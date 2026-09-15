# syntax=docker/dockerfile:1

# Production image for the Nuxt 4 app.
#
# `nuxt build` emits a self-contained Nitro server in .output with its server
# dependencies bundled, so the runtime stage needs no node_modules — only Node
# and the build output.
#
# Build:  docker build -t ai-language-coach/web ./frontend
# Run:    docker run --rm -p 3000:3000 \
#           -e NUXT_PUBLIC_BACKEND_URL=https://api.example.com ai-language-coach/web
#
# Runtime config (NUXT_PUBLIC_*, see nuxt.config.ts) is read from real environment
# variables when the container starts; frontend/.env is excluded via .dockerignore
# so a developer's localhost URLs can never be baked into an image.

ARG NODE_VERSION=24

# ---------------------------------------------------------------------------
# Stage 1 — deps: install exactly what the lockfile pins.
# ---------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS deps

WORKDIR /app

# --legacy-peer-deps mirrors how the lockfile was produced: ai-elements-nuxt
# declares a nuxt ^3.0.0 peer while this project is on Nuxt 4 (see README).
COPY package.json package-lock.json ./
RUN npm ci --legacy-peer-deps

# ---------------------------------------------------------------------------
# Stage 2 — build: Nitro server bundle in /app/.output.
# ---------------------------------------------------------------------------
FROM deps AS build

COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3 — runtime.
# ---------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS runtime

ENV NODE_ENV=production \
    NITRO_PORT=3000 \
    NITRO_HOST=0.0.0.0 \
    NUXT_TELEMETRY_DISABLED=1

WORKDIR /app

COPY --from=build --chown=node:node /app/.output ./.output

USER node

EXPOSE 3000

# For `docker run` / compose. In ECS the ALB target group does this check.
HEALTHCHECK --interval=30s --timeout=3s --start-period=15s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:3000/ || exit 1

CMD ["node", ".output/server/index.mjs"]
