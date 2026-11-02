"""
The per-session turn state machine.

LiveKit delivers the stages of one learner turn as separate events — end of
utterance, first LLM token, first TTS chunk — correlated only by `speech_id`, and
arriving out of order across a multi-sentence reply. This module keeps the state
for one call: which turns are still open, when a turn counts as finished, and
what has to reach the backend before the job ends. The measurement itself lives
in `turn_metrics.py`; reporting lives in `backend.py`.
"""

import asyncio
from collections import deque
import json
from typing import Any, Dict, Optional

if __package__:  # imported as `agent.turn_tracker`
    from .backend import report_session_turn
    from .observability import logger
    from .turn_metrics import (
        build_cloudwatch_turn_log,
        build_turn_payload,
        compute_total_turnaround,
        extract_metric_info,
        to_ms,
    )
else:  # run as a flat script: `python agent.py`, the container layout, the tests
    from backend import report_session_turn
    from observability import logger
    from turn_metrics import (
        build_cloudwatch_turn_log,
        build_turn_payload,
        compute_total_turnaround,
        extract_metric_info,
        to_ms,
    )


def _ms_or_zero(seconds: Optional[float]) -> float:
    """The latency in milliseconds, or 0.0 where a stage never arrived."""
    milliseconds = to_ms(seconds)
    return milliseconds if milliseconds is not None else 0.0


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
        elif turn.get("tts_first_chunk_sec") is None:
            if info["llm_first_token_sec"] is not None and speech_id in self._flush_tasks:
                self._cancel_delayed_flush(speech_id)
                self._schedule_delayed_flush(speech_id, delay=self.flush_delay)
            elif speech_id not in self._flush_tasks:
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
                raise

        task = self._create_background_task(_flush_after_delay())
        self._flush_tasks[speech_id] = task

    def _cancel_delayed_flush(self, speech_id: str) -> None:
        task = self._flush_tasks.pop(speech_id, None)
        if task and not task.done() and task is not asyncio.current_task():
            task.cancel()

    async def drain(self, timeout: float = 3.0) -> None:
        """
        Let the turns already scheduled for reporting reach the backend, then close.

        A session now ends as soon as the learner leaves the room, and shutdown
        used to cancel whatever flush was in flight. That flush carries the last
        learner utterance — the one the mistake analysis is about to read — so
        cancelling it silently shortened the transcript. Waiting for the scheduled
        flushes, bounded so a hung backend cannot hold the job, keeps that turn.
        """
        pending = [task for task in self._flush_tasks.values() if not task.done()]
        if pending:
            await asyncio.wait(pending, timeout=timeout)
        self.close()

    def close(self) -> None:
        """Cancel all active flush tasks and clear pending timers."""
        for task in list(self._flush_tasks.values()):
            if not task.done() and task is not asyncio.current_task():
                task.cancel()
        self._flush_tasks.clear()

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
        )

        logger.info(
            "TURN_LATENCY %s %s %s %s %s",
            _ms_or_zero(total_sec),
            _ms_or_zero(turn["stt_final_sec"]),
            _ms_or_zero(turn["llm_first_token_sec"]),
            _ms_or_zero(turn["tts_first_chunk_sec"]),
            json.dumps(cloudwatch_log, separators=(",", ":")),
        )

        await report_session_turn(self.session_id, payload)


__all__ = ["TurnMetricsTracker"]
