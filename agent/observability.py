"""
Process-wide logging and the Sentry adapter.

One logger and one Sentry integration for the whole worker, so a session's log
lines and its captured exceptions land under the same name and through the same
handler whichever module raised them. `agent.py` initialises Sentry through here
before the rest of the runtime is imported, which is what makes a startup
failure visible in Sentry instead of only in the container log.
"""

import logging

if __package__:  # imported as `agent.observability`
    from .sentry_integration import (
        capture_exception,
        init_sentry,
        is_sentry_enabled,
        scrub_event,
        set_session_tags,
    )
else:  # run as a flat script: `python agent.py`, the container layout, the tests
    from sentry_integration import (
        capture_exception,
        init_sentry,
        is_sentry_enabled,
        scrub_event,
        set_session_tags,
    )

logger = logging.getLogger("ai-language-coach-agent")
logger.setLevel(logging.INFO)
# The worker owns its stdout format: CloudWatch parses the TURN_LATENCY lines
# emitted beside them. Propagating to the root logger as well would print every
# line twice as soon as anything else configures logging.
logger.propagate = False
if not logger.handlers:
    _log_handler = logging.StreamHandler()
    _log_handler.setFormatter(
        logging.Formatter("%(asctime)s [%(levelname)s] %(name)s: %(message)s")
    )
    logger.addHandler(_log_handler)

__all__ = [
    "capture_exception",
    "init_sentry",
    "is_sentry_enabled",
    "logger",
    "scrub_event",
    "set_session_tags",
]
