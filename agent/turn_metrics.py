"""
How a turn's latency is measured, extracted and put on the wire.

These are the pure functions behind the per-turn telemetry: they convert
durations, pull stage numbers out of LiveKit's metric objects, and shape the two
payloads a finished turn produces (the backend request and the structured
CloudWatch line). Kept free of I/O and of session state so the numbers can be
tested directly; `turn_tracker.py` decides *when* to call them.
"""

from typing import Any, Dict, Optional


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

    # Handle interim transcript events where is_final is False
    if hasattr(item_or_event, "is_final") and not getattr(item_or_event, "is_final"):
        return None

    # Direct string input
    if isinstance(item_or_event, str) and item_or_event.strip():
        return item_or_event.strip()

    # Handle event wrapper (e.g. ConversationItemAddedEvent with .item)
    item = getattr(item_or_event, "item", item_or_event)

    if hasattr(item, "is_final") and not getattr(item, "is_final"):
        return None

    # Must be a user role if role attribute is present
    role = getattr(item, "role", None)
    if role is not None and role != "user":
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
            elif p is not None and str(p).strip():
                parts.append(str(p).strip())
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
    Learner transcripts are excluded to prevent PII leakage in container logs (HIGH-02).
    """
    return {
        "event": "voice_turn_latency",
        "session_id": session_id,
        "turn_id": turn_id,
        "stt_final_ms": to_ms(stt_final_sec),
        "llm_first_token_ms": to_ms(llm_first_token_sec),
        "tts_first_chunk_ms": to_ms(tts_first_chunk_sec),
        "total_turnaround_ms": to_ms(total_turnaround_sec),
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


__all__ = [
    "build_cloudwatch_turn_log",
    "build_turn_payload",
    "compute_total_turnaround",
    "extract_metric_info",
    "extract_user_transcript",
    "to_ms",
]
