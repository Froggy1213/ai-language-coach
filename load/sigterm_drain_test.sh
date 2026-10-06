#!/usr/bin/env bash
#
# SIGTERM Drain and Service Recovery Test Harness for AI Language Coach (plan §5, §6).
#
# Verifies that:
# 1. When SIGTERM is sent to the voice-agent worker, an in-flight active job is NOT dropped mid-call.
# 2. The worker enters draining mode and shuts down gracefully once the session ends.
# 3. The container exits with code 0.
# 4. The service is restored via `docker compose up -d voice-agent` and returns to healthy.
#
# Supports --dry-run. Idempotent and uses a trap to guarantee state restoration on failure.
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

exec python3 "${SCRIPT_DIR}/sigterm_drain_test.py" "$@"
