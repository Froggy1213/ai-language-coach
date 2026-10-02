#!/usr/bin/env bash
# Local development stand-in for the LiveKit EC2 node in plan §8.
#
# Runs LiveKit natively on the host bound to 127.0.0.1. The API key and secret
# are sourced directly from backend/.env so access tokens minted by Laravel and
# Twirp calls from the backend match the credentials expected by the server.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$REPO_ROOT/backend/.env"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "Error: backend/.env not found at $ENV_FILE" >&2
    exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

# backend/.env sets REDIS_HOST=127.0.0.1 (without a port for Laravel).
# livekit-server maps $REDIS_HOST to its multi-node clustering flag which expects
# host:port. Unset it so the local server runs standalone with single-node routing.
unset REDIS_HOST REDIS_PASSWORD

if [[ -z "${LIVEKIT_API_KEY:-}" ]]; then
    echo "Error: LIVEKIT_API_KEY is not set in $ENV_FILE" >&2
    exit 1
fi

if [[ -z "${LIVEKIT_API_SECRET:-}" ]]; then
    echo "Error: LIVEKIT_API_SECRET is not set in $ENV_FILE" >&2
    exit 1
fi

if [[ ${#LIVEKIT_API_SECRET} -lt 32 ]]; then
    echo "Error: LIVEKIT_API_SECRET must be at least 32 characters (got ${#LIVEKIT_API_SECRET})." >&2
    echo "php-jwt v7 refuses HMAC keys under 256 bits (decision 25). Generate one with: openssl rand -hex 24" >&2
    exit 1
fi
# Webhook target for local room events. Override or disable by setting
# VOICE_WEBHOOK_URL="" (useful for developers not running `php artisan serve`).
VOICE_WEBHOOK_URL="${VOICE_WEBHOOK_URL-http://127.0.0.1:8000/api/webhooks/livekit}"

# livekit-server 1.13.7 has no CLI flag for webhook URLs; it requires a config file.
# Generate a temporary config (mode 600) with credentials and clean it up on exit.
CONF_FILE="$(mktemp -t livekit.conf.XXXXXX)"
chmod 600 "$CONF_FILE"

cleanup() {
    rm -f "$CONF_FILE"
}
trap cleanup EXIT

cat << EOF > "$CONF_FILE"
port: 7880
bind_addresses:
  - 127.0.0.1
keys:
  "${LIVEKIT_API_KEY}": "${LIVEKIT_API_SECRET}"
EOF

if [[ -n "${VOICE_WEBHOOK_URL}" && "${VOICE_WEBHOOK_URL}" != "off" && "${VOICE_WEBHOOK_URL}" != "disabled" ]]; then
    cat << EOF >> "$CONF_FILE"
webhook:
  api_key: "${LIVEKIT_API_KEY}"
  urls:
    - "${VOICE_WEBHOOK_URL}"
EOF
fi

livekit-server \
    --config "$CONF_FILE" \
    --bind 127.0.0.1 \
    --node-ip 127.0.0.1 \
    --keys "$LIVEKIT_API_KEY: $LIVEKIT_API_SECRET" &
SERVER_PID=$!

trap 'kill -TERM "$SERVER_PID" 2>/dev/null || true; cleanup; exit 143' TERM
trap 'kill -INT "$SERVER_PID" 2>/dev/null || true; cleanup; exit 130' INT

wait "$SERVER_PID"
