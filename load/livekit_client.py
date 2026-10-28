"""
Shared LiveKit client primitives for load testing and operational verification.

Provides token generation, credential discovery, Twirp API interaction,
and latency metric percentiles matching the Laravel backend contracts.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import time
from typing import Any, Dict, List, Optional, Tuple
import urllib.error
import urllib.request


def base64url_encode(data: bytes) -> str:
    """Base64url encoding without padding."""
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


def load_env_file(path: Path) -> Dict[str, str]:
    """Parse simple KEY=VALUE pairs from a .env file."""
    env: Dict[str, str] = {}
    if not path.is_file():
        return env
    with path.open("r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            k = k.strip()
            v = v.strip().strip("\"'")
            env[k] = v
    return env


def resolve_credentials(repo_root: Path) -> Tuple[str, str, str, str]:
    """
    Resolve LiveKit credentials from environment variables, falling back to root .env
    and backend/.env. Refuses loudly if credentials are missing or secret is <32 chars.
    """
    root_env = load_env_file(repo_root / ".env")
    backend_env = load_env_file(repo_root / "backend" / ".env")

    api_key = (
        os.getenv("LIVEKIT_API_KEY")
        or root_env.get("LIVEKIT_API_KEY")
        or backend_env.get("LIVEKIT_API_KEY")
        or ""
    )
    api_secret = (
        os.getenv("LIVEKIT_API_SECRET")
        or root_env.get("LIVEKIT_API_SECRET")
        or backend_env.get("LIVEKIT_API_SECRET")
        or ""
    )
    raw_url = (
        os.getenv("LIVEKIT_URL")
        or root_env.get("LIVEKIT_URL")
        or backend_env.get("LIVEKIT_URL")
        or "http://127.0.0.1:7880"
    )
    agent_name = (
        os.getenv("VOICE_AGENT_NAME")
        or root_env.get("VOICE_AGENT_NAME")
        or backend_env.get("VOICE_AGENT_NAME")
        or "ai-language-coach"
    )

    if not api_key:
        raise ValueError(
            "LIVEKIT_API_KEY is missing. Set it in environment, .env, or backend/.env."
        )
    if not api_secret:
        raise ValueError(
            "LIVEKIT_API_SECRET is missing. Set it in environment, .env, or backend/.env."
        )

    # Match LiveKitToken.php: HS256 requires at least 32 characters
    if len(api_secret) < 32:
        raise ValueError(
            f"LIVEKIT_API_SECRET must be at least 32 characters for HS256 (got {len(api_secret)}). "
            "Generate one with `openssl rand -base64 32`."
        )

    # Convert ws:// / wss:// to http:// / https://
    url = raw_url.rstrip("/")
    if url.startswith("ws://"):
        url = "http://" + url[5:]
    elif url.startswith("wss://"):
        url = "https://" + url[6:]

    return api_key, api_secret, url, agent_name


def mint_server_token(
    api_key: str, api_secret: str, room_name: Optional[str] = None, ttl_minutes: int = 15
) -> str:
    """
    Mints LiveKit server token matching App\\Voice\\LiveKitToken::server($room).
    Claims:
      - iss: api_key
      - sub: 'api'
      - video: {roomCreate: true, roomList: true, roomAdmin: true, room: room_name (if provided)}
    """
    now = int(time.time())
    header = {"typ": "JWT", "alg": "HS256"}
    grants: Dict[str, Any] = {
        "roomCreate": True,
        "roomList": True,
        "roomAdmin": True,
    }
    if room_name:
        grants["room"] = room_name

    claims: Dict[str, Any] = {
        "iss": api_key,
        "sub": "api",
        "nbf": now,
        "iat": now,
        "exp": now + ttl_minutes * 60,
        "video": grants,
        "metadata": "",
    }

    enc_header = base64url_encode(json.dumps(header, separators=(",", ":")).encode("utf-8"))
    enc_payload = base64url_encode(json.dumps(claims, separators=(",", ":")).encode("utf-8"))
    signing_input = f"{enc_header}.{enc_payload}".encode("utf-8")
    signature = hmac.new(api_secret.encode("utf-8"), signing_input, hashlib.sha256).digest()
    return f"{enc_header}.{enc_payload}.{base64url_encode(signature)}"


class TwirpError(Exception):
    def __init__(self, status: int, body: str, method: str):
        super().__init__(f"Twirp {method} failed with HTTP {status}: {body}")
        self.status = status
        self.body = body
        self.method = method


def twirp_call(
    base_url: str,
    method: str,
    payload: Dict[str, Any],
    api_key: str,
    api_secret: str,
    room_name: Optional[str] = None,
    timeout_sec: float = 5.0,
) -> Dict[str, Any]:
    """Execute a Twirp POST request to LiveKit server with short-lived bearer token."""
    token = mint_server_token(api_key, api_secret, room_name=room_name)
    url = f"{base_url}/twirp/{method}"
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=data,
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )

    try:
        with urllib.request.urlopen(req, timeout=timeout_sec) as resp:
            content = resp.read().decode("utf-8")
            if not content.strip():
                return {}
            return json.loads(content)
    except urllib.error.HTTPError as err:
        body = err.read().decode("utf-8", errors="replace")
        raise TwirpError(err.code, body, method) from err
    except urllib.error.URLError as err:
        raise RuntimeError(f"Network error connecting to {url}: {err.reason}") from err


def compute_percentiles(latencies: List[float]) -> Tuple[Optional[float], Optional[float]]:
    """Compute 50th (p50) and 95th (p95) percentiles using linear interpolation."""
    if not latencies:
        return None, None
    sorted_lats = sorted(latencies)
    n = len(sorted_lats)

    def get_p(p: float) -> float:
        if n == 1:
            return sorted_lats[0]
        rank = (n - 1) * p
        low = int(rank)
        high = min(low + 1, n - 1)
        weight = rank - low
        return sorted_lats[low] * (1.0 - weight) + sorted_lats[high] * weight

    return round(get_p(0.50), 1), round(get_p(0.95), 1)
