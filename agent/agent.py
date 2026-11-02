"""
AI Language Coach — LiveKit voice agent worker.

This module is the entrypoint and the compatibility surface, not the
implementation. It used to be all three: one 1658-line file that imported the
SDK, read the environment, built the pipeline, measured turns, reported to the
backend and drove the call. Those responsibilities now live in the sibling
modules below; this file composes them, which is still what
`python agent.py start`, `import agent` and the image smoke check resolve to.

What each module owns:

    observability.py   the process-wide logger and the Sentry integration
    settings.py        how one setting is read from the environment
    livekit_compat.py  which SDK pieces are installed, and the API generation
    backend.py         how a failure and a finished turn reach Laravel
    turn_metrics.py    how a turn's latency is computed and shaped for the wire
    turn_tracker.py    when a turn is finished (the per-session state machine)
    speech.py          which recogniser and which voice, in the session language
    dialogue.py        which model answers, and how the request is shaped
    turn_handling.py   how end-of-turn is decided
    session.py         the order one voice session runs in, and its failure policy
    worker.py          the worker pool the deployment configured
"""

if __package__:  # imported as `agent.agent` (repo-root tooling, the benchmarks)
    from . import observability, settings
else:  # run as a flat script: `python agent.py start` (the container layout)
    import observability
    import settings

# Initialise Sentry as early as possible so startup errors reach Sentry: after
# `settings` has loaded a local .env (that file may carry SENTRY_DSN) and before
# the SDK shims below are imported, exactly as the single-module version did.
# If SENTRY_DSN is unset, this is an immediate no-op.
observability.init_sentry()

if __package__:
    from . import (
        backend,
        dialogue,
        livekit_compat,
        prompts,
        session,
        speech,
        turn_handling,
        turn_metrics,
        turn_tracker,
        worker,
    )
else:
    import backend
    import dialogue
    import livekit_compat
    import prompts
    import session
    import speech
    import turn_handling
    import turn_metrics
    import turn_tracker
    import worker

# The names below lived in this module for the whole life of the project, and the
# test suite, the benchmarks and the image smoke check import them from here.
# They are bindings to the implementations above, not copies of them:
# `agent.create_stt` *is* `speech.create_stt`. What this facade does not do is
# make them the seam a test should patch — a module's own globals are where a
# function looks a name up, so `session.create_stt` is the seam that decides what
# `entrypoint` actually calls.

logger = observability.logger
init_sentry = observability.init_sentry
set_session_tags = observability.set_session_tags
capture_exception = observability.capture_exception
scrub_event = observability.scrub_event
is_sentry_enabled = observability.is_sentry_enabled

_env_num = settings._env_num
_env_int = settings._env_int
_env_float = settings._env_float
_env_str = settings._env_str
_env_bool = settings._env_bool
_env_per_language = settings._env_per_language

LANGUAGE_NAMES = prompts.LANGUAGE_NAMES
_language_code = prompts._language_code
build_greeting = prompts.build_greeting
build_system_prompt = prompts.build_system_prompt

_InternalResponse = backend._InternalResponse
_post_internal = backend._post_internal
report_session_failure = backend.report_session_failure
report_session_turn = backend.report_session_turn

to_ms = turn_metrics.to_ms
extract_user_transcript = turn_metrics.extract_user_transcript
compute_total_turnaround = turn_metrics.compute_total_turnaround
build_turn_payload = turn_metrics.build_turn_payload
build_cloudwatch_turn_log = turn_metrics.build_cloudwatch_turn_log
extract_metric_info = turn_metrics.extract_metric_info

TurnMetricsTracker = turn_tracker.TurnMetricsTracker

CARTESIA_VOICES = speech.CARTESIA_VOICES
DEEPGRAM_AURA_VOICES = speech.DEEPGRAM_AURA_VOICES
OPENAI_VOICES = speech.OPENAI_VOICES
create_stt = speech.create_stt
create_tts = speech.create_tts

KNOWN_DIALOGUE_PROVIDERS = dialogue.KNOWN_DIALOGUE_PROVIDERS
SLOW_DIALOGUE_PROVIDERS = dialogue.SLOW_DIALOGUE_PROVIDERS
ALLOW_SLOW_DIALOGUE_ENV = dialogue.ALLOW_SLOW_DIALOGUE_ENV
LLM_REASONING_EFFORT_ENV = dialogue.LLM_REASONING_EFFORT_ENV
assert_dialogue_provider_is_suitable = dialogue.assert_dialogue_provider_is_suitable
_reasoning_effort_for = dialogue._reasoning_effort_for
create_llm = dialogue.create_llm

build_turn_handling = turn_handling.build_turn_handling

VOICE_MAX_SESSION_SECONDS = session.VOICE_MAX_SESSION_SECONDS
_wait_for_learner = session._wait_for_learner
entrypoint = session.entrypoint
prewarm = session.prewarm

# `WorkerOptions` is part of this module's surface because `build_worker_options`
# returns one and callers assert on the pool it describes; the SDK itself is
# imported, and its absence handled, in `livekit_compat`.
WorkerOptions = livekit_compat.WorkerOptions
build_worker_options = worker.build_worker_options


def main() -> None:
    """Register the worker with LiveKit and run the pool the deployment configured."""
    init_sentry()
    agent_name = _env_str("VOICE_AGENT_NAME", "ai-language-coach")
    logger.info("Registering LiveKit worker with agent_name='%s'", agent_name)
    livekit_compat.cli.run_app(build_worker_options())


if __name__ == "__main__":
    main()
