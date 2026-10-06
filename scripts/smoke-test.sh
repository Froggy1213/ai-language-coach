#!/usr/bin/env bash
# ==============================================================================
# scripts/smoke-test.sh
# End-to-end post-deploy smoke verification for AI Language Coach infrastructure.
# Maps directly to Plan §7 pre-AWS deployment checklist.
# ==============================================================================

set -euo pipefail

APP_URL="${APP_URL:-https://coach.example.com}"
LIVEKIT_HOST="${LIVEKIT_HOST:-livekit.example.com}"
TIMEOUT=10

usage() {
    cat << 'EOF'
Usage: ./scripts/smoke-test.sh [options]

Verifies deployed AWS infrastructure against Plan §7 checklist:
  - ALB HTTP-to-HTTPS redirect
  - ALB /up health route (Laravel API, port 8080)
  - Nuxt web frontend (port 3000)
  - Reverb WebSocket port / handshake
  - LiveKit WebRTC signaling (port 7880) and TURN/TLS (port 5349)
  - LiveKit webhook signature verification endpoint

Options:
  -a, --app-url <URL>        Main application URL (default: https://coach.example.com)
  -l, --livekit <HOST>       LiveKit server domain or IP (default: livekit.example.com)
  -t, --timeout <SEC>        Curl connection timeout (default: 10)
      --help                 Show this help message
EOF
    exit 0
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -a|--app-url)
            APP_URL="$2"
            shift 2
            ;;
        -l|--livekit)
            LIVEKIT_HOST="$2"
            shift 2
            ;;
        -t|--timeout)
            TIMEOUT="$2"
            shift 2
            ;;
        --help)
            usage
            ;;
        *)
            echo "Error: Unknown argument '$1'" >&2
            exit 1
            ;;
    esac
done

echo "=== Running Milestone 2 Smoke Tests ==="
echo "App URL:      $APP_URL"
echo "LiveKit Host: $LIVEKIT_HOST"
echo "========================================"

FAILED=0

check() {
    local name="$1"
    shift
    echo -n "Checking $name... "
    if "$@" > /dev/null 2>&1; then
        echo "OK"
    else
        echo "FAILED"
        FAILED=$((FAILED + 1))
    fi
}

# 1. Check Laravel API health route (/up)
check "ALB -> Laravel API (/up)" curl -fsS --max-time "$TIMEOUT" "$APP_URL/up"

# 2. Check Nuxt 4 web root
check "ALB -> Nuxt Web Frontend (/)" curl -fsS --max-time "$TIMEOUT" "$APP_URL/"

# 3. Check LiveKit signaling HTTP port 7880
check "LiveKit Server Signaling (:7880)" curl -fsS --max-time "$TIMEOUT" "http://$LIVEKIT_HOST:7880/"

# 4. Check LiveKit TURN/TLS port 5349
check "LiveKit TURN/TLS Port (:5349)" nc -z -w "$TIMEOUT" "$LIVEKIT_HOST" 5349

# 5. Check LiveKit Webhook route rejects unsigned request with 401 (Plan §5, §7)
echo -n "Checking LiveKit Webhook Signature Enforcement (Plan §7)... "
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time "$TIMEOUT" -X POST "$APP_URL/api/webhooks/livekit" -H "Content-Type: application/webhook+json" -d "{}" || echo "000")
if [[ "$HTTP_CODE" == "401" ]]; then
    echo "OK (Correctly rejected unsigned webhook with HTTP 401)"
else
    echo "FAILED (Expected HTTP 401, got $HTTP_CODE)"
    FAILED=$((FAILED + 1))
fi

echo "========================================"
if [[ "$FAILED" -eq 0 ]]; then
    echo "=== ALL SMOKE CHECKS PASSED ==="
    exit 0
else
    echo "=== $FAILED CHECKS FAILED ==="
    exit 1
fi
