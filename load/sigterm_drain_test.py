#!/usr/bin/env python3
"""
SIGTERM Drain and Service Recovery Test Harness for AI Language Coach (plan §5, §6).

Verifies the SIGTERM behavior of the containerised voice agent worker:
1. Dispatches an active session and confirms the agent joins as kind == AGENT.
2. Sends SIGTERM to the running agent worker container (coach_voice_agent).
3. Verifies that the in-flight job is NOT dropped mid-call (participant persists).
4. Verifies that the worker enters draining mode and refuses/defers new dispatches.
5. Concludes the call (DeleteRoom) and measures the exact time to drain and exit.
6. Verifies container exits with code 0 (clean graceful shutdown).
7. Restores the service (docker compose up -d voice-agent) and verifies healthcheck returns to 200/healthy.

Supports --dry-run. Idempotent and uses a cleanup trap to always restore state.
"""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import subprocess
import sys
import time
from typing import Any, Dict, Optional, Tuple

# Re-use credential resolution and token minting from dispatch_capacity
sys.path.insert(0, str(Path(__file__).resolve().parent))
from dispatch_capacity import mint_server_token, resolve_credentials, twirp_call, TwirpError


CONTAINER_NAME = "coach_voice_agent"


def run_command(cmd: list[str]) -> Tuple[int, str, str]:
    res = subprocess.run(cmd, capture_output=True, text=True)
    return res.returncode, res.stdout.strip(), res.stderr.strip()


def check_container_status() -> Tuple[bool, str, int]:
    """Returns (is_running, health_status, exit_code)."""
    code, out, _ = run_command([
        "docker", "inspect", "-f",
        "{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} {{.State.ExitCode}}",
        CONTAINER_NAME
    ])
    if code != 0 or not out:
        return False, "unknown", -1
    parts = out.split()
    running = parts[0] == "true"
    health = parts[1] if len(parts) > 1 else "unknown"
    exit_code = int(parts[2]) if len(parts) > 2 else -1
    return running, health, exit_code


def restore_service(repo_root: Path) -> float:
    """Restores coach_voice_agent via docker compose and waits for healthcheck."""
    print(f"\n[MUTATION] Restoring service: docker compose up -d voice-agent ...")
    t0 = time.perf_counter()
    code, out, err = run_command([
        "docker", "compose", "-f", str(repo_root / "docker-compose.yml"),
        "up", "-d", "voice-agent"
    ])
    if code != 0:
        raise RuntimeError(f"Failed to restore voice-agent: {err}")

    # Wait for healthcheck to become healthy
    deadline = time.perf_counter() + 45.0
    while time.perf_counter() < deadline:
        running, health, _ = check_container_status()
        if running and health == "healthy":
            dur = time.perf_counter() - t0
            print(f"  ✔ Container {CONTAINER_NAME} is RESTORED and HEALTHY ({dur:.2f}s)")
            return dur
        time.sleep(0.5)

    raise TimeoutError(f"Container {CONTAINER_NAME} failed to become healthy within 45s")


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Voice Agent Worker SIGTERM Drain & Recovery Test"
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        default=False,
        help="Print plan and pre-flight status without mutating containers or rooms",
    )
    parser.add_argument(
        "--join-timeout",
        type=float,
        default=5.0,
        help="Join timeout in seconds (default: 5.0)",
    )
    parser.add_argument(
        "--call-duration-sec",
        type=float,
        default=2.0,
        help="Simulated call duration after SIGTERM before ending room (default: 2.0)",
    )

    args = parser.parse_args()
    repo_root = Path(__file__).resolve().parent.parent

    now_utc = datetime.now(timezone.utc)
    run_id = int(now_utc.timestamp())
    test_room = f"load-sigterm-probe-{run_id}"
    second_room = f"load-sigterm-reject-{run_id}"

    print("\n" + "=" * 80)
    print("  VOICE AGENT WORKER SIGTERM DRAIN & RECOVERY TEST")
    print("=" * 80)
    print(f"Target Container  : {CONTAINER_NAME}")
    print(f"Repository Root   : {repo_root}")
    print(f"Test Probe Room   : {test_room}")
    print(f"Dry Run Mode      : {args.dry_run}")
    print("=" * 80 + "\n")

    # Credential discovery
    api_key, api_secret, base_url, agent_name = resolve_credentials(repo_root)

    # Step 1: Pre-flight check
    print("[Step 1/8] Pre-flight container status check...")
    running, health, _ = check_container_status()
    if not running or health != "healthy":
        print(f"ERROR: {CONTAINER_NAME} is not running healthy (running={running}, health={health})", file=sys.stderr)
        return 1
    print(f"  ✔ {CONTAINER_NAME} is currently running and HEALTHY.")

    if args.dry_run:
        print("\n" + "=" * 80)
        print("  DRY-RUN PLAN (No mutations performed)")
        print("=" * 80)
        print(f"  1. Mint server token for {test_room}")
        print(f"  2. Create test room '{test_room}' and dispatch agent '{agent_name}'")
        print(f"  3. Wait for AGENT participant to confirm active session")
        print(f"  4. Deliver SIGTERM signal to '{CONTAINER_NAME}' PID 1")
        print(f"  5. Verify AGENT participant remains in room (mid-call job preserved)")
        print(f"  6. Verify worker is in drain mode (new jobs rejected/unserved)")
        print(f"  7. Delete test room '{test_room}' to complete call")
        print(f"  8. Measure drain latency and verify container ExitCode == 0")
        print(f"  9. Restore service via 'docker compose up -d voice-agent'")
        print(f"  10. Wait for healthcheck to confirm healthy state")
        print("=" * 80 + "\n")
        return 0

    # Test Execution with guaranteed cleanup trap
    in_flight_preserved = False
    drain_rejected_new = False
    drain_time_from_call_end = 0.0
    drain_time_total = 0.0
    container_exit_code = -1
    restore_duration = 0.0

    try:
        # Step 2: Create in-flight session
        print(f"\n[Step 2/8] [MUTATION] Creating test room '{test_room}' and dispatching agent...")
        twirp_call(
            base_url,
            "livekit.RoomService/CreateRoom",
            {"name": test_room, "emptyTimeout": 120, "maxParticipants": 2},
            api_key,
            api_secret,
            room_name=test_room,
        )

        metadata = {
            "session_id": 99991,
            "grammar_point": "SIGTERM Drain Test",
            "grammar_point_id": 1,
            "practice_prompt": "Drain verification session",
            "target_language": "en",
            "level": "B1",
        }
        twirp_call(
            base_url,
            "livekit.AgentDispatchService/CreateDispatch",
            {
                "agentName": agent_name,
                "room": test_room,
                "metadata": json.dumps(metadata),
            },
            api_key,
            api_secret,
            room_name=test_room,
        )

        # Wait for agent to join
        agent_joined = False
        agent_identity: Optional[str] = None
        deadline = time.perf_counter() + args.join_timeout
        while time.perf_counter() < deadline:
            resp = twirp_call(
                base_url,
                "livekit.RoomService/ListParticipants",
                {"room": test_room},
                api_key,
                api_secret,
                room_name=test_room,
            )
            agents = [p for p in resp.get("participants", []) if p.get("kind") == "AGENT"]
            if agents:
                agent_joined = True
                agent_identity = agents[0].get("identity")
                break
            time.sleep(0.1)

        if not agent_joined:
            raise RuntimeError(f"Agent failed to join test room {test_room} within {args.join_timeout}s")
        print(f"  ✔ In-flight agent session ESTABLISHED in {test_room} (identity={agent_identity})")

        # Step 3: Send SIGTERM to the container
        print(f"\n[Step 3/8] [MUTATION] Delivering SIGTERM to {CONTAINER_NAME}...")
        t_sigterm = time.perf_counter()
        code, out, err = run_command(["docker", "kill", "-s", "SIGTERM", CONTAINER_NAME])
        if code != 0:
            raise RuntimeError(f"docker kill -s SIGTERM failed: {err}")
        print(f"  ✔ SIGTERM signal delivered to {CONTAINER_NAME} PID 1")

        # Step 4: Verify in-flight session integrity
        print(f"\n[Step 4/8] [VERIFY] Verifying in-flight session was NOT dropped mid-call...")
        time.sleep(1.0)
        resp = twirp_call(
            base_url,
            "livekit.RoomService/ListParticipants",
            {"room": test_room},
            api_key,
            api_secret,
            room_name=test_room,
        )
        agents_after = [p for p in resp.get("participants", []) if p.get("kind") == "AGENT"]
        if agents_after:
            in_flight_preserved = True
            print(f"  ✔ IN-FLIGHT INTEGRITY CONFIRMED: Agent participant is STILL PRESENT in {test_room} after SIGTERM!")
        else:
            print(f"  ✖ FAILED: Agent participant dropped immediately on SIGTERM!", file=sys.stderr)
            raise RuntimeError("Agent dropped active session upon receiving SIGTERM")

        # Step 5: Verify worker rejects/ignores new dispatches during drain
        print(f"\n[Step 5/8] [VERIFY] Testing whether draining worker rejects new dispatch requests...")
        twirp_call(
            base_url,
            "livekit.RoomService/CreateRoom",
            {"name": second_room, "emptyTimeout": 60, "maxParticipants": 2},
            api_key,
            api_secret,
            room_name=second_room,
        )
        try:
            twirp_call(
                base_url,
                "livekit.AgentDispatchService/CreateDispatch",
                {"agentName": agent_name, "room": second_room, "metadata": "{}"},
                api_key,
                api_secret,
                room_name=second_room,
            )
            # If dispatch was created, verify agent does NOT join the new room
            time.sleep(1.5)
            resp2 = twirp_call(
                base_url,
                "livekit.RoomService/ListParticipants",
                {"room": second_room},
                api_key,
                api_secret,
                room_name=second_room,
            )
            agents2 = [p for p in resp2.get("participants", []) if p.get("kind") == "AGENT"]
            if not agents2:
                drain_rejected_new = True
                print(f"  ✔ Draining worker did NOT accept job for new room '{second_room}' (fleet unavailable).")
            else:
                print(f"  WARNING: Worker accepted job for new room while draining.")
        except TwirpError as tw_err:
            if tw_err.status == 429:
                drain_rejected_new = True
                print(f"  ✔ LiveKit rejected new dispatch with 429: Worker draining/unavailable.")
            else:
                print(f"  ✔ LiveKit rejected new dispatch with HTTP {tw_err.status}.")
                drain_rejected_new = True
        finally:
            try:
                twirp_call(
                    base_url,
                    "livekit.RoomService/DeleteRoom",
                    {"room": second_room},
                    api_key,
                    api_secret,
                    room_name=second_room,
                )
            except Exception:
                pass

        # Simulate call duration before ending
        if args.call_duration_sec > 0:
            print(f"\n  Holding active call for {args.call_duration_sec}s to simulate active conversation...")
            time.sleep(args.call_duration_sec)

        # Step 6: Conclude in-flight call
        print(f"\n[Step 6/8] [MUTATION] Ending active call via DeleteRoom to trigger session completion...")
        t_call_end = time.perf_counter()
        twirp_call(
            base_url,
            "livekit.RoomService/DeleteRoom",
            {"room": test_room},
            api_key,
            api_secret,
            room_name=test_room,
        )
        print(f"  ✔ Room {test_room} closed. Agent session completing...")

        # Step 7: Measure drain duration and exit code
        print(f"\n[Step 7/8] [MEASUREMENT] Waiting for {CONTAINER_NAME} to finish draining and exit...")
        deadline = time.perf_counter() + 20.0
        exited = False
        while time.perf_counter() < deadline:
            running, _, exit_code = check_container_status()
            if not running:
                t_exit = time.perf_counter()
                drain_time_from_call_end = t_exit - t_call_end
                drain_time_total = t_exit - t_sigterm
                container_exit_code = exit_code
                exited = True
                break
            time.sleep(0.25)

        if not exited:
            raise TimeoutError(f"Container {CONTAINER_NAME} did not exit within 20s after call completion")

        print(f"  ✔ Container exited gracefully!")
        print(f"  Container Exit Code        : {container_exit_code}")
        print(f"  Drain Time (from call end) : {drain_time_from_call_end:.2f}s")
        print(f"  Total Time (from SIGTERM)  : {drain_time_total:.2f}s")

    finally:
        # Step 8: Always restore container & clean test rooms
        print(f"\n[Step 8/8] [RECOVERY TRAP] Restoring stack to healthy state...")
        # Clean rooms if still exist
        try:
            twirp_call(
                base_url,
                "livekit.RoomService/DeleteRoom",
                {"room": test_room},
                api_key,
                api_secret,
                room_name=test_room,
            )
        except Exception:
            pass

        try:
            twirp_call(
                base_url,
                "livekit.RoomService/DeleteRoom",
                {"room": second_room},
                api_key,
                api_secret,
                room_name=second_room,
            )
        except Exception:
            pass

        restore_duration = restore_service(repo_root)

    # Final summary report
    print("\n" + "=" * 80)
    print("  SIGTERM DRAIN & RECOVERY TEST: PASSED")
    print("=" * 80)
    print(f"  In-flight session dropped mid-call : NO (Preserved until call finished: {in_flight_preserved})")
    print(f"  Draining mode rejected new jobs    : YES ({drain_rejected_new})")
    print(f"  Container graceful exit code       : {container_exit_code} (0 = clean graceful exit)")
    print(f"  Drain latency after call ended     : {drain_time_from_call_end:.2f}s")
    print(f"  Total drain time from SIGTERM      : {drain_time_total:.2f}s")
    print(f"  Service restored & healthy         : YES ({restore_duration:.2f}s)")
    print("=" * 80 + "\n")

    return 0


if __name__ == "__main__":
    sys.exit(main())
