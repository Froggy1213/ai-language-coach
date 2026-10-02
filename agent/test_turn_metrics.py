import asyncio
import json
import unittest
from unittest.mock import AsyncMock, patch

from agent import (
    TurnMetricsTracker,
    build_cloudwatch_turn_log,
    build_turn_payload,
    compute_total_turnaround,
    extract_metric_info,
    extract_user_transcript,
    to_ms,
)


class DummyMetric:
    def __init__(self, **kwargs):
        for k, v in kwargs.items():
            setattr(self, k, v)


class DummyChatMessage:
    def __init__(self, role="user", content=None, text_content=None, raw_text_content=None):
        self.role = role
        if content is not None:
            self.content = content
        if text_content is not None:
            self.text_content = text_content
        if raw_text_content is not None:
            self.raw_text_content = raw_text_content


class DummyEvent:
    def __init__(self, item):
        self.item = item


class TestTurnMetrics(unittest.TestCase):
    def test_to_ms(self):
        self.assertEqual(to_ms(0.2455), 245.5)
        self.assertEqual(to_ms(0.0), 0.0)
        self.assertEqual(to_ms(1.0), 1000.0)
        self.assertIsNone(to_ms(None))
        self.assertIsNone(to_ms(-0.5))
        self.assertIsNone(to_ms("invalid"))

    def test_compute_total_turnaround(self):
        self.assertAlmostEqual(compute_total_turnaround(0.2, 0.4, 0.1), 0.7)
        self.assertAlmostEqual(compute_total_turnaround(None, 0.4, 0.1), 0.5)
        self.assertAlmostEqual(compute_total_turnaround(0.2, None, None), 0.2)
        self.assertIsNone(compute_total_turnaround(None, None, None))
        self.assertIsNone(compute_total_turnaround(-1.0, -2.0, None))

    def test_build_turn_payload_units_and_contract(self):
        payload = build_turn_payload(
            turn_id="turn-101",
            stt_final_sec=0.25,
            llm_first_token_sec=0.38,
            tts_first_chunk_sec=0.15,
            total_turnaround_sec=0.78,
            transcript="Hello world",
        )
        self.assertEqual(
            payload,
            {
                "turn_id": "turn-101",
                "transcript": "Hello world",
                "stt_final": 250.0,
                "llm_first_token": 380.0,
                "tts_first_chunk": 150.0,
                "total_turnaround": 780.0,
            },
        )
        # Check nullable fields and units
        partial_payload = build_turn_payload(
            turn_id="turn-102",
            stt_final_sec=None,
            llm_first_token_sec=0.1234,
            tts_first_chunk_sec=None,
            total_turnaround_sec=0.1234,
            transcript=None,
        )
        self.assertEqual(partial_payload["stt_final"], None)
        self.assertEqual(partial_payload["llm_first_token"], 123.4)
        self.assertEqual(partial_payload["tts_first_chunk"], None)
        self.assertEqual(partial_payload["total_turnaround"], 123.4)
        self.assertIsNone(partial_payload["transcript"])

    def test_build_cloudwatch_turn_log(self):
        log_entry = build_cloudwatch_turn_log(
            session_id=42,
            turn_id="turn-101",
            stt_final_sec=0.25,
            llm_first_token_sec=0.38,
            tts_first_chunk_sec=0.15,
            total_turnaround_sec=0.78,
            transcript="Hello world",
        )
        self.assertEqual(log_entry["event"], "voice_turn_latency")
        self.assertEqual(log_entry["session_id"], 42)
        self.assertEqual(log_entry["turn_id"], "turn-101")
        self.assertEqual(log_entry["stt_final_ms"], 250.0)
        self.assertEqual(log_entry["llm_first_token_ms"], 380.0)
        self.assertEqual(log_entry["tts_first_chunk_ms"], 150.0)
        self.assertEqual(log_entry["total_turnaround_ms"], 780.0)
        self.assertEqual(log_entry["transcript"], "Hello world")

    def test_extract_user_transcript(self):
        # 1. ChatMessage with text_content property
        msg1 = DummyChatMessage(role="user", text_content="I had a good weekend")
        self.assertEqual(extract_user_transcript(msg1), "I had a good weekend")

        # 2. Wrapped in ConversationItemAddedEvent (.item)
        ev = DummyEvent(item=msg1)
        self.assertEqual(extract_user_transcript(ev), "I had a good weekend")

        # 3. ChatMessage with list content (content parts)
        msg_parts = DummyChatMessage(role="user", content=["I went to", "the store"])
        self.assertEqual(extract_user_transcript(msg_parts), "I went to the store")

        # 4. ChatMessage with raw_text_content
        msg_raw = DummyChatMessage(role="user", raw_text_content="Raw transcript")
        self.assertEqual(extract_user_transcript(msg_raw), "Raw transcript")

        # 5. Assistant message should be ignored (returns None)
        assistant_msg = DummyChatMessage(role="assistant", text_content="How can I help?")
        self.assertIsNone(extract_user_transcript(assistant_msg))
        self.assertIsNone(extract_user_transcript(DummyEvent(item=assistant_msg)))

        # 6. Degraded / empty items
        self.assertIsNone(extract_user_transcript(None))
        self.assertIsNone(extract_user_transcript(DummyChatMessage(role="user", content=[])))
        self.assertIsNone(extract_user_transcript(DummyChatMessage(role="user", text_content="   ")))

    def test_extract_metric_info_eou_anchor_and_fallbacks(self):
        # EOU Anchor: when transcription finishes before end-of-utterance silence window (0.22 vs 0.35),
        # the learner waits until turn detector commits the turn (0.35s).
        m1 = DummyMetric(type="eou_metrics", speech_id="speech-1", transcription_delay=0.22, end_of_utterance_delay=0.35)
        info1 = extract_metric_info(m1)
        self.assertEqual(info1["speech_id"], "speech-1")
        self.assertEqual(info1["stt_final_sec"], 0.35)

        # 0.0 default in 1.8.4 EOUMetrics falls back to end_of_utterance_delay
        m2 = DummyMetric(type="eou_metrics", speech_id="speech-2", transcription_delay=0.0, end_of_utterance_delay=0.40)
        info2 = extract_metric_info(m2)
        self.assertEqual(info2["stt_final_sec"], 0.40)

        # Slow transcription: transcription took longer than silence detection (0.55s > 0.35s)
        m3 = DummyMetric(type="eou_metrics", speech_id="speech-3", transcription_delay=0.55, end_of_utterance_delay=0.35)
        info3 = extract_metric_info(m3)
        self.assertEqual(info3["stt_final_sec"], 0.55)

        # Missing EOU delay, transcription only
        m4 = DummyMetric(type="eou_metrics", speech_id="speech-4", transcription_delay=0.28)
        info4 = extract_metric_info(m4)
        self.assertEqual(info4["stt_final_sec"], 0.28)

        # Missing transcription delay, EOU only
        m5 = DummyMetric(type="eou_metrics", speech_id="speech-5", end_of_utterance_delay=0.32)
        info5 = extract_metric_info(m5)
        self.assertEqual(info5["stt_final_sec"], 0.32)

        # end_of_turn_delay alias
        m6 = DummyMetric(type="eou_metrics", speech_id="speech-6", end_of_turn_delay=0.37)
        info6 = extract_metric_info(m6)
        self.assertEqual(info6["stt_final_sec"], 0.37)

    def test_extract_metric_info_llm(self):
        m = DummyMetric(type="llm_metrics", speech_id="speech-1", ttft=0.41, duration=1.2)
        info = extract_metric_info(m)
        self.assertEqual(info["speech_id"], "speech-1")
        self.assertEqual(info["llm_first_token_sec"], 0.41)

    def test_extract_metric_info_tts(self):
        m = DummyMetric(type="tts_metrics", speech_id="speech-1", ttfb=0.18, duration=0.9)
        info = extract_metric_info(m)
        self.assertEqual(info["speech_id"], "speech-1")
        self.assertEqual(info["tts_first_chunk_sec"], 0.18)

    def test_extract_metric_info_stt(self):
        m = DummyMetric(type="stt_metrics", speech_id="speech-stt", duration=0.62)
        info = extract_metric_info(m)
        self.assertEqual(info["stt_final_sec"], 0.62)

    def test_extract_metric_info_degraded_metric(self):
        # Empty metric with unknown attributes should degrade to None fields
        m = DummyMetric(type="unknown_metric")
        info = extract_metric_info(m)
        self.assertIsNone(info["speech_id"])
        self.assertIsNone(info["stt_final_sec"])
        self.assertIsNone(info["llm_first_token_sec"])
        self.assertIsNone(info["tts_first_chunk_sec"])


class TestTurnMetricsTracker(unittest.IsolatedAsyncioTestCase):
    async def test_tracker_correlates_turn_and_dispatches(self):
        tracker = TurnMetricsTracker(session_id=7)
        tracker.on_user_speech("User said this.")

        dispatched_payloads = []

        async def fake_report(session_id, payload):
            dispatched_payloads.append((session_id, payload))

        with patch("agent.report_session_turn", side_effect=fake_report):
            # 1. EOU arrives (anchored to 0.35s)
            tracker.handle_metric(
                DummyMetric(type="eou_metrics", speech_id="sp-1", transcription_delay=0.21, end_of_utterance_delay=0.35)
            )
            # 2. LLM arrives
            tracker.handle_metric(
                DummyMetric(type="llm_metrics", speech_id="sp-1", ttft=0.35)
            )
            # 3. TTS arrives (triggers dispatch)
            tracker.handle_metric(
                DummyMetric(type="tts_metrics", speech_id="sp-1", ttfb=0.15)
            )

            # Give event loop a cycle to run the created task
            await asyncio.sleep(0.01)

            self.assertEqual(len(dispatched_payloads), 1)
            session_id, payload = dispatched_payloads[0]
            self.assertEqual(session_id, 7)
            self.assertEqual(payload["turn_id"], "sp-1")
            self.assertEqual(payload["transcript"], "User said this.")
            self.assertEqual(payload["stt_final"], 350.0)
            self.assertEqual(payload["llm_first_token"], 350.0)
            self.assertEqual(payload["tts_first_chunk"], 150.0)
            self.assertEqual(payload["total_turnaround"], 850.0)

    async def test_phantom_greeting_is_ignored(self):
        tracker = TurnMetricsTracker(session_id=12)
        dispatched_payloads = []

        async def fake_report(session_id, payload):
            dispatched_payloads.append((session_id, payload))

        with patch("agent.report_session_turn", side_effect=fake_report):
            # Opening session.say(greeting) produces TTSMetrics before any user speech
            greeting_metric = DummyMetric(type="tts_metrics", speech_id="greeting-001", ttfb=0.12)
            tracker.handle_metric(greeting_metric)

            await asyncio.sleep(0.01)

            # Must NOT create a pending turn or dispatch a phantom turn
            self.assertEqual(len(tracker.pending_turns), 0)
            self.assertEqual(len(dispatched_payloads), 0)

    async def test_first_ttfb_wins_across_multi_sentence_reply(self):
        tracker = TurnMetricsTracker(session_id=15)
        tracker.on_user_speech("Hello, I have a grammar question.")

        dispatched_payloads = []

        async def fake_report(session_id, payload):
            dispatched_payloads.append((session_id, payload))

        with patch("agent.report_session_turn", side_effect=fake_report):
            # EOU + LLM arrive
            tracker.handle_metric(
                DummyMetric(type="eou_metrics", speech_id="turn-abc", end_of_utterance_delay=0.30)
            )
            tracker.handle_metric(
                DummyMetric(type="llm_metrics", speech_id="turn-abc", ttft=0.25)
            )

            # Sentence 1 TTS arrives
            tracker.handle_metric(
                DummyMetric(type="tts_metrics", speech_id="turn-abc", ttfb=0.14)
            )
            # Sentence 2 TTS arrives for the same turn (multi-sentence reply)
            tracker.handle_metric(
                DummyMetric(type="tts_metrics", speech_id="turn-abc", ttfb=0.48)
            )
            # Sentence 3 TTS arrives
            tracker.handle_metric(
                DummyMetric(type="tts_metrics", speech_id="turn-abc", ttfb=0.92)
            )

            await asyncio.sleep(0.01)

            # Only ONE dispatch should have occurred
            self.assertEqual(len(dispatched_payloads), 1)
            _, payload = dispatched_payloads[0]
            # First TTFB (0.14s -> 140.0 ms) must win, NOT overwritten by 480 or 920 ms
            self.assertEqual(payload["tts_first_chunk"], 140.0)
            self.assertEqual(payload["total_turnaround"], 690.0)

    async def test_completed_turn_is_purged_and_does_not_resurrect(self):
        tracker = TurnMetricsTracker(session_id=20)
        tracker.on_user_speech("Can we practice present perfect?")

        dispatched_payloads = []

        async def fake_report(session_id, payload):
            dispatched_payloads.append((session_id, payload))

        with patch("agent.report_session_turn", side_effect=fake_report):
            tracker.handle_metric(
                DummyMetric(type="eou_metrics", speech_id="turn-xyz", end_of_utterance_delay=0.40)
            )
            tracker.handle_metric(
                DummyMetric(type="tts_metrics", speech_id="turn-xyz", ttfb=0.15)
            )

            await asyncio.sleep(0.01)

            self.assertEqual(len(dispatched_payloads), 1)
            # Completed turn must be purged from pending_turns (memory bound)
            self.assertNotIn("turn-xyz", tracker.pending_turns)
            self.assertEqual(len(tracker.pending_turns), 0)

            # Late arriving TTS or LLM metric for the completed turn must not resurrect it
            tracker.handle_metric(
                DummyMetric(type="tts_metrics", speech_id="turn-xyz", ttfb=0.60)
            )
            await asyncio.sleep(0.01)

            self.assertEqual(len(dispatched_payloads), 1)
            self.assertEqual(len(tracker.pending_turns), 0)

    async def test_turn_dispatch_clears_user_transcript_preventing_stale_reuse(self):
        tracker = TurnMetricsTracker(session_id=30)
        tracker.on_user_speech("User statement 1")

        dispatched_payloads = []

        async def fake_report(session_id, payload):
            dispatched_payloads.append((session_id, payload))

        with patch("agent.report_session_turn", side_effect=fake_report):
            tracker.handle_metric(
                DummyMetric(type="eou_metrics", speech_id="turn-1", end_of_utterance_delay=0.30)
            )
            tracker.handle_metric(
                DummyMetric(type="tts_metrics", speech_id="turn-1", ttfb=0.10)
            )

            await asyncio.sleep(0.01)

            self.assertEqual(len(dispatched_payloads), 1)
            # Transcript was consumed by turn-1
            self.assertIsNone(tracker.last_user_transcript)

            # Subsequent unprompted agent say(...) speech
            tracker.handle_metric(
                DummyMetric(type="tts_metrics", speech_id="unprompted-say", ttfb=0.15)
            )
            await asyncio.sleep(0.01)

            # Must not be treated as a conversational turn using stale "User statement 1"
            self.assertEqual(len(dispatched_payloads), 1)
            self.assertNotIn("unprompted-say", tracker.pending_turns)


if __name__ == "__main__":
    unittest.main()
