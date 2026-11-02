"""
The worker pool as the deployment configured it (plan §5, §7).

Separate from the session because it is the other end of the same deployment
question: how many calls a process may hold, and when a draining worker stops
taking new ones.
"""

from typing import Any

if __package__:  # imported as `agent.worker`
    from .livekit_compat import WorkerOptions
    from .session import entrypoint, prewarm
    from .settings import _env_float, _env_int, _env_str
else:  # run as a flat script: `python agent.py`, the container layout, the tests
    from livekit_compat import WorkerOptions
    from session import entrypoint, prewarm
    from settings import _env_float, _env_int, _env_str


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
        agent_name=_env_str("VOICE_AGENT_NAME", "ai-language-coach"),
        num_idle_processes=_env_int("NUM_IDLE_PROCESSES", 4),
        load_threshold=_env_float("LOAD_THRESHOLD", 0.7),
        drain_timeout=_env_float("DRAIN_TIMEOUT_SECONDS", 110.0),
    )


__all__ = ["build_worker_options"]
