"""
One voice session, from job metadata to the last turn.

This is the orchestration layer: it decodes the metadata Laravel sends with the
dispatch, connects the worker to the room, builds the pipeline through the
provider modules, attaches the turn metrics tracker, owns the session duration
timer and the greeting, and decides what a failure at each step means for the
backend's record of the call. The pieces themselves live next door; what is here
is only the order they run in and the policy around the failures.
"""

import asyncio
import inspect
import json
from typing import Any, Awaitable, Callable, Dict, Optional

if __package__:  # imported as `agent.session`
    from .backend import report_session_failure
    from .dialogue import create_llm
    from .livekit_compat import (
        Agent,
        AgentSession,
        AutoSubscribe,
        HAS_METRICS,
        JobContext,
        JobProcess,
        USE_AGENT_SESSION,
        VoicePipelineAgent,
        metrics,
        silero,
    )
    from .observability import capture_exception, init_sentry, logger, set_session_tags
    from .prompts import build_greeting, build_system_prompt
    from .settings import _env_float
    from .speech import create_stt, create_tts
    from .turn_handling import build_turn_handling
    from .turn_metrics import extract_user_transcript
    from .turn_tracker import TurnMetricsTracker
else:  # run as a flat script: `python agent.py`, the container layout, the tests
    from backend import report_session_failure
    from dialogue import create_llm
    from livekit_compat import (
        Agent,
        AgentSession,
        AutoSubscribe,
        HAS_METRICS,
        JobContext,
        JobProcess,
        USE_AGENT_SESSION,
        VoicePipelineAgent,
        metrics,
        silero,
    )
    from observability import capture_exception, init_sentry, logger, set_session_tags
    from prompts import build_greeting, build_system_prompt
    from settings import _env_float
    from speech import create_stt, create_tts
    from turn_handling import build_turn_handling
    from turn_metrics import extract_user_transcript
    from turn_tracker import TurnMetricsTracker


# Maximum voice session duration in seconds (HIGH-04). Defaults to 15 minutes (900 seconds).
VOICE_MAX_SESSION_SECONDS = _env_float("VOICE_MAX_SESSION_SECONDS", 900.0)

#: Returned by `_initialize` when a component's factory raised. The job has
#: already been reported by then, and the caller must stop without an exception
#: carrying control flow through the session body.
_FAILED = object()


def _load_vad(ctx) -> Any:
    """
    The VAD `prewarm` already loaded, or a fresh one.

    Loading Silero per job would pay for the model on every call even though the
    process was prewarmed exactly so it would not have to; the fallback keeps a
    job that runs without a prewarm (the tests, a direct invocation) working.
    """
    vad = None
    if hasattr(ctx, "proc") and hasattr(ctx.proc, "userdata") and ctx.proc.userdata is not None:
        vad = ctx.proc.userdata.get("vad")
    if vad is None:
        vad = silero.VAD.load()
    return vad


def prewarm(proc: JobProcess):
    init_sentry()
    proc.userdata["vad"] = silero.VAD.load()


async def _wait_for_learner(ctx) -> None:
    """
    Wait for the first learner before the greeting is spoken.

    StartVoiceSession returns the browser's token as soon as the AGENT
    participant appears in the room, but that is not the same moment the browser
    is ready to listen: the page still has to call getUserMedia, get microphone
    permission and finish ICE. Speaking the greeting on the next line after
    `session.start()` therefore put it into a room that still had only the agent
    in it, and the learner joined in time to hear the silence after it — the
    first thing they heard was nothing at all. Waiting for the first remote
    participant puts the greeting on the far side of that race.

    `JobContext.wait_for_participant` also connects the room if the job has not
    done so already, and returns immediately when a learner is already in it.

    The wait is bounded by `GREETING_WAIT_SECONDS` (default 60 s) because a job
    that waits forever would hold a worker process (and the backend's
    VOICE_MAX_SESSION_SECONDS cap) for a learner who never arrives. On timeout
    the greeting is still spoken, so a slow join degrades to the previous
    behaviour rather than to a silent session.
    """
    timeout = _env_float("GREETING_WAIT_SECONDS", 60.0)
    waiter = ctx.wait_for_participant()

    # This guard exists only for the failure-path tests in test_turn_metrics.py,
    # which drive entrypoint() with a MagicMock context whose call returns a
    # plain mock: awaiting that would raise where the greeting used to be. A real
    # JobContext always returns a coroutine, so the production path never takes
    # this branch.
    if not inspect.isawaitable(waiter):
        return

    try:
        await asyncio.wait_for(waiter, timeout=timeout)
    except asyncio.TimeoutError:
        logger.warning(
            "No learner joined within %.1fs; speaking the greeting anyway "
            "(GREETING_WAIT_SECONDS)",
            timeout,
        )


def _decode_metadata(ctx) -> Dict[str, Any]:
    """
    The session metadata Laravel attached to the dispatch, or an empty mapping.

    A job with unreadable metadata still takes the call: the learner is in the
    room and silence is the one thing that cannot be repaired. Every field is
    read defensively further down, so `{}` degrades to the defaults.
    """
    if not ctx.job.metadata:
        return {}

    try:
        parsed = json.loads(ctx.job.metadata)
    except json.JSONDecodeError as err:
        logger.warning("Failed to decode job metadata JSON: %s", err)
        return {}

    if not isinstance(parsed, dict):
        logger.warning("Decoded job metadata is not a dict: %r", parsed)
        return {}

    logger.info(
        "Received session metadata: session_id=%s room_name=%s grammar_point=%s target_language=%s level=%s",
        parsed.get("session_id"),
        parsed.get("room_name"),
        parsed.get("grammar_point"),
        parsed.get("target_language"),
        parsed.get("level"),
    )
    return parsed


def _session_id_from(metadata: Dict[str, Any]) -> Optional[int]:
    """The session id as an int, or None when the metadata had no usable one."""
    raw_session_id = metadata.get("session_id")
    if raw_session_id is None:
        return None
    try:
        return int(raw_session_id)
    except (ValueError, TypeError):
        return None


async def _initialize(
    factory: Callable[[], Any],
    reason: str,
    message: str,
    report: Callable[..., Awaitable[None]],
) -> Any:
    """
    Build one pipeline component, reporting a construction failure the job's way.

    Each component names its own failure reason (`stt_failed`, `llm_failed`, …)
    because that value is validated by the backend's failure report and tells an
    operator which provider refused — "agent_error" for all four would turn a
    missing API key into a generic crash.
    """
    try:
        return factory()
    except Exception as exc:
        await report(exc, reason, message, exc)
        return _FAILED


async def _enforce_max_duration(
    ctx,
    max_session_seconds: float,
    session_id: Optional[int],
    room_name: str,
) -> None:
    """
    Close the job when the session reaches its maximum duration (HIGH-04).

    The backend caps a session at VOICE_MAX_SESSION_SECONDS, but the room is only
    finished once both participants leave; without this the worker would hold a
    process for a learner who stopped talking and never hung up.
    """
    try:
        await asyncio.sleep(max_session_seconds)
        logger.warning(
            "Maximum voice session duration reached (%.1fs). Forcing shutdown for session_id=%s, room=%s",
            max_session_seconds,
            session_id,
            room_name,
        )
        if hasattr(ctx, "shutdown") and callable(ctx.shutdown):
            try:
                res = ctx.shutdown(reason="max_session_duration_reached")
                if asyncio.iscoroutine(res):
                    await res
            except TypeError:
                res = ctx.shutdown()
                if asyncio.iscoroutine(res):
                    await res
        elif hasattr(ctx, "room") and ctx.room and hasattr(ctx.room, "disconnect") and callable(ctx.room.disconnect):
            res = ctx.room.disconnect()
            if asyncio.iscoroutine(res):
                await res
    except asyncio.CancelledError:
        pass
    except Exception as dur_exc:
        logger.warning("Error during max session duration shutdown: %s", dur_exc)


def _register_participant_disconnect(ctx, room_name: str) -> None:
    """
    Shut the job down when the last remote participant leaves the room.

    livekit-agents' RoomIO closes the AgentSession on a client-initiated
    disconnect (`close_on_disconnect=True`), but that is all it does: the job and
    the agent participant stay in the room, so the room never becomes empty. The
    backend then only learns the session ended from `room_finished`, after
    VOICE_MAX_SESSION_SECONDS (15 minutes) plus the room's empty timeout — a
    quarter of an hour in which the worker holds a process for nobody and the
    learner's session stays open. Shutting the job down when the last remote
    participant leaves makes the agent leave the room immediately instead.

    Registered before either start path so both branches get it, and safe before
    anyone joins: a disconnect event cannot fire before the first participant has
    connected, and `ctx.shutdown` may be called more than once.
    """
    if not hasattr(ctx.room, "on"):
        return

    @ctx.room.on("participant_disconnected")
    def _on_participant_left(participant):
        if not ctx.room.remote_participants:
            logger.info(
                "Learner %s left room %s; shutting the agent job down",
                getattr(participant, "identity", "unknown"),
                room_name,
            )
            ctx.shutdown(reason="participant_disconnected")


def _subscribe_turn_metrics(session: Any, tracker: TurnMetricsTracker) -> None:
    """
    Feed the tracker from the session's events.

    NOTE: livekit-agents 1.8.4 logs a deprecation notice recommending
    session_usage_updated for token usage and ChatMessage.metrics for per-turn
    latency (Finding 7). We intentionally retain @session.on("metrics_collected") because:
    1) session_usage_updated only tracks token counts/billing, not stage latencies.
    2) ChatMessage.metrics['e2e_latency'] is only attached to assistant ChatMessage
       after full audio playout completes (agent_activity.py wait_for_playout),
       which occurs seconds later and cannot provide telemetry at the first TTS chunk
       (plan §5 / README decision 32), nor during fallback timeouts or interruptions.
    3) metrics_collected continues to be emitted synchronously during the turn
       for EOUMetrics, LLMMetrics, and TTSMetrics with correlated speech_ids.
    """
    if not hasattr(session, "on"):
        return

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

    def _on_user_speech_event(item_or_event: Any):
        try:
            transcript = extract_user_transcript(item_or_event)
            if transcript:
                tracker.on_user_speech(transcript)
        except Exception as exc:
            logger.debug("Error reading user speech event: %s", exc)

    for event_name in (
        "conversation_item_added",
        "user_input_transcribed",
        "user_speech_committed",
    ):
        session.on(event_name)(_on_user_speech_event)


async def entrypoint(ctx: JobContext):
    room_name = getattr(getattr(ctx, "room", None), "name", None) or "unknown"
    logger.info("Starting voice agent job for room: %s", room_name)

    # 1. Parse metadata passed from Laravel's StartVoiceSession (App\Voice\StartVoiceSession)
    metadata = _decode_metadata(ctx)
    session_id = _session_id_from(metadata)
    grammar_point = str(metadata.get("grammar_point") or "General practice")
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

    async def _handle_session_error(
        exc: Exception, reason: str, message: str, *args: Any, exc_info: bool = False
    ) -> None:
        logger.error(message, *args, exc_info=exc_info)
        capture_exception(exc, tags=session_tags)
        await report_session_failure(session_id, reason)

    try:
        # 2. Connect worker to LiveKit room
        # This announces participant kind=AGENT to the room, which unblocks StartVoiceSession
        try:
            auto_sub = getattr(AutoSubscribe, "AUDIO_ONLY", None)
            await ctx.connect(auto_subscribe=auto_sub)
            logger.info("Agent successfully connected to room %s as AGENT", room_name)
        except Exception as exc:
            await _handle_session_error(
                exc, "agent_error", "Failed to connect to LiveKit room %s: %s", room_name, exc
            )
            return

        # 3. Instantiate voice pipeline components
        vad = await _initialize(
            lambda: _load_vad(ctx), "agent_error", "Failed to load VAD: %s", _handle_session_error
        )
        if vad is _FAILED:
            return

        stt = await _initialize(
            lambda: create_stt(metadata), "stt_failed", "Failed to initialize STT: %s", _handle_session_error
        )
        if stt is _FAILED:
            return

        llm_instance = await _initialize(
            create_llm, "llm_failed", "Failed to initialize LLM: %s", _handle_session_error
        )
        if llm_instance is _FAILED:
            return

        tts = await _initialize(
            lambda: create_tts(metadata), "tts_failed", "Failed to initialize TTS: %s", _handle_session_error
        )
        if tts is _FAILED:
            return

        instructions = build_system_prompt(metadata)

        # 4. Run session
        duration_task: Optional[asyncio.Task] = None
        try:
            max_session_seconds = _env_float("VOICE_MAX_SESSION_SECONDS", VOICE_MAX_SESSION_SECONDS)

            _register_participant_disconnect(ctx, room_name)

            if USE_AGENT_SESSION:
                logger.info("Using LiveKit AgentSession interface")
                agent_instance = Agent(instructions=instructions)
                session = AgentSession(
                    vad=vad,
                    stt=stt,
                    llm=llm_instance,
                    tts=tts,
                    turn_handling=build_turn_handling(),
                )

                tracker = TurnMetricsTracker(session_id=session_id)
                _subscribe_turn_metrics(session, tracker)

                async def _cleanup_tracker() -> None:
                    if duration_task and not duration_task.done():
                        duration_task.cancel()
                    # Flush before closing: the room is already gone by the time
                    # shutdown callbacks run, and the turn still in flight is the
                    # last thing the learner said.
                    try:
                        await tracker.drain()
                    except Exception as exc:
                        logger.warning("Could not flush pending turn metrics: %s", exc)
                        tracker.close()

                if hasattr(ctx, "add_shutdown_callback"):
                    ctx.add_shutdown_callback(_cleanup_tracker)

                await session.start(room=ctx.room, agent=agent_instance)
                duration_task = asyncio.create_task(
                    _enforce_max_duration(ctx, max_session_seconds, session_id, room_name)
                )

                await _wait_for_learner(ctx)

                # One text-to-speech call, no model in the loop: see build_greeting.
                await session.say(build_greeting(metadata), allow_interruptions=True)
            else:
                logger.info("Using LiveKit VoicePipelineAgent interface")
                pipeline_agent = VoicePipelineAgent(
                    vad=vad,
                    stt=stt,
                    llm=llm_instance,
                    tts=tts,
                )

                async def _cleanup_pipeline() -> None:
                    if duration_task and not duration_task.done():
                        duration_task.cancel()

                if hasattr(ctx, "add_shutdown_callback"):
                    ctx.add_shutdown_callback(_cleanup_pipeline)

                pipeline_agent.start(ctx.room)
                duration_task = asyncio.create_task(
                    _enforce_max_duration(ctx, max_session_seconds, session_id, room_name)
                )

                await _wait_for_learner(ctx)
                await pipeline_agent.say(build_greeting(metadata), allow_interruptions=True)

        except Exception as exc:
            if duration_task and not duration_task.done():
                duration_task.cancel()
            await _handle_session_error(
                exc, "agent_error", "Runtime error in voice agent session: %s", exc, exc_info=True
            )

    except Exception as unhandled_exc:
        # Capture any unhandled exception inside a job before the worker disconnects
        await _handle_session_error(
            unhandled_exc, "agent_error", "Unhandled exception in voice agent job: %s", unhandled_exc, exc_info=True
        )


__all__ = [
    "VOICE_MAX_SESSION_SECONDS",
    "_wait_for_learner",
    "entrypoint",
    "prewarm",
]
