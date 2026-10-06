#!/usr/bin/env bash
# ==============================================================================
# /opt/livekit/update-livekit.sh
# Idempotent deploy and upgrade script for LiveKit EC2 node.
# Plan §5, §8.
#
# Honours native SIGTERM drain:
# `docker compose stop livekit` signals SIGTERM to livekit-server.
# The server stops accepting new rooms, drains active rooms up to
# stop_grace_period (15 min), and terminates cleanly.
# ==============================================================================

set -euo pipefail

LIVEKIT_DIR="${LIVEKIT_DIR:-/opt/livekit}"
DRAIN_TIMEOUT="${DRAIN_TIMEOUT:-900}"

echo "=== Upgrading LiveKit Server ($(date -u)) ==="
cd "$LIVEKIT_DIR"

if [[ ! -f "docker-compose.yml" || ! -f "livekit.yaml" ]]; then
    echo "Error: Missing configuration files in $LIVEKIT_DIR" >&2
    exit 1
fi

echo "1. Pulling latest container image..."
docker compose pull livekit

echo "2. Sending SIGTERM drain to active LiveKit container (timeout ${DRAIN_TIMEOUT}s)..."
# docker compose stop sends SIGTERM and waits up to stop_grace_period before sending SIGKILL
docker compose stop -t "$DRAIN_TIMEOUT" livekit

echo "3. Starting updated LiveKit server..."
docker compose up -d livekit

echo "4. Probing health endpoint on port 7880..."
HEALTHY=false
for i in {1..30}; do
    if curl -s -f http://127.0.0.1:7880/ > /dev/null 2>&1; then
        HEALTHY=true
        echo "LiveKit server healthy and responding on :7880 after ${i}s"
        break
    fi
    sleep 1
done

if [[ "$HEALTHY" != "true" ]]; then
    echo "Error: LiveKit server health probe timed out after 30s" >&2
    docker compose logs --tail 50 livekit
    exit 1
fi

echo "=== LiveKit Upgrade Succeeded ($(date -u)) ==="
