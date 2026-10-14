#!/usr/bin/env bash
# ==============================================================================
# scripts/verify-images.sh
#
# Builds the three production images and boots two of them.
#
# The application's own suites run against a *source checkout* — composer
# install, npm ci, php artisan test — so none of them can see a defect that only
# exists inside an image: a build-context exclusion that removed a file the
# runtime needs, a missing PHP extension, an `artisan` call that fails because the
# image has no `.env`, a Nitro server that starts on the wrong host. Every one of
# those has happened in this repository at least once, and every one of them was
# invisible until the image was built and run.
#
# `backend/Dockerfile` is the image ECS deploys for the API, the Horizon worker,
# the Reverb server and the migration task, so a build failure here is a failed
# deployment, not a broken test.
#
# Usage:  ./scripts/verify-images.sh [--only api|web|agent] [--no-build]
#
# Exit code 0 means: all three images built, and the two runnable ones answered
# their health endpoint from inside the container.
# ==============================================================================

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${IMAGE_TAG:-verify}"
PREFIX="${NAME_PREFIX:-ai-language-coach}"
BOOT_TIMEOUT="${BOOT_TIMEOUT:-90}"
ONLY=""
BUILD=true

while [[ $# -gt 0 ]]; do
    case "$1" in
        --only)
            ONLY="${2:-}"
            shift 2
            ;;
        --no-build)
            BUILD=false
            shift
            ;;
        -h|--help)
            sed -n '2,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Error: unknown argument '$1'" >&2
            exit 1
            ;;
    esac
done

# Containers to remove on exit, however this script ends.
STARTED=()

cleanup() {
    if [[ ${#STARTED[@]} -gt 0 ]]; then
        docker rm -f "${STARTED[@]}" > /dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

say() { printf '\n=== %s\n' "$*"; }

# Publishes the container's port on an ephemeral loopback host port and prints it.
# A fixed host port would collide with the developer's own `--profile full` stack.
published_port() {
    local container="$1" container_port="$2" mapping

    mapping="$(docker port "$container" "${container_port}/tcp" | head -1)"
    printf '%s' "${mapping##*:}"
}

# Polls a URL until it answers anything below 500, or the timeout expires.
wait_for_http() {
    local name="$1" url="$2" deadline=$((SECONDS + BOOT_TIMEOUT)) code=""

    while (( SECONDS < deadline )); do
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$url" || true)"

        if [[ "$code" =~ ^[123] ]]; then
            printf '%-14s %s -> %s\n' "$name" "$url" "$code"
            return 0
        fi

        sleep 2
    done

    echo "FAIL: $name did not answer $url within ${BOOT_TIMEOUT}s (last status: ${code:-none})" >&2
    docker logs --tail 40 "$name" >&2 || true
    return 1
}

should_run() {
    [[ -z "$ONLY" || "$ONLY" == "$1" ]]
}

# ------------------------------------------------------------------------------
# Images
# ------------------------------------------------------------------------------
if [[ "$BUILD" == true ]]; then
    for target in api web agent; do
        should_run "$target" || continue
        say "Building ${PREFIX}-${target}:${TAG}"

        case "$target" in
            api) context="$REPO_ROOT/backend" ;;
            web) context="$REPO_ROOT/frontend" ;;
            agent) context="$REPO_ROOT/agent" ;;
        esac

        docker build -t "${PREFIX}-${target}:${TAG}" "$context"
    done
fi

# ------------------------------------------------------------------------------
# API image: nginx + php-fpm behind supervisord, `/up` is Laravel's health route.
#
# Deliberately booted with APP_ENV=production and nothing else, because that is
# how ECS boots it: no `.env` in the image, every value from the environment.
# ProductionSanityCheck refuses to start on development defaults, so the keys
# below are the minimum a real deployment must supply — and this run is what
# proves the image can actually accept them.
# ------------------------------------------------------------------------------
if should_run api; then
    say "Booting the API image"

    api_container="coach_verify_api"
    docker rm -f "$api_container" > /dev/null 2>&1 || true

    docker run -d --name "$api_container" \
        -p 127.0.0.1::8080 \
        -e APP_ENV=production \
        -e APP_DEBUG=false \
        -e "APP_KEY=base64:$(openssl rand -base64 32)" \
        -e SESSION_SECURE_COOKIE=true \
        -e CORS_ALLOWED_ORIGINS=https://verify.example \
        "${PREFIX}-api:${TAG}" > /dev/null

    STARTED+=("$api_container")

    wait_for_http "$api_container" "http://127.0.0.1:$(published_port "$api_container" 8080)/up"

    # The image carries the GraphQL schema, so the route that serves it must be
    # there too — a build-context exclusion of graphql/ would only surface here.
    graphql_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 \
        -X POST "http://127.0.0.1:$(published_port "$api_container" 8080)/graphql" \
        -H 'Content-Type: application/json' \
        -d '{"query":"{ __typename }"}')"

    if [[ "$graphql_code" != "200" ]]; then
        echo "FAIL: POST /graphql answered $graphql_code, expected 200" >&2
        exit 1
    fi

    echo "api            POST /graphql -> 200"
fi

# ------------------------------------------------------------------------------
# Web image: the Nitro server, which must bind 0.0.0.0 (the image sets NITRO_HOST)
# or nothing outside the container can reach it.
# ------------------------------------------------------------------------------
if should_run web; then
    say "Booting the web image"

    web_container="coach_verify_web"
    docker rm -f "$web_container" > /dev/null 2>&1 || true

    docker run -d --name "$web_container" \
        -p 127.0.0.1::3000 \
        -e NUXT_PUBLIC_BACKEND_URL=http://127.0.0.1:8000 \
        "${PREFIX}-web:${TAG}" > /dev/null

    STARTED+=("$web_container")

    wait_for_http "$web_container" "http://127.0.0.1:$(published_port "$web_container" 3000)/"

    # The SPA shell has to carry the runtime config it was started with: the
    # server-rendered payload is where a built Nuxt image puts NUXT_PUBLIC_*.
    body="$(curl -s --max-time 5 "http://127.0.0.1:$(published_port "$web_container" 3000)/")"

    if ! grep -q '127.0.0.1:8000' <<< "$body"; then
        echo "FAIL: the served shell does not carry NUXT_PUBLIC_BACKEND_URL" >&2
        exit 1
    fi

    echo "web            runtime config present in the served shell"
fi

# ------------------------------------------------------------------------------
# Agent image: a worker registers with LiveKit at start-up, so booting it here
# would need a live server and API keys. Importing it is the honest static check:
# it proves the SDK and the worker modules are installed in the image.
# ------------------------------------------------------------------------------
if should_run agent; then
    say "Checking the agent image"

    docker run --rm --entrypoint python "${PREFIX}-agent:${TAG}" -c \
        "import livekit.agents, aiohttp, agent; print('agent imports ok: livekit-agents', livekit.agents.__version__)"
fi

say "All requested images verified"
