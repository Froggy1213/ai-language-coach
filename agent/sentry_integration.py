import json
import logging
import os
from typing import Any, Dict, Optional

try:
    import sentry_sdk
    HAS_SENTRY = True
except ImportError:
    sentry_sdk = None  # type: ignore
    HAS_SENTRY = False

logger = logging.getLogger("ai-language-coach-agent.sentry")

# Privacy sentinel used to replace sensitive text
REDACTED_SENTINEL = "[REDACTED]"
TRUNCATED_SUFFIX = "... [TRUNCATED]"
MAX_STRING_LENGTH = 256

# PRIVACY IS LOAD-BEARING:
# A learner's transcribed speech is personal data. We send the shape of a failure,
# never the learner's words.
# Any key that can carry learner speech, transcription results, or the practice prompt
# is redacted in event payloads, breadcrumbs, extra context, and stack frame local variables.
SENSITIVE_KEYS = {
    # Core transcript and practice prompt keys (plan §5, GraphQL schema)
    "transcript",
    "practice_prompt",
    "practiceprompt",
    "user_utterance",
    "userutterance",
    "correction",
    "explanation",
    # Agent internals and LiveKit conversation items (agent.py)
    "last_user_transcript",
    "text_content",
    "raw_text_content",
    "user_speech",
    "learner_speech",
    "user_transcript",
    "usertranscript",
    "learner_transcript",
    "user_input",
    "learner_utterance",
    "utterance",
}

_sentry_initialized = False


def _scrub_json_string(s: str, max_string_length: int = MAX_STRING_LENGTH) -> Optional[str]:
    """
    Attempts to detect and scrub embedded JSON objects (such as structured TURN_LATENCY log lines
    or metadata strings) so transcripts inside JSON log messages are redacted before shipping.
    """
    s_trimmed = s.strip()
    # Case 1: Pure JSON object
    if s_trimmed.startswith("{") and s_trimmed.endswith("}"):
        try:
            parsed = json.loads(s_trimmed)
            if isinstance(parsed, dict):
                scrubbed = _scrub_data(parsed, max_string_length)
                return json.dumps(scrubbed)
        except Exception:
            pass

    # Case 2: Prefixed JSON line (e.g. 'TURN_LATENCY {"event": ...}')
    if "{" in s and s_trimmed.endswith("}"):
        idx = s.find("{")
        prefix = s[:idx]
        json_part = s[idx:]
        try:
            parsed = json.loads(json_part)
            if isinstance(parsed, dict):
                scrubbed = _scrub_data(parsed, max_string_length)
                return prefix + json.dumps(scrubbed)
        except Exception:
            pass

    return None


def _scrub_data(data: Any, max_string_length: int = MAX_STRING_LENGTH) -> Any:
    """
    Recursively scrubs dictionary keys and truncates free-form strings.
    - Replaces sensitive keys with REDACTED_SENTINEL.
    - Leaves non-sensitive keys (session IDs, timings, metric names) completely intact.
    - Truncates free-form strings longer than max_string_length.
    """
    if isinstance(data, dict):
        scrubbed = {}
        for k, v in data.items():
            k_normalized = str(k).lower().replace("-", "_").replace(" ", "_")
            if k_normalized in SENSITIVE_KEYS:
                scrubbed[k] = REDACTED_SENTINEL
            else:
                scrubbed[k] = _scrub_data(v, max_string_length)
        return scrubbed
    elif isinstance(data, list):
        return [_scrub_data(item, max_string_length) for item in data]
    elif isinstance(data, tuple):
        return tuple(_scrub_data(item, max_string_length) for item in data)
    elif isinstance(data, str):
        # Check if the string contains a JSON payload that should be scrubbed
        json_scrubbed = _scrub_json_string(data, max_string_length)
        if json_scrubbed is not None:
            data = json_scrubbed
        if len(data) > max_string_length:
            return data[:max_string_length] + TRUNCATED_SUFFIX
        return data
    else:
        return data


def scrub_event(
    event: Optional[Dict[str, Any]],
    hint: Optional[Dict[str, Any]] = None,
    max_string_length: int = MAX_STRING_LENGTH,
) -> Optional[Dict[str, Any]]:
    """
    Sentry before_send callback.
    Guarantees privacy: deletes or redacts any event payload key that can carry
    a transcript or the practice prompt, and truncates over-long free-form strings.
    Leaves the rest of the event (error shape, session tags, latency metrics) intact.
    """
    if event is None:
        return None

    return _scrub_data(event, max_string_length=max_string_length)


def is_sentry_enabled() -> bool:
    """
    Returns True if Sentry SDK is installed and has been successfully initialized.
    """
    global _sentry_initialized
    if not HAS_SENTRY or sentry_sdk is None:
        return False
    return bool(_sentry_initialized)


def init_sentry(force: bool = False) -> bool:
    """
    Initialises Sentry error tracking for the Python voice agent.

    Configuration philosophy:
    - Initialised ONLY when SENTRY_DSN is set and non-empty. When unconfigured,
      this function is an immediate no-op: no network attempt is made and the agent
      behaves identically to its baseline.
    - We use SENTRY_DSN for the agent. In backend/config/sentry.php, SENTRY_LARAVEL_DSN
      is an override specifically for Laravel, with SENTRY_DSN as the general DSN.
      For the Python agent, SENTRY_DSN is the dedicated and standard variable.
    - environment is derived from SENTRY_ENVIRONMENT, falling back to APP_ENV,
      defaulting to 'local'.
    - release is derived from SENTRY_RELEASE (or None if unset).
    - traces_sample_rate defaults to 0.0 (errors only, which is the honest default
      until someone chooses to pay for traces), overridable via SENTRY_TRACES_SAMPLE_RATE.
    - send_default_pii is explicitly False.
    - before_send is wired to the privacy scrubber (scrub_event).
    """
    global _sentry_initialized

    if _sentry_initialized and not force:
        return True

    dsn = (os.getenv("SENTRY_DSN") or "").strip()
    if not dsn:
        _sentry_initialized = False
        logger.debug("SENTRY_DSN is not configured; Sentry error tracking disabled")
        return False

    if not HAS_SENTRY or sentry_sdk is None:
        logger.warning("sentry-sdk is not installed; cannot initialize Sentry despite SENTRY_DSN being set")
        _sentry_initialized = False
        return False

    environment = os.getenv("SENTRY_ENVIRONMENT") or os.getenv("APP_ENV") or "local"
    release = os.getenv("SENTRY_RELEASE") or None

    raw_traces = os.getenv("SENTRY_TRACES_SAMPLE_RATE")
    if raw_traces is not None and raw_traces.strip():
        try:
            traces_sample_rate = float(raw_traces.strip())
        except ValueError:
            logger.warning("Invalid SENTRY_TRACES_SAMPLE_RATE '%s'; defaulting to 0.0", raw_traces)
            traces_sample_rate = 0.0
    else:
        traces_sample_rate = 0.0

    try:
        sentry_sdk.init(
            dsn=dsn,
            environment=environment,
            release=release,
            traces_sample_rate=traces_sample_rate,
            send_default_pii=False,
            before_send=scrub_event,
        )
        _sentry_initialized = True
        logger.info(
            "Sentry initialized (environment: %s, release: %s, traces_sample_rate: %s)",
            environment,
            release,
            traces_sample_rate,
        )
        return True
    except Exception as exc:
        logger.error("Failed to initialize Sentry: %s", exc)
        _sentry_initialized = False
        return False


def set_session_tags(
    session_id: Optional[int] = None,
    room_name: Optional[str] = None,
    grammar_point: Optional[str] = None,
    target_language: Optional[str] = None,
) -> None:
    """
    Tags the current session context with diagnostic metadata:
    - voice_session_id
    - room_name
    - grammar_point
    - target_language
    Never tags learner words or speech transcripts.
    """
    if not is_sentry_enabled():
        return

    tags = {
        "voice_session_id": str(session_id) if session_id is not None else "unknown",
        "room_name": str(room_name or "unknown"),
        "grammar_point": str(grammar_point or "unknown"),
        "target_language": str(target_language or "unknown"),
    }

    try:
        if hasattr(sentry_sdk, "set_tag"):
            for k, v in tags.items():
                sentry_sdk.set_tag(k, v)
    except Exception as err:
        logger.debug("Failed to set Sentry session tags: %s", err)


def capture_exception(
    exc: Optional[BaseException] = None,
    tags: Optional[Dict[str, str]] = None,
    **kwargs: Any,
) -> Optional[str]:
    """
    Captures an exception to Sentry with attached session context tags.
    If Sentry is unconfigured or not installed, this is an immediate no-op.
    """
    if not is_sentry_enabled():
        return None

    try:
        if tags and hasattr(sentry_sdk, "isolation_scope"):
            with sentry_sdk.isolation_scope() as scope:
                for k, v in tags.items():
                    if v is not None:
                        scope.set_tag(k, str(v))
                return sentry_sdk.capture_exception(exc, **kwargs)
        elif tags and hasattr(sentry_sdk, "push_scope"):
            with sentry_sdk.push_scope() as scope:
                for k, v in tags.items():
                    if v is not None:
                        scope.set_tag(k, str(v))
                return sentry_sdk.capture_exception(exc, **kwargs)
        else:
            if tags and hasattr(sentry_sdk, "set_tag"):
                for k, v in tags.items():
                    if v is not None:
                        sentry_sdk.set_tag(k, str(v))
            return sentry_sdk.capture_exception(exc, **kwargs)
    except Exception as err:
        logger.warning("Failed to capture exception to Sentry: %s", err)
        return None
