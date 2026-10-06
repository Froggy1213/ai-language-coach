import asyncio
import json
import unittest
from unittest.mock import AsyncMock, MagicMock, patch

import sentry_integration
from sentry_integration import (
    MAX_STRING_LENGTH,
    REDACTED_SENTINEL,
    SENSITIVE_KEYS,
    TRUNCATED_SUFFIX,
    capture_exception,
    init_sentry,
    is_sentry_enabled,
    scrub_event,
    set_session_tags,
)
from agent import entrypoint


class TestSentryUnconfigured(unittest.TestCase):
    """
    Verifies that Sentry is a complete no-op when SENTRY_DSN is absent.
    No network attempt is made, SDK is not initialized, and helper functions safely no-op.
    """

    def setUp(self):
        sentry_integration._sentry_initialized = False

    def tearDown(self):
        sentry_integration._sentry_initialized = False

    def test_noop_when_dsn_absent(self):
        env = {
            "SENTRY_DSN": "",
            "SENTRY_ENVIRONMENT": "production",
            "SENTRY_RELEASE": "v1.0.0",
        }
        mock_sdk = MagicMock()
        with patch.dict("os.environ", env, clear=True), \
             patch.object(sentry_integration, "sentry_sdk", mock_sdk), \
             patch.object(sentry_integration, "HAS_SENTRY", True):
            initialized = init_sentry(force=True)
            self.assertFalse(initialized)
            self.assertFalse(is_sentry_enabled())
            mock_sdk.init.assert_not_called()

            # Exception capture should be an immediate no-op
            result = capture_exception(Exception("test_error"))
            self.assertIsNone(result)
            mock_sdk.capture_exception.assert_not_called()

    def test_noop_when_dsn_is_only_whitespace(self):
        env = {"SENTRY_DSN": "   "}
        mock_sdk = MagicMock()
        with patch.dict("os.environ", env, clear=True), \
             patch.object(sentry_integration, "sentry_sdk", mock_sdk), \
             patch.object(sentry_integration, "HAS_SENTRY", True):
            initialized = init_sentry(force=True)
            self.assertFalse(initialized)
            mock_sdk.init.assert_not_called()

    def test_set_session_tags_noop_when_disabled(self):
        mock_sdk = MagicMock()
        with patch.object(sentry_integration, "sentry_sdk", mock_sdk), \
             patch.object(sentry_integration, "HAS_SENTRY", True):
            sentry_integration._sentry_initialized = False
            set_session_tags(
                session_id=42,
                room_name="lesson-101",
                grammar_point="present_perfect",
                target_language="en",
            )
            mock_sdk.set_tag.assert_not_called()

    def test_init_sentry_handles_missing_sdk_gracefully(self):
        env = {"SENTRY_DSN": "https://fake@o0.ingest.sentry.io/123"}
        with patch.dict("os.environ", env, clear=True), \
             patch.object(sentry_integration, "sentry_sdk", None), \
             patch.object(sentry_integration, "HAS_SENTRY", False):
            initialized = init_sentry(force=True)
            self.assertFalse(initialized)
            self.assertFalse(is_sentry_enabled())


class TestSentryInitialization(unittest.TestCase):
    """
    Verifies Sentry initialization with expected options when SENTRY_DSN is present.
    Uses monkeypatching / mocks to ensure no network calls are made.
    """

    def setUp(self):
        sentry_integration._sentry_initialized = False

    def tearDown(self):
        sentry_integration._sentry_initialized = False

    def test_initialization_passes_expected_options(self):
        env = {
            "SENTRY_DSN": "https://pubkey@o12345.ingest.sentry.io/67890",
            "SENTRY_ENVIRONMENT": "staging",
            "SENTRY_RELEASE": "agent-v1.0.4",
            "SENTRY_TRACES_SAMPLE_RATE": "0.15",
        }
        mock_sdk = MagicMock()
        with patch.dict("os.environ", env, clear=True), \
             patch.object(sentry_integration, "sentry_sdk", mock_sdk), \
             patch.object(sentry_integration, "HAS_SENTRY", True):
            initialized = init_sentry(force=True)
            self.assertTrue(initialized)
            self.assertTrue(is_sentry_enabled())

            mock_sdk.init.assert_called_once_with(
                dsn="https://pubkey@o12345.ingest.sentry.io/67890",
                environment="staging",
                release="agent-v1.0.4",
                traces_sample_rate=0.15,
                send_default_pii=False,
                before_send=scrub_event,
            )

    def test_initialization_defaults(self):
        # When optional vars are unset, environment falls back to APP_ENV,
        # release is None, and traces_sample_rate defaults to 0.0 (errors only).
        env = {
            "SENTRY_DSN": "https://pubkey@o12345.ingest.sentry.io/67890",
            "APP_ENV": "production",
            "SENTRY_ENVIRONMENT": "",
            "SENTRY_RELEASE": "",
            "SENTRY_TRACES_SAMPLE_RATE": "",
        }
        mock_sdk = MagicMock()
        with patch.dict("os.environ", env, clear=True), \
             patch.object(sentry_integration, "sentry_sdk", mock_sdk), \
             patch.object(sentry_integration, "HAS_SENTRY", True):
            initialized = init_sentry(force=True)
            self.assertTrue(initialized)

            mock_sdk.init.assert_called_once_with(
                dsn="https://pubkey@o12345.ingest.sentry.io/67890",
                environment="production",
                release=None,
                traces_sample_rate=0.0,
                send_default_pii=False,
                before_send=scrub_event,
            )

    def test_initialization_environment_fallback_to_local(self):
        env = {
            "SENTRY_DSN": "https://pubkey@o12345.ingest.sentry.io/67890",
        }
        mock_sdk = MagicMock()
        with patch.dict("os.environ", env, clear=True), \
             patch.object(sentry_integration, "sentry_sdk", mock_sdk), \
             patch.object(sentry_integration, "HAS_SENTRY", True):
            init_sentry(force=True)
            call_kwargs = mock_sdk.init.call_args.kwargs
            self.assertEqual(call_kwargs["environment"], "local")

    def test_initialization_invalid_traces_sample_rate_defaults_to_zero(self):
        env = {
            "SENTRY_DSN": "https://pubkey@o12345.ingest.sentry.io/67890",
            "SENTRY_TRACES_SAMPLE_RATE": "not-a-float",
        }
        mock_sdk = MagicMock()
        with patch.dict("os.environ", env, clear=True), \
             patch.object(sentry_integration, "sentry_sdk", mock_sdk), \
             patch.object(sentry_integration, "HAS_SENTRY", True):
            init_sentry(force=True)
            call_kwargs = mock_sdk.init.call_args.kwargs
            self.assertEqual(call_kwargs["traces_sample_rate"], 0.0)


class TestBeforeSendScrubber(unittest.TestCase):
    """
    Verifies that the before_send scrubber removes/redacts every transcript-bearing key
    and leaves non-sensitive telemetry, diagnostic tags, and timings intact.
    Also verifies string truncation for over-long free-form strings.
    """

    def test_scrubber_redacts_all_sensitive_keys(self):
        sensitive_payload = {
            "transcript": "I have visited Rome last summer.",
            "practice_prompt": "Describe your favourite vacation destination.",
            "practicePrompt": "CamelCase prompt test.",
            "user_utterance": "I have visited Rome yesterday.",
            "userUtterance": "CamelCase utterance test.",
            "correction": "I visited Rome yesterday.",
            "explanation": "Use past simple with specific past time.",
            "last_user_transcript": "I have visited Rome.",
            "text_content": "Raw spoken content.",
            "raw_text_content": "Alternative raw content.",
            "user_speech": "User audio transcript.",
            "learner_speech": "Learner audio transcript.",
            "user_transcript": "User transcript.",
            "learner_transcript": "Learner transcript.",
            "user_input": "User input text.",
            "learner_utterance": "Learner utterance.",
            "utterance": "Generic utterance.",
        }

        scrubbed = scrub_event(sensitive_payload)

        for key in sensitive_payload:
            self.assertIn(key, scrubbed)
            self.assertEqual(
                scrubbed[key],
                REDACTED_SENTINEL,
                f"Key '{key}' was not redacted! Value: {scrubbed[key]}",
            )

    def test_scrubber_leaves_non_sensitive_keys_intact(self):
        safe_payload = {
            "event_id": "abc123def456",
            "timestamp": 1728000000.5,
            "level": "error",
            "tags": {
                "voice_session_id": "42",
                "room_name": "lesson-7",
                "grammar_point": "present_perfect",
                "target_language": "en",
                "level": "B1",
            },
            "extra": {
                "session_id": 42,
                "turn_id": "sp-101",
                "speech_id": "sp-101",
                # Timing metrics should NEVER be redacted
                "stt_final": 250.0,
                "llm_first_token": 350.0,
                "tts_first_chunk": 120.0,
                "total_turnaround": 720.0,
                "transcription_delay": 0.22,
                "end_of_utterance_delay": 0.35,
            },
        }

        scrubbed = scrub_event(safe_payload)

        # Tags remain completely intact
        self.assertEqual(scrubbed["tags"]["voice_session_id"], "42")
        self.assertEqual(scrubbed["tags"]["room_name"], "lesson-7")
        self.assertEqual(scrubbed["tags"]["grammar_point"], "present_perfect")
        self.assertEqual(scrubbed["tags"]["target_language"], "en")
        self.assertEqual(scrubbed["tags"]["level"], "B1")

        # Telemetry timings remain completely intact with original numbers
        extra = scrubbed["extra"]
        self.assertEqual(extra["session_id"], 42)
        self.assertEqual(extra["turn_id"], "sp-101")
        self.assertEqual(extra["stt_final"], 250.0)
        self.assertEqual(extra["llm_first_token"], 350.0)
        self.assertEqual(extra["tts_first_chunk"], 120.0)
        self.assertEqual(extra["total_turnaround"], 720.0)
        self.assertEqual(extra["transcription_delay"], 0.22)
        self.assertEqual(extra["end_of_utterance_delay"], 0.35)

    def test_scrubber_truncates_overlong_freeform_strings(self):
        long_string = "A" * (MAX_STRING_LENGTH + 100)
        short_string = "Normal length error message."

        payload = {
            "error_message": long_string,
            "status_message": short_string,
        }

        scrubbed = scrub_event(payload)

        # Short string is unchanged
        self.assertEqual(scrubbed["status_message"], short_string)

        # Long string is truncated to MAX_STRING_LENGTH with suffix
        expected_prefix = "A" * MAX_STRING_LENGTH
        self.assertTrue(scrubbed["error_message"].startswith(expected_prefix))
        self.assertTrue(scrubbed["error_message"].endswith(TRUNCATED_SUFFIX))
        self.assertEqual(
            len(scrubbed["error_message"]),
            MAX_STRING_LENGTH + len(TRUNCATED_SUFFIX),
        )

    def test_scrubber_handles_nested_breadcrumbs_and_vars(self):
        payload = {
            "breadcrumbs": {
                "values": [
                    {
                        "category": "user_action",
                        "data": {
                            "transcript": "Learner private speech",
                            "turn_id": "turn-1",
                        },
                    }
                ]
            },
            "exception": {
                "values": [
                    {
                        "type": "RuntimeError",
                        "value": "Pipeline failed",
                        "stacktrace": {
                            "frames": [
                                {
                                    "filename": "agent.py",
                                    "vars": {
                                        "transcript": "Secret utterance",
                                        "practice_prompt": "Secret topic",
                                        "session_id": 99,
                                    },
                                }
                            ]
                        },
                    }
                ]
            },
        }

        scrubbed = scrub_event(payload)

        # Breadcrumbs scrubbed
        bc_data = scrubbed["breadcrumbs"]["values"][0]["data"]
        self.assertEqual(bc_data["transcript"], REDACTED_SENTINEL)
        self.assertEqual(bc_data["turn_id"], "turn-1")

        # Frame vars scrubbed
        frame_vars = scrubbed["exception"]["values"][0]["stacktrace"]["frames"][0]["vars"]
        self.assertEqual(frame_vars["transcript"], REDACTED_SENTINEL)
        self.assertEqual(frame_vars["practice_prompt"], REDACTED_SENTINEL)
        self.assertEqual(frame_vars["session_id"], 99)

    def test_scrubber_redacts_embedded_json_log_string(self):
        # agent.py logs structured TURN_LATENCY lines containing JSON with transcript
        raw_log = 'TURN_LATENCY {"event": "voice_turn_latency", "transcript": "secret speech", "stt_final_ms": 250.0}'

        payload = {"log_line": raw_log}
        scrubbed = scrub_event(payload)

        # The JSON inside log_line should have transcript redacted
        self.assertNotIn("secret speech", scrubbed["log_line"])
        self.assertIn(REDACTED_SENTINEL, scrubbed["log_line"])
        self.assertIn('"stt_final_ms": 250.0', scrubbed["log_line"])

    def test_scrubber_handles_none_event(self):
        self.assertIsNone(scrub_event(None))


class TestCaptureExceptionAndSessionFailures(unittest.IsolatedAsyncioTestCase):
    """
    Verifies that every failure path in entrypoint calls capture_exception
    with appropriate session tags before reporting to the backend.
    """

    def setUp(self):
        sentry_integration._sentry_initialized = True

    def tearDown(self):
        sentry_integration._sentry_initialized = False

    async def test_simulated_stt_failure_calls_capture_exception(self):
        ctx = MagicMock()
        ctx.room.name = "lesson-10"
        ctx.job.metadata = json.dumps({
            "session_id": 42,
            "grammar_point": "present_perfect",
            "practice_prompt": "Talk about past experiences",
            "target_language": "en",
        })
        ctx.connect = AsyncMock()
        ctx.proc.userdata.get.return_value = "fake_vad"

        captured_exceptions = []

        def fake_capture(exc, tags=None, **kwargs):
            captured_exceptions.append((exc, tags))
            return "event-id-123"

        with patch("agent.create_stt", side_effect=Exception("stt stream connection failed")), \
             patch("agent.report_session_failure", new_callable=AsyncMock) as mock_fail, \
             patch("agent.capture_exception", side_effect=fake_capture):
            await entrypoint(ctx)

            # Sentry must capture the exception
            self.assertEqual(len(captured_exceptions), 1)
            exc, tags = captured_exceptions[0]
            self.assertEqual(str(exc), "stt stream connection failed")
            self.assertEqual(tags["voice_session_id"], "42")
            self.assertEqual(tags["room_name"], "lesson-10")
            self.assertEqual(tags["grammar_point"], "present_perfect")
            self.assertEqual(tags["target_language"], "en")

            # Backend must still receive failure report
            mock_fail.assert_awaited_once_with(42, "stt_failed")

    async def test_simulated_llm_failure_calls_capture_exception(self):
        ctx = MagicMock()
        ctx.room.name = "lesson-11"
        ctx.job.metadata = json.dumps({
            "session_id": 55,
            "grammar_point": "past_simple",
            "target_language": "en",
        })
        ctx.connect = AsyncMock()
        ctx.proc.userdata.get.return_value = "fake_vad"

        captured_exceptions = []

        def fake_capture(exc, tags=None, **kwargs):
            captured_exceptions.append((exc, tags))
            return "event-id-456"

        with patch("agent.create_stt", return_value="fake_stt"), \
             patch("agent.create_llm", side_effect=ValueError("llm model key error")), \
             patch("agent.report_session_failure", new_callable=AsyncMock) as mock_fail, \
             patch("agent.capture_exception", side_effect=fake_capture):
            await entrypoint(ctx)

            self.assertEqual(len(captured_exceptions), 1)
            exc, tags = captured_exceptions[0]
            self.assertIsInstance(exc, ValueError)
            self.assertEqual(tags["voice_session_id"], "55")
            self.assertEqual(tags["grammar_point"], "past_simple")
            mock_fail.assert_awaited_once_with(55, "llm_failed")

    async def test_simulated_tts_failure_calls_capture_exception(self):
        ctx = MagicMock()
        ctx.room.name = "lesson-12"
        ctx.job.metadata = json.dumps({
            "session_id": 66,
            "grammar_point": "conditionals",
            "target_language": "en",
        })
        ctx.connect = AsyncMock()
        ctx.proc.userdata.get.return_value = "fake_vad"

        captured_exceptions = []

        def fake_capture(exc, tags=None, **kwargs):
            captured_exceptions.append((exc, tags))
            return "event-id-789"

        with patch("agent.create_stt", return_value="fake_stt"), \
             patch("agent.create_llm", return_value="fake_llm"), \
             patch("agent.create_tts", side_effect=RuntimeError("tts synthesis error")), \
             patch("agent.report_session_failure", new_callable=AsyncMock) as mock_fail, \
             patch("agent.capture_exception", side_effect=fake_capture):
            await entrypoint(ctx)

            self.assertEqual(len(captured_exceptions), 1)
            exc, tags = captured_exceptions[0]
            self.assertIsInstance(exc, RuntimeError)
            self.assertEqual(tags["voice_session_id"], "66")
            mock_fail.assert_awaited_once_with(66, "tts_failed")

    async def test_simulated_room_connect_failure_calls_capture_exception(self):
        ctx = MagicMock()
        ctx.room.name = "lesson-13"
        ctx.job.metadata = json.dumps({"session_id": 77})
        ctx.connect = AsyncMock(side_effect=Exception("webrtc connection refused"))

        captured_exceptions = []

        def fake_capture(exc, tags=None, **kwargs):
            captured_exceptions.append((exc, tags))
            return "event-id-connect"

        with patch("agent.report_session_failure", new_callable=AsyncMock) as mock_fail, \
             patch("agent.capture_exception", side_effect=fake_capture):
            await entrypoint(ctx)

            self.assertEqual(len(captured_exceptions), 1)
            exc, tags = captured_exceptions[0]
            self.assertEqual(str(exc), "webrtc connection refused")
            self.assertEqual(tags["voice_session_id"], "77")
            mock_fail.assert_awaited_once_with(77, "agent_error")

    async def test_unhandled_job_exception_captured_before_disconnect(self):
        ctx = MagicMock()
        ctx.room.name = "lesson-99"
        ctx.job.metadata = json.dumps({"session_id": 99})
        # Simulate an unexpected exception during build_system_prompt
        ctx.connect = AsyncMock()
        ctx.proc.userdata.get.return_value = "fake_vad"

        captured_exceptions = []

        def fake_capture(exc, tags=None, **kwargs):
            captured_exceptions.append((exc, tags))
            return "event-id-unhandled"

        with patch("agent.create_stt", return_value="fake_stt"), \
             patch("agent.create_llm", return_value="fake_llm"), \
             patch("agent.create_tts", return_value="fake_tts"), \
             patch("agent.build_system_prompt", side_effect=ZeroDivisionError("unexpected division error")), \
             patch("agent.report_session_failure", new_callable=AsyncMock) as mock_fail, \
             patch("agent.capture_exception", side_effect=fake_capture):
            await entrypoint(ctx)

            # Unhandled exception was intercepted and captured to Sentry
            self.assertEqual(len(captured_exceptions), 1)
            exc, tags = captured_exceptions[0]
            self.assertIsInstance(exc, ZeroDivisionError)
            self.assertEqual(tags["voice_session_id"], "99")
            mock_fail.assert_awaited_once_with(99, "agent_error")

    def test_capture_exception_calls_sdk_with_isolation_scope(self):
        mock_sdk = MagicMock()
        mock_scope = MagicMock()
        mock_sdk.isolation_scope.return_value.__enter__ = MagicMock(return_value=mock_scope)
        mock_sdk.isolation_scope.return_value.__exit__ = MagicMock(return_value=None)
        mock_sdk.capture_exception.return_value = "sentry-event-id"

        with patch.object(sentry_integration, "sentry_sdk", mock_sdk), \
             patch.object(sentry_integration, "HAS_SENTRY", True), \
             patch.object(sentry_integration, "_sentry_initialized", True):
            test_exc = RuntimeError("synthetic test")
            event_id = capture_exception(
                test_exc,
                tags={"voice_session_id": "42", "room_name": "lesson-1"},
            )
            self.assertEqual(event_id, "sentry-event-id")
            mock_scope.set_tag.assert_any_call("voice_session_id", "42")
            mock_scope.set_tag.assert_any_call("room_name", "lesson-1")
            mock_sdk.capture_exception.assert_called_once_with(test_exc)


if __name__ == "__main__":
    unittest.main()
