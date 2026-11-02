"""
Reporting to the internal Laravel API.

The backend is the record of what happened in a call, and the worker is not:
a learner leaving, a provider refusing a stream or a turn finishing only becomes
visible there through the two reports in this module. Both are best-effort — a
conversation must never be interrupted or held open by an unreachable backend —
so this is the one place that owns the internal secret, the URL and the retry
policy for it.
"""

import asyncio
import json
import os
from typing import Any, Dict, NamedTuple, Optional

try:
    import aiohttp
except ImportError:
    aiohttp = None  # type: ignore

if __package__:  # imported as `agent.backend`
    from .observability import capture_exception, logger
else:  # run as a flat script: `python agent.py`, the container layout, the tests
    from observability import capture_exception, logger


class _InternalResponse(NamedTuple):
    """
    Result of an internal HTTP request to the backend.

    Attributes:
        status: HTTP status code returned by the server.
        data: Parsed JSON dictionary if response body was valid JSON, otherwise None.
        text: Raw response body string.
    """

    status: int
    data: Optional[Dict[str, Any]] = None
    text: str = ""


async def _post_internal(
    path: str,
    payload: Dict[str, Any],
    log_context: Optional[str] = None,
) -> Optional[_InternalResponse]:
    """
    Posts a JSON payload to the internal Laravel backend API.

    Handles environment resolution and validation (BACKEND_INTERNAL_URL,
    VOICE_INTERNAL_SECRET), aiohttp library availability check, header
    construction, URL joining with trailing-slash tolerance, session
    management with a 5-second total timeout, and response body / JSON parsing.

    Args:
        path: Relative or absolute route path (e.g. '/api/internal/sessions/1/fail').
        payload: Dict to serialize as JSON.
        log_context: Optional context description for validation warnings
            (e.g. 'failure' or 'turn metrics').

    Returns:
        _InternalResponse(status, data, text) if the HTTP request completed,
        or None if prerequisite checks failed (missing secret or missing aiohttp).

    Raises:
        Exception: Propagates transport/network errors (e.g. connection refused,
            client timeouts) so callers can apply their own retry or error policy.
    """
    backend_url = os.getenv("BACKEND_INTERNAL_URL", "http://host.docker.internal:8000")
    secret = os.getenv("VOICE_INTERNAL_SECRET", "")

    log_suffix = f" {log_context}" if log_context else ""
    if not secret:
        logger.warning("VOICE_INTERNAL_SECRET is unset; cannot report%s to backend", log_suffix)
        return None

    if aiohttp is None:
        logger.warning("aiohttp is not installed; cannot report%s to backend", log_suffix)
        return None

    clean_base = (backend_url or "http://host.docker.internal:8000").rstrip("/")
    clean_path = path if path.startswith("/") else f"/{path}"
    endpoint = f"{clean_base}{clean_path}"

    headers = {
        "X-Internal-Secret": secret,
        "Content-Type": "application/json",
        "Accept": "application/json",
    }

    async with aiohttp.ClientSession() as session:
        async with session.post(
            endpoint,
            json=payload,
            headers=headers,
            timeout=aiohttp.ClientTimeout(total=5),
        ) as response:
            status = response.status
            text = ""
            data = None
            try:
                raw_text = await response.text()
                text = raw_text if isinstance(raw_text, str) else str(raw_text)
            except Exception:
                pass

            if hasattr(response, "json") and callable(response.json):
                try:
                    raw_json = await response.json()
                    if isinstance(raw_json, dict):
                        data = raw_json
                except Exception:
                    pass

            if data is None and text:
                try:
                    parsed = json.loads(text)
                    if isinstance(parsed, dict):
                        data = parsed
                except Exception:
                    pass

            return _InternalResponse(status=status, data=data, text=text)


async def report_session_failure(session_id: Optional[int], reason: str) -> None:
    """
    Reports fatal session errors to Laravel backend (POST /api/internal/sessions/{id}/fail).
    Guarded by VOICE_INTERNAL_SECRET.
    Allowed reasons: ['stt_failed', 'tts_failed', 'llm_failed', 'agent_error'].
    """
    if not session_id:
        return

    valid_reasons = {"stt_failed", "tts_failed", "llm_failed", "agent_error"}
    payload_reason = reason if reason in valid_reasons else "agent_error"
    path = f"/api/internal/sessions/{session_id}/fail"
    payload = {"reason": payload_reason}

    max_retries = 1
    for attempt in range(max_retries + 1):
        try:
            response = await _post_internal(path, payload, log_context="failure")
            if response is None:
                return

            status = response.status
            if 200 <= status < 300:
                logger.info(
                    "Failure reported to backend for session %s (reason: %s): HTTP %s",
                    session_id,
                    payload_reason,
                    status,
                )
                return
            elif status == 404:
                logger.info(
                    "Session %s already terminal on backend (HTTP 404)",
                    session_id,
                )
                return
            elif status >= 500:
                if attempt < max_retries:
                    logger.warning(
                        "Backend error reporting failure for session %s: HTTP %s; retrying...",
                        session_id,
                        status,
                    )
                    await asyncio.sleep(0.5)
                    continue
                logger.error(
                    "Backend error reporting failure for session %s: HTTP %s",
                    session_id,
                    status,
                )
                return
            else:
                logger.warning(
                    "Backend rejected failure report for session %s: HTTP %s",
                    session_id,
                    status,
                )
                return
        except Exception as exc:
            if attempt < max_retries:
                logger.warning(
                    "Retrying failure notification for session %s (attempt %d/%d): %s",
                    session_id,
                    attempt + 1,
                    max_retries + 1,
                    exc,
                )
                await asyncio.sleep(0.5)
                continue
            logger.error("Failed to notify backend about session %s failure: %s", session_id, exc)
            capture_exception(
                exc,
                tags={
                    "voice_session_id": str(session_id) if session_id is not None else "unknown",
                    "failure_path": "backend_report",
                },
            )


async def report_session_turn(session_id: Optional[int], payload: Dict[str, Any]) -> None:
    """
    Reports per-turn latency and transcript to Laravel backend (POST /api/internal/sessions/{id}/turns).
    Guarded by VOICE_INTERNAL_SECRET.
    Never raises exceptions: failures are logged as warnings and do not interrupt conversation.
    """
    if not session_id:
        return

    path = f"/api/internal/sessions/{session_id}/turns"

    try:
        response = await _post_internal(path, payload, log_context="turn metrics")
        if response is None:
            return

        if response.status == 200:
            logger.info(
                "Turn %s latency reported to backend for session %s: HTTP %s",
                payload.get("turn_id"),
                session_id,
                response.status,
            )
        else:
            body = response.text
            logger.warning(
                "Backend rejected turn %s for session %s: HTTP %s: %s",
                payload.get("turn_id"),
                session_id,
                response.status,
                body,
            )
    except Exception as exc:
        logger.warning(
            "Failed to report turn %s for session %s to backend: %s",
            payload.get("turn_id"),
            session_id,
            exc,
        )


__all__ = [
    "_InternalResponse",
    "_post_internal",
    "report_session_failure",
    "report_session_turn",
]
