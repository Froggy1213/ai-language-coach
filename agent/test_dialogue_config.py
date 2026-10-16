"""What the deployment configuration actually decides (plan §5, §7, §8).

`agent.py` reads its whole runtime behaviour out of the environment: which model
answers the learner, which voice reads it back, how long the agent waits before
deciding a turn is over. A silent regression in any of those is invisible at
deploy time — the worker boots, the health check passes, and the only symptom is
a conversation that pauses too long or a German lesson read by an English voice.

These tests pin the decisions, not the plumbing: each one names the mistake it
would catch.
"""

import os
import unittest
from unittest.mock import MagicMock, patch

try:
    from livekit.plugins import openai  # noqa: F401

    HAS_OPENAI_PLUGIN = True
except ImportError:
    HAS_OPENAI_PLUGIN = False

from agent import (
    ALLOW_SLOW_DIALOGUE_ENV,
    KNOWN_DIALOGUE_PROVIDERS,
    _env_per_language,
    _language_code,
    assert_dialogue_provider_is_suitable,
    build_greeting,
    build_turn_handling,
    create_llm,
    create_stt,
    create_tts,
)


class TestLanguageCode(unittest.TestCase):
    def test_it_reduces_a_locale_to_its_language(self):
        # Session metadata written by Laravel carries `en`; a hand-built job or a
        # future locale-aware client sends `en-US`. Both have to reach Deepgram
        # and the voice maps as the same thing.
        for raw in ("en", "en-US", "EN", "en_US", " en-GB "):
            self.assertEqual("en", _language_code(raw))

    def test_an_empty_language_is_the_default_language_not_no_language(self):
        self.assertEqual("en", _language_code(""))
        self.assertEqual("en", _language_code(None))

    def test_it_keeps_a_language_that_has_no_region(self):
        self.assertEqual("de", _language_code("de"))
        self.assertEqual("pt", _language_code("pt-BR"))


class TestPerLanguageSetting(unittest.TestCase):
    def test_a_language_specific_setting_wins_over_the_general_one(self):
        with patch.dict(
            os.environ,
            {"CARTESIA_VOICE": "english-voice", "CARTESIA_VOICE_DE": "german-voice"},
            clear=True,
        ):
            self.assertEqual("english-voice", _env_per_language("CARTESIA_VOICE", "en"))
            self.assertEqual("german-voice", _env_per_language("CARTESIA_VOICE", "de"))

    def test_it_falls_back_to_the_callers_default_when_nothing_is_configured(self):
        # The caller passes the built-in voice for the language, so an
        # unconfigured deployment gets a voice rather than an empty string.
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual("builtin", _env_per_language("CARTESIA_VOICE", "de", "builtin"))


class TestTurnHandling(unittest.TestCase):
    def test_it_uses_the_streaming_endpointing_defaults_not_the_legacy_ones(self):
        with patch.dict(os.environ, {}, clear=True):
            endpointing = build_turn_handling()["endpointing"]

        # livekit-agents tightens these to 0.3/2.5 once a streaming turn
        # detector is attached. Repeating the looser legacy 0.5/3.0 here would
        # silently make the agent slower to answer than leaving it alone.
        self.assertEqual("dynamic", endpointing["mode"])
        self.assertAlmostEqual(0.3, endpointing["min_delay"])
        self.assertAlmostEqual(2.5, endpointing["max_delay"])

    def test_the_endpointing_window_is_configurable(self):
        with patch.dict(
            os.environ,
            {
                "ENDPOINTING_MODE": "fixed",
                "ENDPOINTING_MIN_DELAY_SECONDS": "0.6",
                "ENDPOINTING_MAX_DELAY_SECONDS": "4",
            },
            clear=True,
        ):
            endpointing = build_turn_handling()["endpointing"]

        self.assertEqual("fixed", endpointing["mode"])
        self.assertAlmostEqual(0.6, endpointing["min_delay"])
        self.assertAlmostEqual(4.0, endpointing["max_delay"])

    def test_preemptive_generation_is_on_and_can_be_turned_off(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertTrue(build_turn_handling()["preemptive_generation"]["enabled"])

        with patch.dict(os.environ, {"PREEMPTIVE_GENERATION": "false"}, clear=True):
            self.assertFalse(build_turn_handling()["preemptive_generation"]["enabled"])

    def test_the_semantic_turn_detector_stays_on_unless_it_is_switched_off(self):
        with patch.dict(os.environ, {}, clear=True):
            # Absent means "whatever AgentSession defaults to", which is the
            # on-device end-of-turn model.
            self.assertNotIn("turn_detection", build_turn_handling())

        with patch.dict(os.environ, {"TURN_DETECTION": "off"}, clear=True):
            # `"vad"` is the SDK's own value for a plain silence timer; `None` is
            # not in TurnDetectionMode and would be a type error.
            self.assertEqual("vad", build_turn_handling()["turn_detection"])


@unittest.skipUnless(HAS_OPENAI_PLUGIN, "livekit-plugins-openai is not installed on this host")
class TestDialogueProviderSelection(unittest.TestCase):
    """Which model answers the learner (plan §7: the model chosen by the benchmark)."""

    def llm(self, env):
        with patch.dict(os.environ, env, clear=True):
            return create_llm()

    def test_a_named_provider_is_the_one_that_runs(self):
        llm = self.llm(
            {"LLM_PROVIDER": "groq", "GROQ_API_KEY": "gsk-test", "DEEPSEEK_API_KEY": "sk-deepseek"}
        )

        self.assertEqual("openai/gpt-oss-120b", llm.model)
        # The key that was not named must not be the one that is used: picking
        # DeepSeek here is precisely the regression this test exists for.
        self.assertIn("groq.com", str(getattr(llm._client, "base_url", "groq.com")))

    def test_the_model_can_be_pinned_against_the_provider_default(self):
        llm = self.llm({"LLM_PROVIDER": "groq", "GROQ_API_KEY": "gsk-test", "LLM_MODEL": "openai/gpt-oss-20b"})

        self.assertEqual("openai/gpt-oss-20b", llm.model)

    def test_an_unnamed_provider_is_inferred_fastest_first(self):
        # Both keys present: auto-detection must not fall back to "whichever key
        # exists", which is how this deployment ended up on the slowest of the
        # candidates it had.
        llm = self.llm({"GROQ_API_KEY": "gsk-test", "DEEPSEEK_API_KEY": "sk-deepseek"})

        self.assertEqual("openai/gpt-oss-120b", llm.model)

    def test_a_provider_without_a_base_url_uses_the_vendor_endpoint(self):
        # `base_url` is only passed when it is known, so OpenAI must not be sent
        # an empty string — that produces a client pointed at nowhere.
        llm = self.llm({"LLM_PROVIDER": "openai", "OPENAI_API_KEY": "sk-test"})

        self.assertEqual("gpt-4o-mini", llm.model)
        self.assertIn("openai.com", str(getattr(llm._client, "base_url", "openai.com")))

    def test_a_provider_without_a_key_is_refused_by_name(self):
        with self.assertRaises(ValueError) as raised:
            self.llm({"LLM_PROVIDER": "groq"})

        self.assertIn("GROQ_API_KEY", str(raised.exception))

    def test_a_custom_openai_compatible_provider_still_works(self):
        llm = self.llm(
            {
                "LLM_PROVIDER": "myprovider",
                "MYPROVIDER_API_KEY": "key",
                "LLM_MODEL": "my-model",
                "LLM_BASE_URL": "https://llm.internal/v1",
            }
        )

        self.assertEqual("my-model", llm.model)

    def test_only_a_disqualified_key_is_refused_rather_than_used(self):
        # The benchmark measured DeepSeek at 1068 ms p95 TTFT against a 600 ms
        # budget. Booting anyway would produce a fleet that looks healthy and
        # pauses after every utterance.
        with self.assertRaises(ValueError) as raised:
            self.llm({"DEEPSEEK_API_KEY": "sk-deepseek"})

        self.assertIn("latency budget", str(raised.exception))

    def test_the_override_actually_runs_the_disqualified_provider(self):
        # The escape hatch has to *use* the provider, not merely stop refusing it.
        # An earlier version asserted the guard passed and then fell through to
        # "no dialogue LLM is configured", which is the worst of both: the
        # operator overrode the check and still got a worker that would not boot.
        llm = self.llm({"DEEPSEEK_API_KEY": "sk-deepseek", ALLOW_SLOW_DIALOGUE_ENV: "true"})

        self.assertEqual("deepseek-chat", llm.model)

    def test_the_no_provider_error_names_a_real_variable(self):
        # `', '.join()` on a string splits it into characters, which produced
        # "(G, R, O, Q, _, A, P, I, _, K, E, Y / …)" in the one message an
        # operator sees when nothing is configured.
        with self.assertRaises(ValueError) as raised:
            self.llm({})

        self.assertIn("GROQ_API_KEY", str(raised.exception))
        self.assertNotIn("G, R, O", str(raised.exception))

    def test_a_custom_provider_without_a_model_or_endpoint_is_refused(self):
        # There is no sensible default to guess: the previous fallback was a
        # DeepSeek model id, which any other endpoint answers with a 404 at the
        # learner's first turn.
        with self.assertRaises(ValueError) as missing_model:
            self.llm({"LLM_PROVIDER": "myprovider", "MYPROVIDER_API_KEY": "key"})

        self.assertIn("LLM_MODEL", str(missing_model.exception))

        with self.assertRaises(ValueError) as missing_url:
            self.llm({"LLM_PROVIDER": "myprovider", "MYPROVIDER_API_KEY": "key", "LLM_MODEL": "m"})

        self.assertIn("LLM_BASE_URL", str(missing_url.exception))


class TestSlowProviderGuard(unittest.TestCase):
    def test_a_fast_provider_is_never_questioned(self):
        for provider in KNOWN_DIALOGUE_PROVIDERS:
            if provider == "deepseek":
                continue
            assert_dialogue_provider_is_suitable(provider)

    def test_the_disqualified_provider_is_refused_with_a_way_out(self):
        with patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(ValueError) as raised:
                assert_dialogue_provider_is_suitable("deepseek")

        message = str(raised.exception)
        self.assertIn("deepseek", message)
        self.assertIn("ALLOW_SLOW_DIALOGUE_LLM", message)
        self.assertIn("benchmarks/README.md", message)

    def test_the_override_is_deliberate_and_available(self):
        with patch.dict(os.environ, {ALLOW_SLOW_DIALOGUE_ENV: "true"}, clear=True):
            assert_dialogue_provider_is_suitable("deepseek")

    def test_an_unknown_provider_is_not_classified(self):
        # A local or bespoke endpoint has no measured latency to hold it to, and
        # the deployment that points at one has made its own trade.
        assert_dialogue_provider_is_suitable("myprovider")


class TestTtsVoiceFollowsTheSessionLanguage(unittest.TestCase):
    """
    A voice is a single-language asset (plan §5).

    The previous implementation took no language at all and named
    `aura-asteria-en` in its Deepgram fallback, so the first non-English language
    added to `config/languages.php` would have been read aloud by an English voice.
    """

    def setUp(self):
        self.cartesia = MagicMock()
        self.deepgram = MagicMock()
        self.openai = MagicMock()

        self.patches = [
            patch("agent.cartesia", self.cartesia, create=True),
            patch("agent.HAS_CARTESIA", True),
            patch("agent.deepgram", self.deepgram),
            patch("agent.HAS_PLUGINS", True),
            patch("agent.openai", self.openai, create=True),
            patch("agent.HAS_OPENAI", True),
        ]

        for started in self.patches:
            started.start()
            self.addCleanup(started.stop)

    def test_cartesia_uses_one_multilingual_voice_for_every_language(self):
        with patch.dict(os.environ, {"CARTESIA_API_KEY": "cart-key"}, clear=True):
            create_tts({"target_language": "de"})

        self.assertEqual("sonic-3", self.cartesia.TTS.call_args.kwargs["model"])
        # Nothing configured means the built-in voice for the language, not "".
        self.assertTrue(self.cartesia.TTS.call_args.kwargs["voice"])

    def test_a_language_specific_voice_variable_wins(self):
        with patch.dict(
            os.environ,
            {"CARTESIA_API_KEY": "cart-key", "CARTESIA_VOICE_DE": "a-german-voice"},
            clear=True,
        ):
            create_tts({"target_language": "de"})

        self.assertEqual("a-german-voice", self.cartesia.TTS.call_args.kwargs["voice"])

    def test_deepgram_falls_back_to_the_voice_of_the_session_language(self):
        # Deepgram Aura voices are per language, so a German session must not be
        # handed the English model. These ids come from the account's own
        # /v1/models listing rather than from a guess.
        with patch.dict(os.environ, {"DEEPGRAM_API_KEY": "dg-key"}, clear=True):
            create_tts({"target_language": "de"})

        self.assertEqual("aura-2-aurelia-de", self.deepgram.TTS.call_args.kwargs["model"])

    def test_an_unmapped_language_gets_a_voice_rather_than_an_empty_string(self):
        with patch.dict(os.environ, {"DEEPGRAM_API_KEY": "dg-key"}, clear=True):
            create_tts({"target_language": "sv"})

        self.assertTrue(self.deepgram.TTS.call_args.kwargs["model"])

    def test_no_language_at_all_is_english(self):
        with patch.dict(os.environ, {"DEEPGRAM_API_KEY": "dg-key"}, clear=True):
            create_tts({})

        self.assertEqual("aura-2-thalia-en", self.deepgram.TTS.call_args.kwargs["model"])

    def test_a_forwarded_but_empty_variable_does_not_become_the_model(self):
        # Docker Compose forwards every variable it declares: `OPENAI_TTS_MODEL:
        # ${OPENAI_TTS_MODEL:-}` puts an empty string in the container, and
        # `os.getenv(name, default)` returns "" rather than the default when the
        # variable exists. That empty string used to be handed to the provider as
        # the model name and rejected at the first turn — a live session failing
        # rather than a worker failing to start.
        with patch.dict(
            os.environ,
            {"DEEPGRAM_API_KEY": "dg-key", "DEEPGRAM_TTS_MODEL": "", "CARTESIA_MODEL": "  "},
            clear=True,
        ):
            create_tts({"target_language": "en"})

        self.assertEqual("aura-2-thalia-en", self.deepgram.TTS.call_args.kwargs["model"])

    def test_a_blank_stt_model_falls_back_instead_of_emptying_the_recogniser(self):
        with patch.dict(os.environ, {"DEEPGRAM_STT_MODEL": ""}, clear=True):
            create_stt({"target_language": "en"})

        self.assertEqual("nova-3", self.deepgram.STT.call_args.kwargs["model"])

    def test_a_blank_endpointing_mode_falls_back_to_dynamic(self):
        with patch.dict(os.environ, {"ENDPOINTING_MODE": "  "}, clear=True):
            self.assertEqual("dynamic", build_turn_handling()["endpointing"]["mode"])


class TestGreeting(unittest.TestCase):
    def test_it_speaks_the_scenario_without_reading_the_metadata_aloud(self):
        greeting = build_greeting(
            {
                "target_language": "de",
                "grammar_point": "present_perfect",
                "practice_prompt": "Describe your usual weekday",
            }
        )

        # The catalogue prompt is already a full instruction to the learner, so it
        # is spoken as the task; the raw grammar-point code is not spoken at all.
        self.assertIn("Describe your usual weekday", greeting)
        self.assertNotIn("present_perfect", greeting)
        self.assertIn("German", greeting)
        self.assertLess(len(greeting.split()), 30)

    def test_it_still_says_something_without_a_scenario(self):
        greeting = build_greeting({"target_language": "en"})

        self.assertNotIn("None", greeting)
        self.assertTrue(greeting.strip())


try:
    from livekit.agents import AgentSession
    from livekit.plugins import silero

    HAS_AGENT_SESSION = True
except ImportError:
    AgentSession = None  # type: ignore[assignment]
    silero = None  # type: ignore[assignment]
    HAS_AGENT_SESSION = False


@unittest.skipUnless(HAS_AGENT_SESSION, "livekit-agents is not installed on this host")
class TestTurnHandlingIsAcceptedByTheSdk(unittest.TestCase):
    """
    The mapping `build_turn_handling` returns has to satisfy the SDK, not just
    this repository.

    `turn_handling` is a TypedDict read with `dict.get`, so a typo in a key or an
    invented value for `mode` is silently ignored at runtime: the agent starts and
    simply behaves like the default. Building a real `AgentSession` from it is what
    turns that into a failing test.
    """

    def session(self, env):
        vad = silero.VAD.load()

        with patch.dict(os.environ, env, clear=True):
            return AgentSession(vad=vad, turn_handling=build_turn_handling())

    def test_the_default_configuration_lands_as_dynamic_endpointing(self):
        session = self.session({})
        endpointing = session._opts.turn_handling["endpointing"]

        self.assertEqual("dynamic", endpointing["mode"])
        self.assertAlmostEqual(0.3, endpointing["min_delay"])
        self.assertAlmostEqual(2.5, endpointing["max_delay"])
        # Preemptive generation is what keeps the model off the critical path on
        # the turns where the interim transcript was right.
        self.assertTrue(session._opts.turn_handling["preemptive_generation"]["enabled"])

    def test_turning_the_semantic_detector_off_still_constructs_a_session(self):
        # `"vad"` is the SDK's own value for a silence timer; anything else here
        # (including None) is not in TurnDetectionMode and only shows up at
        # construction time.
        session = self.session({"TURN_DETECTION": "off"})

        self.assertEqual("vad", session._opts.turn_handling["turn_detection"])

    def test_the_semantic_detector_is_the_on_device_model_by_default(self):
        session = self.session({})
        detector = session._opts.turn_handling["turn_detection"]

        # `turn-detector-v1-mini` runs locally from the livekit-local-inference
        # wheel; `turn-detector-v1` would mean a LiveKit Cloud round trip, which
        # this self-hosted deployment has no credentials for.
        self.assertEqual("turn-detector-v1-mini", detector.model)


if __name__ == "__main__":
    unittest.main()
