"""
How the agent decides the learner has finished their turn.

This is a configuration surface with its own rules, and it is separated from
session wiring because the rules are easy to break by "improving" them: most of
what a review would suggest here is already the SDK default, and naming a default
can make end-of-utterance detection *worse*.
"""

from typing import Any, Dict

if __package__:  # imported as `agent.turn_handling`
    from .settings import _env_bool, _env_float, _env_str
else:  # run as a flat script: `python agent.py`, the container layout, the tests
    from settings import _env_bool, _env_float, _env_str


def build_turn_handling() -> Dict[str, Any]:
    """
    How the agent decides the learner has finished their turn.

    Read this before "improving" it: most of what a review would suggest here is
    already the default, and the defaults are easy to make *worse* by naming them.

    1. **Turn detection is already on, and already local.** `AgentSession`
       defaults to `inference.TurnDetector()`, which on a self-hosted node
       resolves to the on-device `turn-detector-v1-mini` model. Those weights
       ship inside the `livekit-local-inference` wheel (34 MB, loads in ~70 ms
       with no network access), so end-of-utterance has always been semantic
       rather than a bare silence timer. Nothing needs installing or
       downloading, and there is no LiveKit Cloud dependency: the cloud `v1`
       detector is only chosen when the process is running hosted or in dev
       mode. Passing `TURN_DETECTION=off` is the escape hatch, not the fix.

    2. **Endpointing: `dynamic`, at the streaming defaults.** With a streaming
       detector attached, livekit-agents 1.8.5 already tightens the fixed
       endpointing defaults from min 0.5 s / max 3.0 s to **min 0.3 s / max
       2.5 s** (`_STREAMING_ENDPOINTING_DEFAULTS`). What it still does is wait
       that fixed 0.3 s of silence whatever was said. `dynamic` lets the
       end-of-turn probability stretch or shorten the wait, which is what the
       detector is for. The numbers below therefore repeat the tighter defaults
       rather than the looser legacy ones — naming 0.5 here would have made the
       agent *less* responsive than leaving the setting alone.

    3. **Preemptive generation** is on by default in 1.8.5: the LLM starts on
       the interim transcript and is cancelled if the learner keeps talking,
       taking the model off the critical path on the turns where the interim was
       right. Kept as an explicit setting so a slow provider can be given less
       rope (`PREEMPTIVE_GENERATION=false`) without a code change.

    Returned as a plain mapping because `turn_handling` is a TypedDict that
    `AgentSession` reads with `dict.get`, so no import is needed for it — which
    also keeps this function importable on a host without livekit-agents.
    """
    endpointing: Dict[str, Any] = {
        # `_env_str` rather than a bare `or`: a forwarded-but-empty variable is
        # normal in Docker Compose and must not become an empty mode name.
        "mode": _env_str("ENDPOINTING_MODE", "dynamic").lower(),
        # Defaults mirror _STREAMING_ENDPOINTING_DEFAULTS, not _ENDPOINTING_DEFAULTS.
        "min_delay": _env_float("ENDPOINTING_MIN_DELAY_SECONDS", 0.3),
        "max_delay": _env_float("ENDPOINTING_MAX_DELAY_SECONDS", 2.5),
    }

    turn_handling: Dict[str, Any] = {
        "endpointing": endpointing,
        "preemptive_generation": {
            "enabled": _env_bool("PREEMPTIVE_GENERATION", True),
        },
    }

    if not _env_bool("TURN_DETECTION", True):
        # `"vad"` is the SDK's own name for "silence timer only, no semantic
        # detector" — not `None`, which the type does not accept.
        turn_handling["turn_detection"] = "vad"

    return turn_handling


__all__ = ["build_turn_handling"]
