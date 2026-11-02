"""
How the worker reads its configuration from the environment.

Every setting in the package is read through these helpers and nowhere else, so
the two rules that keep a misconfigured worker readable hold for all of it:
a blank variable means "not configured" rather than an empty value, and a typo
keeps the default and says so in the log instead of crashing a live call.
"""

import os
from typing import Callable, Optional, TypeVar

try:
    from dotenv import load_dotenv

    # Load a local .env when the worker is run natively. In the container every
    # variable comes from Compose and the file is excluded from the image.
    load_dotenv()
except ImportError:
    pass

if __package__:  # imported as `agent.settings`
    from .observability import logger
    from .prompts import _language_code
else:  # run as a flat script: `python agent.py`, the container layout, the tests
    from observability import logger
    from prompts import _language_code

T = TypeVar("T")


def _env_num(name: str, default: T, cast: Callable[[str], T], type_label: str) -> T:
    """Read a numeric setting, keeping the default and saying so on a typo."""
    raw = os.getenv(name, "").strip()
    if raw == "":
        return default
    try:
        return cast(raw)
    except (ValueError, TypeError):
        logger.warning("Ignoring %s=%r: not %s", name, raw, type_label)
        return default


def _env_int(name: str, default: int) -> int:
    """Read an integer setting, keeping the default and saying so on a typo."""
    return _env_num(name, default, int, "an integer")


def _env_float(name: str, default: float) -> float:
    """Read a numeric setting, keeping the default and saying so on a typo."""
    return _env_num(name, default, float, "a number")


def _env_str(name: str, default: Optional[str] = "") -> Optional[str]:
    """
    Read a string setting, treating blank as absent.

    `os.getenv(name, default)` returns the default only when the variable is
    *unset*, and Docker Compose sets every variable it forwards — `CARTESIA_MODEL:
    ${CARTESIA_MODEL:-}` puts an empty string in the container. With `getenv`'s
    default that empty string was passed on as the model name, which the provider
    rejects at the first turn instead of at startup. Blank means "not configured"
    everywhere in the worker.
    """
    raw = (os.getenv(name) or "").strip()
    return raw if raw else default


def _env_bool(name: str, default: bool) -> bool:
    """Read a flag, accepting the spellings people actually write in a .env."""
    raw = os.getenv(name, "").strip().lower()
    if raw == "":
        return default
    if raw in {"1", "true", "yes", "on"}:
        return True
    if raw in {"0", "false", "no", "off"}:
        return False
    logger.warning("Ignoring %s=%r: not a boolean", name, raw)
    return default


def _env_per_language(name: str, language: str, default: str = "") -> str:
    """
    Read a setting that may be specialised per target language.

    A voice is not a language-neutral asset — the Cartesia voice ids are English
    voices, and the Deepgram Aura model name ends in its language — so a second
    language needs a second voice. `CARTESIA_VOICE` remains the general setting,
    and `CARTESIA_VOICE_DE` overrides it for German. Falls back to `default` when
    neither is set, and the caller decides whether that is acceptable.
    """
    code = _language_code(language)

    for key in (f"{name}_{code.upper()}", f"{name}_{code}"):
        value = (os.getenv(key) or "").strip()
        if value:
            return value

    return (os.getenv(name) or "").strip() or default


__all__ = [
    "T",
    "_env_bool",
    "_env_float",
    "_env_int",
    "_env_num",
    "_env_per_language",
    "_env_str",
    "logger",
]
