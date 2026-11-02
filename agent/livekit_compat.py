"""
The optional LiveKit SDK surface, imported once.

The worker has to stay importable on a host without the audio stack — the test
suite, the benchmarks and the image smoke check all import it outside the
container — while running the real pipeline inside one. Every LiveKit import
therefore happens here with a fallback, and consumers read the capability flags
(`HAS_LIVEKIT`, `HAS_PLUGINS`, `HAS_OPENAI`, ...) instead of importing the SDK
themselves, so "the SDK is missing" is decided in one place.
"""

from typing import Any

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
    cartesia = None  # type: ignore
    HAS_CARTESIA = False

# Detect the LiveKit Agents API generation: `AgentSession` (1.x) versus the older
# `VoicePipelineAgent` (0.x). The worker runs whichever one is installed, so the
# difference is captured here once rather than at the call site.
try:
    from livekit.agents import Agent, AgentSession

    USE_AGENT_SESSION = True
except ImportError:
    Agent = None  # type: ignore
    AgentSession = None  # type: ignore
    USE_AGENT_SESSION = False

if USE_AGENT_SESSION:
    # The 1.x API is what this worker runs, so the 0.x entrypoint is not imported.
    # The name is still bound, because the fallback branch in `session.py` reads
    # it when it is switched on deliberately (and the test suite patches it).
    VoicePipelineAgent = None  # type: ignore
else:
    try:
        from livekit.agents.pipeline import VoicePipelineAgent
    except ImportError:
        VoicePipelineAgent = None  # type: ignore

__all__ = [
    "Agent",
    "AgentSession",
    "AutoSubscribe",
    "HAS_CARTESIA",
    "HAS_LIVEKIT",
    "HAS_METRICS",
    "HAS_OPENAI",
    "HAS_PLUGINS",
    "JobContext",
    "JobProcess",
    "USE_AGENT_SESSION",
    "VoicePipelineAgent",
    "WorkerOptions",
    "cartesia",
    "cli",
    "deepgram",
    "metrics",
    "openai",
    "silero",
]
