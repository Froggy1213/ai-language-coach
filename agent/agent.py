import asyncio
from collections import deque
import json
import logging
import os
import sys
from typing import Any, Dict, NamedTuple, Optional

try:
    import aiohttp
except ImportError:
    aiohttp = None  # type: ignore

try:
    from dotenv import load_dotenv
    # Load local environment if available
    load_dotenv()
except ImportError:
    pass

try:
    from sentry_integration import (
        init_sentry,
        set_session_tags,
        capture_exception,
        scrub_event,
        is_sentry_enabled,
    )
except ImportError:
    try:
        from .sentry_integration import (  # type: ignore
            init_sentry,
            set_session_tags,
            capture_exception,
            scrub_event,
            is_sentry_enabled,
        )
    except ImportError:
        def init_sentry(*args, **kwargs): return False
        def set_session_tags(*args, **kwargs): pass
        def capture_exception(*args, **kwargs): return None
        def scrub_event(ev, *args, **kwargs): return ev
        def is_sentry_enabled(): return False

# Initialise Sentry as early as possible so startup errors reach Sentry.
# If SENTRY_DSN is unset, this is an immediate no-op.
init_sentry()


try:
    from livekit.agents import (
        AutoSubscribe,
        JobContext,
        JobProcess,
        WorkerOptions,
        cli,
        metrics,
    )
    HAS_LIVEKIT = True
    HAS_METRICS = True
except ImportError:
    AutoSubscribe = None  # type: ignore
    JobContext = Any  # type: ignore
    JobProcess = Any  # type: ignore
    WorkerOptions = None  # type: ignore
    cli = None  # type: ignore
    metrics = None  # type: ignore
    HAS_LIVEKIT = False
    HAS_METRICS = False

# Plugins
try:
    from livekit.plugins import silero, deepgram
    HAS_PLUGINS = True
except ImportError:
    silero = None  # type: ignore
    deepgram = None  # type: ignore
    HAS_PLUGINS = False


try:
    from livekit.plugins import openai
    HAS_OPENAI = True
except ImportError:
    openai = None  # type: ignore
    HAS_OPENAI = False

try:
    from livekit.plugins import cartesia
    HAS_CARTESIA = True
except ImportError:
    HAS_CARTESIA = False

# Detect LiveKit Agents version / API (AgentSession v1 vs VoicePipelineAgent v0)
try:
    from livekit.agents import AgentSession, Agent
    USE_AGENT_SESSION = True
except ImportError:
    try:
        from livekit.agents.pipeline import VoicePipelineAgent
        USE_AGENT_SESSION = False
    except ImportError:
        AgentSession = None  # type: ignore
        Agent = None  # type: ignore
        VoicePipelineAgent = None  # type: ignore
        USE_AGENT_SESSION = False


logger = logging.getLogger("ai-language-coach-agent")
logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(name)s: %(message)s")


def _env_int(name: str, default: int) -> int:
    """Read an integer setting, keeping the default and saying so on a typo."""
    raw = os.getenv(name, "").strip()
    if raw == "":
        return default
    try:
        return int(raw)
    except ValueError:
        logger.warning("Ignoring %s=%r: not an integer", name, raw)
        return default


def _env_float(name: str, default: float) -> float:
    """Read a numeric setting, keeping the default and saying so on a typo."""
    raw = os.getenv(name, "").strip()
    if raw == "":
        return default
    try:
        return float(raw)
    except ValueError:
        logger.warning("Ignoring %s=%r: not a number", name, raw)
        return default


def build_worker_options() -> Any:
    """
    The worker pool as the deployment configured it (plan §5, §7).

    livekit-agents 1.8.5 does not read these settings from the environment: its
    ``ServerEnvOption`` only chooses between a development and a production
    default, and this line has no ``max_processes`` field at all. Passing the
    values through here is what makes the capacity the benchmark chose actually
    apply — an environment variable nobody reads is a capacity setting that only
    exists in a Terraform file.
    """
    return WorkerOptions(
        entrypoint_fnc=entrypoint,
        prewarm_fnc=prewarm,
        agent_name=os.getenv("VOICE_AGENT_NAME", "ai-language-coach"),
        num_idle_processes=_env_int("NUM_IDLE_PROCESSES", 4),
        load_threshold=_env_float("LOAD_THRESHOLD", 0.7),
        drain_timeout=_env_float("DRAIN_TIMEOUT_SECONDS", 360.0),
    )


class _InternalResponse(NamedTuple):
    """
    Result of an internal HTTP request to the backend.

    Attributes:
        status: HTTP status code returned by the server.
        data: Parsed JSON dictionary if response body was valid JSON, otherwise None.
        text: Raw response body string.
    """
    status: int
    data: Optional[Dict[str, Any]] = None
    text: str = ""


async def _post_internal(
    path: str,
    payload: Dict[str, Any],
    log_context: Optional[str] = None,
) -> Optional[_InternalResponse]:
    """
    Posts a JSON payload to the internal Laravel backend API.

    Handles environment resolution and validation (BACKEND_INTERNAL_URL,
    VOICE_INTERNAL_SECRET), aiohttp library availability check, header
    construction, URL joining with trailing-slash tolerance, session
    management with a 5-second total timeout, and response body / JSON parsing.

    Args:
        path: Relative or absolute route path (e.g. '/api/internal/sessions/1/fail').
        payload: Dict to serialize as JSON.
        log_context: Optional context description for validation warnings
            (e.g. 'failure' or 'turn metrics').

    Returns:
        _InternalResponse(status, data, text) if the HTTP request completed,
        or None if prerequisite checks failed (missing secret or missing aiohttp).

    Raises:
        Exception: Propagates transport/network errors (e.g. connection refused,
            client timeouts) so callers can apply their own retry or error policy.
    """
    backend_url = os.getenv("BACKEND_INTERNAL_URL", "http://host.docker.internal:8000")
    secret = os.getenv("VOICE_INTERNAL_SECRET", "")

    log_suffix = f" {log_context}" if log_context else ""
    if not secret:
        logger.warning("VOICE_INTERNAL_SECRET is unset; cannot report%s to backend", log_suffix)
        return None

    if aiohttp is None:
        logger.warning("aiohttp is not installed; cannot report%s to backend", log_suffix)
        return None

    clean_base = (backend_url or "http://host.docker.internal:8000").rstrip("/")
    clean_path = path if path.startswith("/") else f"/{path}"
    endpoint = f"{clean_base}{clean_path}"

    headers = {
        "X-Internal-Secret": secret,
        "Content-Type": "application/json",
        "Accept": "application/json",
    }

    async with aiohttp.ClientSession() as session:
        async with session.post(
            endpoint,
            json=payload,
            headers=headers,
            timeout=aiohttp.ClientTimeout(total=5),
        ) as response:
            status = response.status
            text = ""
            data = None
            try:
                raw_text = await response.text()
                text = raw_text if isinstance(raw_text, str) else str(raw_text)
            except Exception:
                pass

            if hasattr(response, "json") and callable(response.json):
                try:
                    raw_json = await response.json()
                    if isinstance(raw_json, dict):
                        data = raw_json
                except Exception:
                    pass

            if data is None and text:
                try:
                    parsed = json.loads(text)
                    if isinstance(parsed, dict):
                        data = parsed
                except Exception:
                    pass

            return _InternalResponse(status=status, data=data, text=text)


async def report_session_failure(session_id: Optional[int], reason: str) -> None:
    """
    Reports fatal session errors to Laravel backend (POST /api/internal/sessions/{id}/fail).
    Guarded by VOICE_INTERNAL_SECRET.
    Allowed reasons: ['stt_failed', 'tts_failed', 'llm_failed', 'agent_error'].
    """
    if not session_id:
        return

    valid_reasons = {"stt_failed", "tts_failed", "llm_failed", "agent_error"}
    payload_reason = reason if reason in valid_reasons else "agent_error"
    path = f"/api/internal/sessions/{session_id}/fail"
    payload = {"reason": payload_reason}

    max_retries = 1
    for attempt in range(max_retries + 1):
        try:
            response = await _post_internal(path, payload, log_context="failure")
            if response is None:
                return

            status = response.status
            if 200 <= status < 300:
                logger.info(
                    "Failure reported to backend for session %s (reason: %s): HTTP %s",
                    session_id,
                    payload_reason,
                    status,
                )
                return
            elif status == 404:
                logger.info(
                    "Session %s already terminal on backend (HTTP 404)",
                    session_id,
                )
                return
            elif status >= 500:
                if attempt < max_retries:
                    logger.warning(
                        "Backend error reporting failure for session %s: HTTP %s; retrying...",
                        session_id,
                        status,
                    )
                    await asyncio.sleep(0.5)
                    continue
                logger.error(
                    "Backend error reporting failure for session %s: HTTP %s",
                    session_id,
                    status,
                )
                return
            else:
                logger.warning(
                    "Backend rejected failure report for session %s: HTTP %s",
                    session_id,
                    status,
                )
                return
        except Exception as exc:
            if attempt < max_retries:
                logger.warning(
                    "Network error notifying backend about session %s failure: %s; retrying...",
                    session_id,
                    exc,
                )
                await asyncio.sleep(0.5)
            logger.error("Failed to notify backend about session %s failure: %s", session_id, exc)
            capture_exception(
                exc,
                tags={
                    "voice_session_id": str(session_id) if session_id is not None else "unknown",
                    "failure_path": "backend_report",
                },
            )


def to_ms(val_in_seconds: Optional[float]) -> Optional[float]:
    """
    Converts a duration in seconds to milliseconds, rounded to 1 decimal place.
    Returns None if input is None, negative, or invalid.
    """
    if val_in_seconds is None:
        return None
    try:
        val = float(val_in_seconds)
        if val < 0:
            return None
        return round(val * 1000.0, 1)
    except (ValueError, TypeError):
        return None


def extract_user_transcript(item_or_event: Any) -> Optional[str]:
    """
    Defensively extracts text transcript from a conversation item or ConversationItemAddedEvent.
    Handles LiveKit ChatMessage objects (text_content, raw_text_content, content list),
    event wrappers (.item), and test fakes.
    Returns None if role != 'user' or if no non-empty text content is found.
    """
    if item_or_event is None:
        return None

    # Handle event wrapper (e.g. ConversationItemAddedEvent with .item)
    item = getattr(item_or_event, "item", item_or_event)

    # Must be a user role
    role = getattr(item, "role", None)
    if role != "user":
        return None

    # 1. Try text_content property or method (LiveKit ChatMessage has @property text_content)
    text_content = getattr(item, "text_content", None)
    if callable(text_content):
        try:
            text_content = text_content()
        except Exception:
            text_content = None
    if isinstance(text_content, str) and text_content.strip():
        return text_content.strip()

    # 2. Try raw_text_content property or method
    raw_content = getattr(item, "raw_text_content", None)
    if callable(raw_content):
        try:
            raw_content = raw_content()
        except Exception:
            raw_content = None
    if isinstance(raw_content, str) and raw_content.strip():
        return raw_content.strip()

    # 3. Try content attribute (can be str or list of parts in ChatMessage)
    content = getattr(item, "content", None)
    if isinstance(content, str) and content.strip():
        return content.strip()
    elif isinstance(content, (list, tuple)):
        parts = []
        for p in content:
            if isinstance(p, str) and p.strip():
                parts.append(p.strip())
            elif hasattr(p, "text") and isinstance(p.text, str) and p.text.strip():
                parts.append(p.text.strip())
        if parts:
            return " ".join(parts)

    # 4. Fallback to transcript attribute (e.g. UserInputTranscribedEvent or test fakes)
    tx = getattr(item, "transcript", None)
    if isinstance(tx, str) and tx.strip():
        return tx.strip()

    return None


def compute_total_turnaround(
    stt_sec: Optional[float],
    llm_sec: Optional[float],
    tts_sec: Optional[float],
) -> Optional[float]:
    """
    Computes total turnaround in seconds as sum of available turn stages:
    stt_final + llm_first_token + tts_first_chunk.
    Returns None if no stage values are available.

    LATENCY ARCHITECTURE DECISION (Finding 3):
    LiveKit Agents 1.8.4 exposes `ChatMessage.metrics` containing `e2e_latency`
    (measured from the user stopping speaking to the agent starting speech).
    However, we derive total turnaround from the progressive metric events
    (EOUMetrics, LLMMetrics, TTSMetrics) rather than `ChatMessage.metrics` because:
    1. In LiveKit 1.8.4 (`agent_activity.py`), `ChatMessage.metrics['e2e_latency']`
       is only attached to the assistant's `ChatMessage` when the speech has completely
       finished playing out (`wait_for_playout()`), which occurs multiple seconds later
       at the end of the entire agent utterance.
    2. Plan §5 and README decision 32 mandate reporting turn latency immediately upon
       the arrival of the first TTS chunk (or after a 2.0s fallback timer), capturing
       the moment the learner perceives the agent responding.
    3. `ChatMessage.metrics` is unavailable in fallback timeout scenarios (e.g. stalled TTS)
       or when playback is interrupted.
    4. By anchoring `stt_final` to the end-of-turn window (taking `max(end_of_utterance_delay,
       transcription_delay)`), we account for the turn-detector silence window (typically
       300-500ms) that the learner experiences before LLM generation begins. The sum of
       (stt_final + llm_first_token + tts_first_chunk) accurately models the learner's
       perceived turnaround latency in real time.
    """
    stages = [s for s in (stt_sec, llm_sec, tts_sec) if s is not None and s >= 0]
    if not stages:
        return None
    return sum(stages)


def build_turn_payload(
    turn_id: str,
    stt_final_sec: Optional[float],
    llm_first_token_sec: Optional[float],
    tts_first_chunk_sec: Optional[float],
    total_turnaround_sec: Optional[float],
    transcript: Optional[str] = None,
) -> Dict[str, Any]:
    """
    Constructs the JSON payload for POST /api/internal/sessions/{id}/turns.
    All latency metrics are in milliseconds (ms).
    """
    return {
        "turn_id": turn_id,
        "transcript": transcript,
        "stt_final": to_ms(stt_final_sec),
        "llm_first_token": to_ms(llm_first_token_sec),
        "tts_first_chunk": to_ms(tts_first_chunk_sec),
        "total_turnaround": to_ms(total_turnaround_sec),
    }


def build_cloudwatch_turn_log(
    session_id: Optional[int],
    turn_id: str,
    stt_final_sec: Optional[float],
    llm_first_token_sec: Optional[float],
    tts_first_chunk_sec: Optional[float],
    total_turnaround_sec: Optional[float],
    transcript: Optional[str] = None,
) -> Dict[str, Any]:
    """
    Constructs structured JSON dict for container stdout / CloudWatch log ingestion.
    Metrics are in milliseconds (ms).
    """
    return {
        "event": "voice_turn_latency",
        "session_id": session_id,
        "turn_id": turn_id,
        "stt_final_ms": to_ms(stt_final_sec),
        "llm_first_token_ms": to_ms(llm_first_token_sec),
        "tts_first_chunk_ms": to_ms(tts_first_chunk_sec),
        "total_turnaround_ms": to_ms(total_turnaround_sec),
        "transcript": transcript,
    }


def extract_metric_info(m: Any) -> Dict[str, Any]:
    """
    Defensively extracts latency numbers and identifiers from LiveKit metric objects
    (STTMetrics, LLMMetrics, TTSMetrics, EOUMetrics).
    All extracted timing values are in seconds.
    """
    metric_type = str(getattr(m, "type", "") or type(m).__name__).lower()
    speech_id = getattr(m, "speech_id", None)

    info: Dict[str, Any] = {
        "metric_type": metric_type,
        "speech_id": speech_id,
        "stt_final_sec": None,
        "llm_first_token_sec": None,
        "tts_first_chunk_sec": None,
    }

    # EOU metrics / STT metrics
    # In livekit-agents 1.8.4, EOUMetrics defaults transcription_delay to 0.0 (not None).
    # Checking `delay is None or delay < 0` previously prevented the fallback to
    # end_of_utterance_delay when transcription_delay was 0.0 (Finding 2).
    # Furthermore, turn detection requires the learner silence window (end_of_utterance_delay,
    # typically 300-500ms) before committing the turn to LLM.
    # We anchor on the end-of-turn window: taking max(end_of_utterance_delay, transcription_delay)
    # accurately captures the actual delay from the end of user speech until LLM generation starts (Finding 3).
    if (
        "eou" in metric_type
        or hasattr(m, "end_of_utterance_delay")
        or hasattr(m, "transcription_delay")
        or hasattr(m, "end_of_turn_delay")
    ):
        eou = getattr(m, "end_of_utterance_delay", None)
        if eou is None:
            eou = getattr(m, "end_of_turn_delay", None)
        tx = getattr(m, "transcription_delay", None)

        delays = []
        if eou is not None:
            try:
                eou_f = float(eou)
                if eou_f >= 0:
                    delays.append(eou_f)
            except (ValueError, TypeError):
                pass
        if tx is not None:
            try:
                tx_f = float(tx)
                if tx_f > 0:
                    delays.append(tx_f)
                elif tx_f == 0.0 and not delays:
                    delays.append(0.0)
            except (ValueError, TypeError):
                pass

        if delays:
            info["stt_final_sec"] = max(delays)


    # LLM metrics: ttft (time to first token)
    if "llm" in metric_type or hasattr(m, "ttft") or type(m).__name__ == "LLMMetrics":
        ttft = getattr(m, "ttft", None)
        if ttft is not None and ttft >= 0:
            info["llm_first_token_sec"] = float(ttft)

    # TTS metrics: ttfb (time to first byte)
    if "tts" in metric_type or hasattr(m, "ttfb") or type(m).__name__ == "TTSMetrics":
        ttfb = getattr(m, "ttfb", None)
        if ttfb is not None and ttfb >= 0:
            info["tts_first_chunk_sec"] = float(ttfb)

    return info


async def report_session_turn(session_id: Optional[int], payload: Dict[str, Any]) -> None:
    """
    Reports per-turn latency and transcript to Laravel backend (POST /api/internal/sessions/{id}/turns).
    Guarded by VOICE_INTERNAL_SECRET.
    Never raises exceptions: failures are logged as warnings and do not interrupt conversation.
    """
    if not session_id:
        return

    path = f"/api/internal/sessions/{session_id}/turns"

    try:
        response = await _post_internal(path, payload, log_context="turn metrics")
        if response is None:
            return

        if response.status == 200:
            logger.info(
                "Turn %s latency reported to backend for session %s: HTTP %s",
                payload.get("turn_id"),
                session_id,
                response.status,
            )
        else:
            body = response.text
            logger.warning(
                "Backend rejected turn %s for session %s: HTTP %s: %s",
                payload.get("turn_id"),
                session_id,
                response.status,
                body,
            )
    except Exception as exc:
        logger.warning(
            "Failed to report turn %s for session %s to backend: %s",
            payload.get("turn_id"),
            session_id,
            exc,
        )


class TurnMetricsTracker:
    """
    Aggregates per-turn latency metrics across LiveKit AgentSession events for a single session.
    Correlates stages using speech_id, flushes to backend and CloudWatch structured logging.
    """

    def __init__(self, session_id: Optional[int], flush_delay: float = 2.0):
        self.session_id = session_id
        self.flush_delay = flush_delay
        self.pending_turns: Dict[str, Dict[str, Any]] = {}
        self.last_user_transcript: Optional[str] = None
        self._flush_tasks: Dict[str, asyncio.Task] = {}
        self._background_tasks: set[asyncio.Task] = set()
        # Keep a bounded set of completed turn IDs to ignore duplicate/late TTS metrics
        # across multi-sentence replies and prevent memory leaks.
        self._completed_turn_ids: set = set()
        self._completed_turn_order: deque = deque()

    def _create_background_task(self, coro) -> asyncio.Task:
        task = asyncio.create_task(coro)
        self._background_tasks.add(task)
        task.add_done_callback(self._background_tasks.discard)

        def _log_task_exception(t: asyncio.Task) -> None:
            if not t.cancelled():
                exc = t.exception()
                if exc:
                    logger.error("Background task failed: %s", exc, exc_info=exc)

        task.add_done_callback(_log_task_exception)
        return task

    def on_user_speech(self, text: Optional[str]) -> None:
        if not text:
            return
        cleaned = text.strip()
        if not cleaned:
            return
        self.last_user_transcript = cleaned
        # If there are active pending turns without transcript, attach the latest transcript
        for turn in self.pending_turns.values():
            if turn.get("transcript") is None:
                turn["transcript"] = cleaned

    def _record_completed_turn_id(self, speech_id: str, max_size: int = 500) -> None:
        if speech_id not in self._completed_turn_ids:
            self._completed_turn_ids.add(speech_id)
            self._completed_turn_order.append(speech_id)
            if len(self._completed_turn_order) > max_size:
                oldest = self._completed_turn_order.popleft()
                self._completed_turn_ids.discard(oldest)

    def handle_metric(self, m: Any) -> None:
        info = extract_metric_info(m)
        speech_id = info["speech_id"]
        if not speech_id:
            return

        # Finding 5 & 6: If this turn has already completed and purged, ignore subsequent
        # metrics (e.g. subsequent TTSMetrics for multi-sentence replies or late metrics).
        if speech_id in self._completed_turn_ids:
            return

        is_new_turn = speech_id not in self.pending_turns

        # Finding 4: The opening session.say(greeting) produces TTSMetrics with no preceding
        # learner utterance (no EOUMetrics, LLMMetrics, or user transcript).
        # A turn is the learner's conversational turn. We ignore standalone agent speech
        # that no learner utterance preceded.
        if is_new_turn:
            has_eou = info["stt_final_sec"] is not None
            has_llm = info["llm_first_token_sec"] is not None
            has_transcript = self.last_user_transcript is not None

            # If purely TTS with no preceding EOU, LLM, or user transcript, drop it.
            if not has_eou and not has_llm and not has_transcript:
                logger.debug(
                    "Ignoring standalone agent speech (no preceding learner utterance): speech_id=%s",
                    speech_id,
                )
                return

            self.pending_turns[speech_id] = {
                "turn_id": speech_id,
                "stt_final_sec": None,
                "llm_first_token_sec": None,
                "tts_first_chunk_sec": None,
                "transcript": self.last_user_transcript,
            }

        turn = self.pending_turns[speech_id]

        if info["stt_final_sec"] is not None:
            turn["stt_final_sec"] = info["stt_final_sec"]
        if info["llm_first_token_sec"] is not None:
            turn["llm_first_token_sec"] = info["llm_first_token_sec"]

        # Ensure transcript is populated if it arrived after turn creation
        if turn.get("transcript") is None and self.last_user_transcript is not None:
            turn["transcript"] = self.last_user_transcript

        # Finding 5: First-TTFB-wins across multi-sentence replies.
        # A multi-sentence reply emits several TTSMetrics with the same speech_id.
        # The first chunk of the turn's first sentence is what the field means.
        # Do not overwrite tts_first_chunk_sec and do not re-dispatch.
        if info["tts_first_chunk_sec"] is not None:
            if turn["tts_first_chunk_sec"] is None:
                turn["tts_first_chunk_sec"] = info["tts_first_chunk_sec"]
                # When TTS first chunk arrives, the turnaround cycle is complete.
                self._cancel_delayed_flush(speech_id)
                self._create_background_task(self._dispatch_turn(speech_id))
            else:
                logger.debug(
                    "Ignoring subsequent TTS chunk for speech_id=%s (first TTFB wins)",
                    speech_id,
                )
        elif turn["tts_first_chunk_sec"] is None and speech_id not in self._flush_tasks:
            self._schedule_delayed_flush(speech_id, delay=self.flush_delay)

    def _schedule_delayed_flush(self, speech_id: str, delay: Optional[float] = None) -> None:
        if speech_id in self._flush_tasks:
            return

        flush_delay = self.flush_delay if delay is None else delay

        async def _flush_after_delay() -> None:
            try:
                await asyncio.sleep(flush_delay)
                await self._dispatch_turn(speech_id)
            except asyncio.CancelledError:
                pass

        task = self._create_background_task(_flush_after_delay())
        self._flush_tasks[speech_id] = task

    def _cancel_delayed_flush(self, speech_id: str) -> None:
        task = self._flush_tasks.pop(speech_id, None)
        if task and not task.done() and task is not asyncio.current_task():
            task.cancel()

    async def _dispatch_turn(self, speech_id: str) -> None:
        self._cancel_delayed_flush(speech_id)

        # Finding 6: Purge completed turn from pending_turns to prevent unbounded memory growth.
        turn = self.pending_turns.pop(speech_id, None)
        if not turn:
            return

        self._record_completed_turn_id(speech_id)

        # If this turn consumed last_user_transcript, clear it so subsequent
        # unprompted agent speech cannot reuse stale transcript data.
        if self.last_user_transcript == turn.get("transcript"):
            self.last_user_transcript = None

        total_sec = compute_total_turnaround(
            turn["stt_final_sec"],
            turn["llm_first_token_sec"],
            turn["tts_first_chunk_sec"],
        )

        payload = build_turn_payload(
            turn_id=speech_id,
            stt_final_sec=turn["stt_final_sec"],
            llm_first_token_sec=turn["llm_first_token_sec"],
            tts_first_chunk_sec=turn["tts_first_chunk_sec"],
            total_turnaround_sec=total_sec,
            transcript=turn.get("transcript"),
        )

        cloudwatch_log = build_cloudwatch_turn_log(
            session_id=self.session_id,
            turn_id=speech_id,
            stt_final_sec=turn["stt_final_sec"],
            llm_first_token_sec=turn["llm_first_token_sec"],
            tts_first_chunk_sec=turn["tts_first_chunk_sec"],
            total_turnaround_sec=total_sec,
            transcript=turn.get("transcript"),
        )
        logger.info("TURN_LATENCY %s", json.dumps(cloudwatch_log))

        await report_session_turn(self.session_id, payload)



def build_system_prompt(metadata: Dict[str, Any]) -> str:
    target_language = str(metadata.get("target_language") or "en")
    level = str(metadata.get("level") or "A2")
    grammar_point = str(metadata.get("grammar_point") or "General conversation")
    practice_prompt = str(
        metadata.get("practice_prompt") or "Have a friendly conversation to practice language fluency."
    )

    return f"""You are an expert, empathetic AI Language Coach.
You are conducting a 1-on-1 spoken practice session with a language learner.

Session Details:
- Target Language: {target_language}
- Student CEFR Level: {level}
- Target Grammar Point: {grammar_point}
- Practice Goal / Scenario: {practice_prompt}

Guidelines:
1. Speak exclusively or predominantly in the target language ({target_language}), tailored to level {level}.
2. Keep your conversational turns concise (1 to 3 short sentences maximum). You are speaking aloud over voice.
3. Be conversational and interactive: ask open-ended questions so the student does most of the talking.
4. When the student makes an error related to '{grammar_point}', provide a gentle rephrase or brief correction naturally in conversation.
5. Keep the atmosphere supportive, friendly, and motivating.
"""


def create_stt(metadata: Dict[str, Any]):
    target_lang = str(metadata.get("target_language") or "en").lower()
    lang = "en" if target_lang.startswith("en") else target_lang
    return deepgram.STT(model="nova-2", language=lang)


def create_llm():
    """
    Constructs the dialogue LLM instance for LiveKit AgentSession.

    The dialogue model is fully environment-driven:
    - LLM_PROVIDER: Candidate provider identifier (e.g. 'deepseek', 'openai', 'groq',
      or any OpenAI-compatible provider). If unset, auto-detects based on configured
      API keys, preserving the baseline default (DeepSeek if DEEPSEEK_API_KEY is present,
      else OpenAI if OPENAI_API_KEY is present).
    - LLM_MODEL: Model identifier (e.g. 'deepseek-chat', 'gpt-4o-mini', 'llama-3.3-70b-versatile').
      Defaults per provider.
    - LLM_BASE_URL: OpenAI-compatible endpoint URL (e.g. 'https://api.deepseek.com',
      'https://api.groq.com/openai/v1', or provider default).
    - API keys: Resolved from universal override LLM_API_KEY or provider-specific keys
      (DEEPSEEK_API_KEY, OPENAI_API_KEY, GROQ_API_KEY, or {PROVIDER}_API_KEY).

    Why OpenAI-compatible interface?
    LiveKit Agents' `livekit-plugins-openai` (`openai.LLM`) natively implements the
    streaming LLM protocol used by AgentSession. All candidate providers evaluated
    in the dialogue latency benchmark (DeepSeek, OpenAI, Groq, Together, OpenRouter)
    provide OpenAI-compatible chat completion endpoints. By driving provider, model,
    and base_url from environment variables, applying the benchmark's conclusion
    requires only updating environment configuration (in docker-compose.yml or .env)
    without touching code or rebuilding containers.
    """
    if not HAS_OPENAI:
        raise RuntimeError("livekit-plugins-openai is required for LLM integration")

    explicit_provider = os.getenv("LLM_PROVIDER", "").strip().lower()
    model_name = (os.getenv("LLM_MODEL") or "").strip() or None
    base_url = (os.getenv("LLM_BASE_URL") or "").strip() or None
    llm_key = (os.getenv("LLM_API_KEY") or "").strip() or None

    deepseek_key = (os.getenv("DEEPSEEK_API_KEY") or "").strip() or None
    openai_key = (os.getenv("OPENAI_API_KEY") or "").strip() or None
    groq_key = (os.getenv("GROQ_API_KEY") or "").strip() or None

    # If provider is explicitly specified, resolve provider-specific configuration
    if explicit_provider:
        if explicit_provider == "deepseek":
            api_key = llm_key or deepseek_key
            if not api_key:
                raise ValueError("DEEPSEEK_API_KEY (or LLM_API_KEY) is required when LLM_PROVIDER is 'deepseek'")
            return openai.LLM(
                model=model_name or "deepseek-chat",
                base_url=base_url or "https://api.deepseek.com",
                api_key=api_key,
            )
        elif explicit_provider == "openai":
            api_key = llm_key or openai_key
            if not api_key:
                raise ValueError("OPENAI_API_KEY (or LLM_API_KEY) is required when LLM_PROVIDER is 'openai'")
            return openai.LLM(
                model=model_name or "gpt-4o-mini",
                base_url=base_url,
                api_key=api_key,
            )
        elif explicit_provider == "groq":
            api_key = llm_key or groq_key
            if not api_key:
                raise ValueError("GROQ_API_KEY (or LLM_API_KEY) is required when LLM_PROVIDER is 'groq'")
            return openai.LLM(
                model=model_name or "llama-3.3-70b-versatile",
                base_url=base_url or "https://api.groq.com/openai/v1",
                api_key=api_key,
            )
        else:
            # Custom / generic OpenAI-compatible provider
            provider_key_var = f"{explicit_provider.upper()}_API_KEY"
            api_key = llm_key or os.getenv(provider_key_var)
            if not api_key:
                raise ValueError(
                    f"{provider_key_var} (or LLM_API_KEY) is required when LLM_PROVIDER is '{explicit_provider}'"
                )
            return openai.LLM(
                model=model_name or "deepseek-chat",
                base_url=base_url,
                api_key=api_key,
            )

    # Provider not explicitly specified: preserve original auto-detection behavior
    if deepseek_key and (not openai_key or base_url or "deepseek" in (model_name or "").lower()):
        return openai.LLM(
            model=model_name or "deepseek-chat",
            base_url=base_url or "https://api.deepseek.com",
            api_key=llm_key or deepseek_key,
        )

    if openai_key:
        return openai.LLM(
            model=model_name or "gpt-4o-mini",
            base_url=base_url,
            api_key=llm_key or openai_key,
        )

    if groq_key:
        return openai.LLM(
            model=model_name or "llama-3.3-70b-versatile",
            base_url=base_url or "https://api.groq.com/openai/v1",
            api_key=llm_key or groq_key,
        )

    if llm_key:
        return openai.LLM(
            model=model_name or "deepseek-chat",
            base_url=base_url or "https://api.deepseek.com",
            api_key=llm_key,
        )

    raise ValueError("Neither DEEPSEEK_API_KEY nor OPENAI_API_KEY is configured")


def create_tts():
    cartesia_key = os.getenv("CARTESIA_API_KEY")
    deepgram_key = os.getenv("DEEPGRAM_API_KEY")
    openai_key = os.getenv("OPENAI_API_KEY")

    if cartesia_key and HAS_CARTESIA:
        return cartesia.TTS(
            # "sonic-3" is the plugin's current default; "sonic-english" was
            # retired upstream and only survived as an alias, so naming it here
            # would pin the voice to a model the API is free to drop.
            model=os.getenv("CARTESIA_MODEL", "sonic-3"),
            voice=os.getenv("CARTESIA_VOICE", "248be419-c632-4f23-adf1-5324ed7dbf1d"),
            api_key=cartesia_key,
        )

    if deepgram_key:
        return deepgram.TTS(model=os.getenv("DEEPGRAM_TTS_MODEL", "aura-asteria-en"))

    if openai_key and HAS_OPENAI:
        return openai.TTS(model=os.getenv("OPENAI_TTS_MODEL", "tts-1"), voice="alloy")

    raise ValueError("No valid TTS provider configured (set CARTESIA_API_KEY, DEEPGRAM_API_KEY, or OPENAI_API_KEY)")


def prewarm(proc: JobProcess):
    init_sentry()
    proc.userdata["vad"] = silero.VAD.load()


async def entrypoint(ctx: JobContext):
    room_name = getattr(getattr(ctx, "room", None), "name", None) or "unknown"
    logger.info("Starting voice agent job for room: %s", room_name)

    # 1. Parse metadata passed from Laravel's StartVoiceSession (App\\Voice\\StartVoiceSession)
    metadata: Dict[str, Any] = {}
    if ctx.job.metadata:
        try:
            parsed = json.loads(ctx.job.metadata)
            if isinstance(parsed, dict):
                metadata = parsed
            else:
                logger.warning("Decoded job metadata is not a dict: %r", parsed)
                metadata = {}
            logger.info("Received session metadata: %s", metadata)
        except json.JSONDecodeError as err:
            logger.warning("Failed to decode job metadata JSON: %s", err)
            metadata = {}

    raw_session_id = metadata.get("session_id")
    session_id: Optional[int] = None
    if raw_session_id is not None:
        try:
            session_id = int(raw_session_id)
        except (ValueError, TypeError):
            session_id = None

    grammar_point = str(metadata.get("grammar_point") or "General practice")
    practice_prompt = str(metadata.get("practice_prompt") or "Let's practice speaking.")
    target_language = str(metadata.get("target_language") or "en")

    # Tag every captured event with enough context to find the session without
    # shipping its content (plan §1, §7).
    set_session_tags(
        session_id=session_id,
        room_name=room_name,
        grammar_point=grammar_point,
        target_language=target_language,
    )

    session_tags = {
        "voice_session_id": str(session_id) if session_id is not None else "unknown",
        "room_name": str(room_name),
        "grammar_point": str(grammar_point),
        "target_language": str(target_language),
    }

    try:
        # 2. Connect worker to LiveKit room
        # This announces participant kind=AGENT to the room, which unblocks StartVoiceSession
        try:
            auto_sub = getattr(AutoSubscribe, "AUDIO_ONLY", None)
            await ctx.connect(auto_subscribe=auto_sub)
            logger.info("Agent successfully connected to room %s as AGENT", room_name)
        except Exception as exc:
            logger.error("Failed to connect to LiveKit room %s: %s", room_name, exc)
            capture_exception(exc, tags=session_tags)
            await report_session_failure(session_id, "agent_error")
            return

        # 3. Instantiate voice pipeline components
        try:
            vad = ctx.proc.userdata.get("vad") if hasattr(ctx, "proc") and hasattr(ctx.proc, "userdata") and ctx.proc.userdata is not None else None
            if vad is None:
                vad = silero.VAD.load()
        except Exception as exc:
            logger.error("Failed to load VAD: %s", exc)
            capture_exception(exc, tags=session_tags)
            await report_session_failure(session_id, "agent_error")
            return

        try:
            stt = create_stt(metadata)
        except Exception as exc:
            logger.error("Failed to initialize STT: %s", exc)
            capture_exception(exc, tags=session_tags)
            await report_session_failure(session_id, "stt_failed")
            return

        try:
            llm_instance = create_llm()
        except Exception as exc:
            logger.error("Failed to initialize LLM: %s", exc)
            capture_exception(exc, tags=session_tags)
            await report_session_failure(session_id, "llm_failed")
            return

        try:
            tts = create_tts()
        except Exception as exc:
            logger.error("Failed to initialize TTS: %s", exc)
            capture_exception(exc, tags=session_tags)
            await report_session_failure(session_id, "tts_failed")
            return

        instructions = build_system_prompt(metadata)

        # 4. Run session
        try:
            if USE_AGENT_SESSION:
                logger.info("Using LiveKit AgentSession interface")
                agent_instance = Agent(instructions=instructions)
                session = AgentSession(
                    vad=vad,
                    stt=stt,
                    llm=llm_instance,
                    tts=tts,
                )

                tracker = TurnMetricsTracker(session_id=session_id)

                if hasattr(session, "on"):
                    # NOTE: livekit-agents 1.8.4 logs a deprecation notice recommending
                    # session_usage_updated for token usage and ChatMessage.metrics for per-turn
                    # latency (Finding 7). We intentionally retain @session.on("metrics_collected") because:
                    # 1) session_usage_updated only tracks token counts/billing, not stage latencies.
                    # 2) ChatMessage.metrics['e2e_latency'] is only attached to assistant ChatMessage
                    #    after full audio playout completes (agent_activity.py wait_for_playout),
                    #    which occurs seconds later and cannot provide telemetry at the first TTS chunk
                    #    (plan §5 / README decision 32), nor during fallback timeouts or interruptions.
                    # 3) metrics_collected continues to be emitted synchronously during the turn
                    #    for EOUMetrics, LLMMetrics, and TTSMetrics with correlated speech_ids.
                    @session.on("metrics_collected")
                    def _on_metrics_collected(ev: Any):
                        try:
                            m = getattr(ev, "metrics", ev)
                            if HAS_METRICS and metrics is not None and hasattr(metrics, "log_metrics"):
                                try:
                                    metrics.log_metrics(m)
                                except Exception:
                                    pass
                            tracker.handle_metric(m)
                        except Exception as exc:
                            logger.warning("Error processing collected metric: %s", exc)

                    @session.on("conversation_item_added")
                    def _on_conversation_item_added(item: Any):
                        try:
                            transcript = extract_user_transcript(item)
                            if transcript:
                                tracker.on_user_speech(transcript)
                        except Exception as exc:
                            logger.debug("Error reading conversation item: %s", exc)

                    @session.on("user_input_transcribed")
                    def _on_user_input_transcribed(ev: Any):
                        try:
                            if getattr(ev, "is_final", False):
                                tx = getattr(ev, "transcript", None)
                                if isinstance(tx, str) and tx.strip():
                                    tracker.on_user_speech(tx.strip())
                        except Exception as exc:
                            logger.debug("Error reading user input transcription: %s", exc)

                    # Kept as legacy/defensive fallback in case older/wrapped pipelines emit it
                    @session.on("user_speech_committed")
                    def _on_user_speech_committed(msg: Any):
                        try:
                            transcript = extract_user_transcript(msg)
                            if transcript:
                                tracker.on_user_speech(transcript)
                            else:
                                content = getattr(msg, "content", None)
                                if isinstance(content, str):
                                    tracker.on_user_speech(content)
                                elif isinstance(content, list):
                                    parts = [str(p) for p in content if p]
                                    if parts:
                                        tracker.on_user_speech(" ".join(parts))
                        except Exception as exc:
                            logger.debug("Error reading committed user speech: %s", exc)

                await session.start(room=ctx.room, agent=agent_instance)

                # Opening prompt
                greeting = f"Hello! Today we are practicing {grammar_point}. {practice_prompt} Whenever you are ready, let's start!"
                await session.say(greeting, allow_interruptions=True)
            else:
                logger.info("Using LiveKit VoicePipelineAgent interface")
                pipeline_agent = VoicePipelineAgent(
                    vad=vad,
                    stt=stt,
                    llm=llm_instance,
                    tts=tts,
                )
                pipeline_agent.start(ctx.room)

                greeting = f"Hello! Today we are practicing {grammar_point}. {practice_prompt} Whenever you are ready, let's start!"
                await pipeline_agent.say(greeting, allow_interruptions=True)

        except Exception as exc:
            logger.error("Runtime error in voice agent session: %s", exc, exc_info=True)
            capture_exception(exc, tags=session_tags)
            await report_session_failure(session_id, "agent_error")

    except Exception as unhandled_exc:
        # Capture any unhandled exception inside a job before the worker disconnects
        logger.error("Unhandled exception in voice agent job: %s", unhandled_exc, exc_info=True)
        capture_exception(unhandled_exc, tags=session_tags)
        await report_session_failure(session_id, "agent_error")


if __name__ == "__main__":
    init_sentry()
    agent_name = os.getenv("VOICE_AGENT_NAME", "ai-language-coach")
    logger.info("Registering LiveKit worker with agent_name='%s'", agent_name)

    cli.run_app(build_worker_options())
