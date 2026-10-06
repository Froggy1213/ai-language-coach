#!/usr/bin/env python3
"""
Voice Fleet Dispatch Capacity Harness for AI Language Coach.

Drives the EXACT same LiveKit Twirp path as the Laravel backend (plan §2, §5):
1. Mints a short-lived server token (matching LiveKitToken::server() grants & HMAC-SHA256).
2. For each dispatch:
   - RoomService/CreateRoom
   - AgentDispatchService/CreateDispatch (with realistic session metadata)
   - Polls RoomService/ListParticipants until kind == 'AGENT' or timeout (VOICE_FLEET_BUSY)
   - RoomService/DeleteRoom (unless --keep-rooms is passed)
3. Summarises join latency percentiles (p50, p95), success rate, and fleet ceiling per concurrency level.
4. Saves run records to load/results/<utc-timestamp>.json (with zero secrets).
"""

from __future__ import annotations

import argparse
import base64
import concurrent.futures
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
import hashlib
import hmac
import json
import os
from pathlib import Path
import sys
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


@dataclass
class DispatchResult:
    concurrency: int
    repeat_index: int
    dispatch_index: int
    room_name: str
    joined: bool
    join_latency_ms: Optional[float]
    joined_inside_timeout: bool
    outcome: str  # "success", "timeout", "dispatch_rejected_429", "error"
    agent_identity: Optional[str] = None
    error_message: Optional[str] = None


@dataclass
class ConcurrencySummary:
    concurrency: int
    total_dispatched: int
    success_count: int
    timeout_count: int
    rejected_429_count: int
    error_count: int
    success_rate_pct: float
    min_latency_ms: Optional[float]
    p50_latency_ms: Optional[float]
    p95_latency_ms: Optional[float]
    max_latency_ms: Optional[float]
    observed_ceiling: str


def compute_percentiles(latencies: List[float]) -> Tuple[Optional[float], Optional[float]]:
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


def execute_single_dispatch(
    concurrency: int,
    repeat_idx: int,
    dispatch_idx: int,
    base_url: str,
    api_key: str,
    api_secret: str,
    agent_name: str,
    join_timeout_sec: float,
    poll_interval_sec: float,
    hold_sec: float,
    keep_rooms: bool,
    run_id: str,
) -> DispatchResult:
    """Run one end-to-end dispatch cycle for a single room."""
    room_name = f"load-cap-{run_id}-c{concurrency}-r{repeat_idx}-d{dispatch_idx}"
    metadata = {
        "session_id": 90000 + dispatch_idx,
        "grammar_point": "Present Perfect",
        "grammar_point_id": 1,
        "practice_prompt": "Tell me about what you have done today.",
        "target_language": "en",
        "level": "B1",
    }

    t_start = time.perf_counter()
    joined = False
    join_latency_ms: Optional[float] = None
    joined_inside_timeout = False
    agent_id: Optional[str] = None
    outcome = "error"
    err_msg: Optional[str] = None

    try:
        # 1. CreateRoom
        twirp_call(
            base_url,
            "livekit.RoomService/CreateRoom",
            {"name": room_name, "emptyTimeout": 120, "maxParticipants": 2},
            api_key,
            api_secret,
            room_name=room_name,
        )

        # 2. CreateDispatch
        t_dispatch = time.perf_counter()
        try:
            twirp_call(
                base_url,
                "livekit.AgentDispatchService/CreateDispatch",
                {
                    "agentName": agent_name,
                    "room": room_name,
                    "metadata": json.dumps(metadata),
                },
                api_key,
                api_secret,
                room_name=room_name,
            )
        except TwirpError as tw_err:
            if tw_err.status == 429:
                return DispatchResult(
                    concurrency=concurrency,
                    repeat_index=repeat_idx,
                    dispatch_index=dispatch_idx,
                    room_name=room_name,
                    joined=False,
                    join_latency_ms=None,
                    joined_inside_timeout=False,
                    outcome="dispatch_rejected_429",
                    error_message="Twirp 429: Worker capacity exceeded (VOICE_FLEET_BUSY)",
                )
            raise

        # 3. Poll ListParticipants until kind == 'AGENT' or timeout
        deadline = t_dispatch + join_timeout_sec
        while time.perf_counter() < deadline:
            resp = twirp_call(
                base_url,
                "livekit.RoomService/ListParticipants",
                {"room": room_name},
                api_key,
                api_secret,
                room_name=room_name,
            )
            participants = resp.get("participants", [])
            agents = [p for p in participants if p.get("kind") == "AGENT"]
            if agents:
                t_found = time.perf_counter()
                agent_id = agents[0].get("identity")
                join_latency_ms = round((t_found - t_dispatch) * 1000.0, 1)
                joined = True
                joined_inside_timeout = (t_found - t_dispatch) <= join_timeout_sec
                outcome = "success"
                break
            time.sleep(poll_interval_sec)

        if not joined:
            elapsed_ms = round((time.perf_counter() - t_dispatch) * 1000.0, 1)
            outcome = "timeout"
            join_latency_ms = elapsed_ms
            joined_inside_timeout = False
            err_msg = f"Agent failed to join within {join_timeout_sec}s (VOICE_FLEET_BUSY)"

        # 4. Optional hold duration to overlap concurrent sessions
        if joined and hold_sec > 0:
            time.sleep(hold_sec)

    except Exception as exc:
        outcome = "error"
        err_msg = str(exc)
    finally:
        # 5. DeleteRoom (unless --keep-rooms)
        if not keep_rooms:
            try:
                twirp_call(
                    base_url,
                    "livekit.RoomService/DeleteRoom",
                    {"room": room_name},
                    api_key,
                    api_secret,
                    room_name=room_name,
                )
            except Exception:
                pass  # Ignore cleanup errors if room was already removed

    return DispatchResult(
        concurrency=concurrency,
        repeat_index=repeat_idx,
        dispatch_index=dispatch_idx,
        room_name=room_name,
        joined=joined,
        join_latency_ms=join_latency_ms,
        joined_inside_timeout=joined_inside_timeout,
        outcome=outcome,
        agent_identity=agent_id,
        error_message=err_msg,
    )


def run_capacity_sweep(
    concurrency_levels: List[int],
    repeats: int,
    base_url: str,
    api_key: str,
    api_secret: str,
    agent_name: str,
    join_timeout_sec: float,
    poll_interval_sec: float,
    hold_sec: float,
    keep_rooms: bool,
    run_id: str,
) -> Tuple[List[DispatchResult], Dict[str, ConcurrencySummary]]:
    """Run sweep across all specified concurrency levels."""
    all_results: List[DispatchResult] = []
    summaries: Dict[str, ConcurrencySummary] = {}

    print("\n" + "=" * 78)
    print("  LIVEKIT VOICE FLEET CAPACITY BENCHMARK")
    print("=" * 78)
    print(f"Target LiveKit URL : {base_url}")
    print(f"Agent Name         : {agent_name}")
    print(f"Join Timeout       : {join_timeout_sec}s")
    print(f"Poll Interval      : {poll_interval_sec}s")
    print(f"Concurrent Hold    : {hold_sec}s")
    print(f"Keep Rooms         : {keep_rooms}")
    print(f"Concurrency Sweep  : {concurrency_levels}")
    print(f"Repeats per Level  : {repeats}")
    print("=" * 78 + "\n")

    for concurrency in concurrency_levels:
        print(f"\n[>>> Starting Concurrency Level: {concurrency} (workers: {concurrency}) <<<]")
        level_results: List[DispatchResult] = []

        for r_idx in range(1, repeats + 1):
            if repeats > 1:
                print(f"  --- Repeat {r_idx}/{repeats} ---")

            # Launch concurrency dispatches in parallel
            with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as executor:
                futures = [
                    executor.submit(
                        execute_single_dispatch,
                        concurrency=concurrency,
                        repeat_idx=r_idx,
                        dispatch_idx=d_idx,
                        base_url=base_url,
                        api_key=api_key,
                        api_secret=api_secret,
                        agent_name=agent_name,
                        join_timeout_sec=join_timeout_sec,
                        poll_interval_sec=poll_interval_sec,
                        hold_sec=hold_sec,
                        keep_rooms=keep_rooms,
                        run_id=run_id,
                    )
                    for d_idx in range(1, concurrency + 1)
                ]

                for fut in concurrent.futures.as_completed(futures):
                    res = fut.result()
                    level_results.append(res)
                    all_results.append(res)
                    lat_str = f"{res.join_latency_ms:.1f}ms" if res.join_latency_ms is not None else "N/A"
                    status_flag = "✔ JOINED" if res.joined_inside_timeout else f"✖ {res.outcome.upper()}"
                    print(
                        f"    dispatch #{res.dispatch_index:02d} | room={res.room_name} | "
                        f"latency={lat_str:<9} | {status_flag}"
                    )
                    if res.error_message and res.outcome != "success":
                        print(f"      └─ detail: {res.error_message}")

        # Compute summary for this level
        total = len(level_results)
        successes = [r for r in level_results if r.joined_inside_timeout]
        succ_count = len(successes)
        timeout_count = sum(1 for r in level_results if r.outcome == "timeout")
        rej_count = sum(1 for r in level_results if r.outcome == "dispatch_rejected_429")
        err_count = sum(1 for r in level_results if r.outcome == "error")
        succ_rate = round((succ_count / total) * 100.0, 1) if total > 0 else 0.0

        succ_lats = [r.join_latency_ms for r in successes if r.join_latency_ms is not None]
        min_lat = min(succ_lats) if succ_lats else None
        max_lat = max(succ_lats) if succ_lats else None
        p50, p95 = compute_percentiles(succ_lats)

        if succ_rate == 100.0:
            ceiling_note = (
                f"Ceiling sustained: all {concurrency} concurrent dispatches served within timeout "
                f"(p50={p50}ms, p95={p95}ms)"
            )
        else:
            ceiling_note = (
                f"Fleet saturated: {total - succ_count}/{total} failed or timed out "
                f"(timeout={timeout_count}, rej429={rej_count}, err={err_count})"
            )

        summary = ConcurrencySummary(
            concurrency=concurrency,
            total_dispatched=total,
            success_count=succ_count,
            timeout_count=timeout_count,
            rejected_429_count=rej_count,
            error_count=err_count,
            success_rate_pct=succ_rate,
            min_latency_ms=min_lat,
            p50_latency_ms=p50,
            p95_latency_ms=p95,
            max_latency_ms=max_lat,
            observed_ceiling=ceiling_note,
        )
        summaries[str(concurrency)] = summary

    return all_results, summaries


def print_summary_table(summaries: Dict[str, ConcurrencySummary]) -> None:
    print("\n" + "=" * 90)
    print("  CAPACITY SWEEP SUMMARY TABLE")
    print("=" * 90)
    header = f"{'Concurrency':<12} | {'Dispatched':<10} | {'Success':<8} | {'Failed':<8} | {'Rate':<8} | {'p50 (ms)':<10} | {'p95 (ms)':<10} | {'Status'}"
    print(header)
    print("-" * 90)
    for c_key, sm in summaries.items():
        p50_str = f"{sm.p50_latency_ms:.1f}" if sm.p50_latency_ms is not None else "-"
        p95_str = f"{sm.p95_latency_ms:.1f}" if sm.p95_latency_ms is not None else "-"
        failed = sm.total_dispatched - sm.success_count
        status = "PASS (100%)" if sm.success_rate_pct == 100.0 else f"SATURATED ({sm.success_rate_pct}%)"
        row = (
            f"{sm.concurrency:<12} | {sm.total_dispatched:<10} | {sm.success_count:<8} | "
            f"{failed:<8} | {sm.success_rate_pct:>5.1f}%  | {p50_str:<10} | {p95_str:<10} | {status}"
        )
        print(row)
    print("=" * 90 + "\n")


def main() -> int:
    parser = argparse.ArgumentParser(
        description="LiveKit Voice Fleet Dispatch Capacity Benchmark Harness"
    )
    parser.add_argument(
        "--concurrency",
        type=str,
        default="1,2,4",
        help="Comma-separated concurrency levels to test (default: 1,2,4)",
    )
    parser.add_argument(
        "--join-timeout",
        type=float,
        default=5.0,
        help="Join timeout in seconds matching VOICE_AGENT_JOIN_TIMEOUT (default: 5.0)",
    )
    parser.add_argument(
        "--poll-interval",
        type=float,
        default=0.25,
        help="Participant poll interval in seconds (default: 0.25)",
    )
    parser.add_argument(
        "--hold-sec",
        type=float,
        default=2.0,
        help="Seconds to hold room open to test concurrent fleet load (default: 2.0)",
    )
    parser.add_argument(
        "--repeats",
        type=int,
        default=1,
        help="Number of iterations per concurrency level (default: 1)",
    )
    parser.add_argument(
        "--keep-rooms",
        action="store_true",
        default=False,
        help="Keep test rooms after dispatch instead of deleting them (default: delete)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        default=False,
        help="Print the execution plan and credential check without making calls",
    )
    parser.add_argument(
        "--url",
        type=str,
        default=None,
        help="LiveKit server HTTP URL override (e.g. http://127.0.0.1:7880)",
    )
    parser.add_argument(
        "--agent-name",
        type=str,
        default=None,
        help="Target agent name override (default from env: ai-language-coach)",
    )
    parser.add_argument(
        "--output-dir",
        type=str,
        default="load/results",
        help="Directory to save JSON results (default: load/results)",
    )

    args = parser.parse_args()

    repo_root = Path(__file__).resolve().parent.parent

    # Parse concurrency levels
    try:
        concurrency_levels = [int(c.strip()) for c in args.concurrency.split(",") if c.strip()]
        if not concurrency_levels:
            raise ValueError
    except ValueError:
        print(f"Error: Invalid --concurrency argument: {args.concurrency}. Expected comma-separated integers.")
        return 1

    # Credential discovery
    try:
        api_key, api_secret, base_url, default_agent_name = resolve_credentials(repo_root)
    except ValueError as err:
        print(f"\n[FATAL ERROR] {err}", file=sys.stderr)
        return 1

    if args.url:
        base_url = args.url.rstrip("/")
        if base_url.startswith("ws://"):
            base_url = "http://" + base_url[5:]
        elif base_url.startswith("wss://"):
            base_url = "https://" + base_url[6:]

    agent_name = args.agent_name or default_agent_name

    now_utc = datetime.now(timezone.utc)
    timestamp_str = now_utc.strftime("%Y%m%dT%H%M%SZ")
    run_id = now_utc.strftime("%H%M%S")

    if args.dry_run:
        print("\n" + "=" * 60)
        print("  DRY-RUN PLAN (No network calls made)")
        print("=" * 60)
        print(f"Resolved LiveKit URL : {base_url}")
        print(f"LiveKit API Key      : {api_key} (secret length={len(api_secret)} >= 32: OK)")
        print(f"Agent Name           : {agent_name}")
        print(f"Concurrency Levels   : {concurrency_levels}")
        print(f"Repeats per Level    : {args.repeats}")
        print(f"Join Timeout         : {args.join_timeout}s")
        print(f"Hold Session Sec     : {args.hold_sec}s")
        print(f"Keep Rooms           : {args.keep_rooms}")
        print(f"Output Directory     : {args.output_dir}")
        print(f"Example Room Prefix  : load-cap-{run_id}-c<C>-r<R>-d<D>")
        print("Grants to be minted  : {roomCreate: true, roomList: true, roomAdmin: true, room: <room>}")
        print("=" * 60 + "\n")
        return 0

    # Execute Sweep
    all_results, summaries = run_capacity_sweep(
        concurrency_levels=concurrency_levels,
        repeats=args.repeats,
        base_url=base_url,
        api_key=api_key,
        api_secret=api_secret,
        agent_name=agent_name,
        join_timeout_sec=args.join_timeout,
        poll_interval_sec=args.poll_interval,
        hold_sec=args.hold_sec,
        keep_rooms=args.keep_rooms,
        run_id=run_id,
    )

    # Print Summary Table
    print_summary_table(summaries)

    # Save to JSON
    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)
    json_path = output_dir / f"{timestamp_str}.json"

    export_data = {
        "benchmark_id": f"dispatch_capacity_{timestamp_str}",
        "timestamp_utc": now_utc.isoformat(),
        "target_url": base_url,
        "agent_name": agent_name,
        "join_timeout_sec": args.join_timeout,
        "poll_interval_sec": args.poll_interval,
        "hold_sec": args.hold_sec,
        "keep_rooms": args.keep_rooms,
        "concurrency_levels": concurrency_levels,
        "repeats": args.repeats,
        "summaries": {k: asdict(v) for k, v in summaries.items()},
        "results": [asdict(r) for r in all_results],
    }

    with json_path.open("w", encoding="utf-8") as f:
        json.dump(export_data, f, indent=2)

    print(f"Results successfully saved to: {json_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
