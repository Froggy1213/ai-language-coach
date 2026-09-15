# AI Language Coach

Голосовой AI-коуч для изучения языков (CEFR A1–C1): онбординг-ассессмент, персональный роадмап, диалоговая практика с голосовым агентом и spaced repetition по ошибкам.

Full development plan: [`ai-language-coach-plan.md`](ai-language-coach-plan.md) (RU, milestones Sept 2026 – Feb 2027).

## Stack

| Layer | Technology |
|---|---|
| Frontend | Nuxt 4 (Vue 3, SPA) + Tailwind CSS 4 |
| Auth | Laravel Sanctum (SPA cookie) |
| API | Laravel 13 + Lighthouse (GraphQL) |
| Realtime | Laravel Reverb (1 instance, V1) |
| Queues | Redis + Laravel Horizon |
| DB | MySQL 8.0 (JSON columns for cheat sheet/transcript) |
| Media | LiveKit Server (self-hosted EC2) |
| Voice agent | Python `livekit-agents` (ECS Fargate) |
| STT / TTS | Deepgram Nova-2 / Cartesia Sonic |
| LLM (async) | DeepSeek-V3 via `laravel/ai` |
| LLM (dialogue) | TBD by first-token-latency benchmark (§6 of plan) |
| Observability | Sentry, Telescope (dev) |

## Dependencies

### Backend (`backend/`, composer)

**Production**

| Package | Version | Purpose |
|---|---|---|
| `laravel/framework` | ^13.17 | Base framework (PHP 8.5) |
| `laravel/sanctum` | ^4.3 | SPA cookie authentication |
| `nuwave/lighthouse` | ^6.70 | GraphQL API at `/graphql` |
| `laravel/reverb` | ^1.11 | WebSocket realtime (Pusher protocol) |
| `laravel/horizon` | ^5.49 | Redis queue dashboard + workers |
| `laravel/ai` | ^0.11 | AI SDK — **DeepSeek provider is built in** |
| `dij-digital/deepgram-laravel` | ^0.1 | Deepgram batch STT (assessment job) |
| `firebase/php-jwt` | ^7.1 | LiveKit access tokens + webhook signing |
| `sentry/sentry-laravel` | ^4.27 | Error tracking (mandatory per plan §1) |

**Development**

| Package | Version | Purpose |
|---|---|---|
| `laravel/telescope` | ^5.24 | Request/query/queue debugging |
| `laravel/boost` | ^2.9 | MCP server for AI-assisted development (required) |
| `pestphp/pest` | ^5.2 | Test framework |
| `pestphp/pest-plugin-laravel` | ^5.0 | Pest ↔ Laravel integration |
| `pestphp/pest-plugin-evals` | ^5.0 | LLM agent evaluation tests |
| `phpunit/phpunit` | ^13.3 | Test engine under Pest (bumped from ^12.5 — Pest 5 requires it) |
| `laravel/pint`, `laravel/pail`, `laravel/pao`, `rector/rector`, `mockery/mockery`, `nunomaduro/collision`, `fakerphp/faker` | — | Pre-existing dev tooling |

### Frontend (`frontend/`, npm)

| Package | Version | Purpose |
|---|---|---|
| `nuxt` | ^4.5 | Framework (SPA mode) |
| `vue` / `vue-router` | ^3.5 / ^5.3 | Pre-existing |
| `@urql/vue` | ^2.1 | GraphQL client (queries, mutations, subscriptions) |
| `graphql` / `graphql-ws` | ^17 / ^6.2 | GraphQL runtime + WebSocket subscriptions |
| `laravel-echo` + `pusher-js` | ^2.5 / ^8.6 | Reverb realtime client |
| `livekit-client` | ^2.22 | WebRTC voice calls (direct to LiveKit server) |
| `ai` | ^7.0 | Vercel AI SDK core |
| `@ai-sdk/vue` | ^4.0 | Vue bindings for the AI SDK |
| `ai-elements-nuxt` | ^1.5 | Headless AI UI components (Nuxt port of Vercel AI Elements) |
| `tailwindcss` + `@tailwindcss/vite` | ^4.3 | Styling (Tailwind v4 via Vite plugin) |

### Not yet installed (planned)

Python voice-agent worker (`livekit-agents`, Deepgram/Cartesia SDKs) — November milestone, separate service, not part of the PHP/Nuxt repos.

## Key decisions & deviations from the plan

1. **LiveKit PHP SDK** (`agence104/livekit-server-sdk`, named in plan §5) is abandoned and uninstallable: all versions pin `firebase/php-jwt` v6 (security advisory) or guzzle ≤7. Instead we use **`firebase/php-jwt` ^7** and will implement the two pieces the plan needs (~50 lines): access-token signing and webhook HMAC verification.
2. **guzzle is pinned at 7.15.5, not 8**: `laravel/reverb` requires `guzzlehttp/psr7 ^2.6`, incompatible with guzzle 8's psr7 3.x. Laravel framework and Boost accept both. Do not bump guzzle to 8 without fixing Reverb compatibility.
3. **Deepgram**: `dij-digital/deepgram-laravel` (Laravel 13 native) instead of the framework-agnostic `aisdk/deepgram`. Fallback if it proves immature: raw REST via guzzle (the API is simple).
4. **GraphQL client**: `@urql/vue` — `@nuxtjs/apollo`'s stable line is an rc from 2020 (v5 still alpha).
5. **`ai-elements-nuxt`** declares a `nuxt ^3.0.0` peer but the project is Nuxt 4.5 — installed with `--legacy-peer-deps`. Smoke-test it before relying on it in UI work.

## Local setup

Prerequisites: PHP 8.5 + Composer, Node 22 + npm, Docker (MySQL/Redis).

```bash
# 1. Infrastructure
docker compose up -d          # MySQL 8.0 on :3306, Redis 7 on :6379

# 2. Backend (http://localhost:8000)
cd backend
composer install
cp .env.example .env          # or use the prepared .env (see note below)
php artisan key:generate
php artisan migrate
php artisan serve             # also: php artisan reverb:start, php artisan horizon

# 3. Frontend (http://localhost:3000)
cd frontend
npm install
npm run dev
```

**Note on `.env` files**: `backend/.env` currently uses SQLite (works without Docker). `frontend/.env` contains the intended production-like backend config (MySQL + Redis + Sanctum stateful domains) — move it to `backend/.env` (and adjust `REDIS_CLIENT=phpredis` only if the phpredis extension is installed) once Docker is up.

## Configuration already wired

- **Backend**: Sanctum (`install:api`, `HasApiTokens` on `User`), Reverb (`config/reverb.php`, `REVERB_*` keys), Horizon + Telescope providers registered, Lighthouse schema at `graphql/schema.graphql` + `config/lighthouse.php`, Sentry config published (DSN empty — fill in before AWS deploy), CORS for `/graphql` with credentials (lock down origins before deploy, §7).
- **Frontend**: Tailwind v4 via `@tailwindcss/vite` (`app/assets/css/main.css`), global urql client as `$urql` (`app/plugins/urql.ts`) — HTTP + WebSocket subscriptions, cookie credentials included, backend URL via `NUXT_PUBLIC_BACKEND_URL`.
- **Tests**: Pest 5 initialized (`tests/Pest.php`), `php artisan test` green.

## Next milestones

Sept 15–30: migrations per plan §3, CI skeleton · October: Lighthouse+Reverb subscriptions spike, Sanctum auth + GraphQL types, roadmap generation · November: LLM benchmark, LiveKit server + voice fleet · December: Milestone 1 (demo-ready prototype) · February: Milestone 2 (AWS).
