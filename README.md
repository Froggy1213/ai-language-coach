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
6. **Native PHP enums reach GraphQL through `App\GraphQL\Types\NativeEnumType`**, registered on Lighthouse's `TypeRegistry` (`AppServiceProvider`), not declared in the SDL. The value names on the wire are the backed values (`active`, `A1`), while graphql-php's own enum handling would emit the case names (`Active`). Adding an enum means one `register()` line — never a `enum` block in the SDL, which would collide.
7. **Auth lives in GraphQL** (`login`/`register`/`logout` mutations) — plan §4 defines `@guard` on every field but no way to sign in, and the stack table says the API is Lighthouse. Lighthouse is configured with `guards => ['sanctum']` and the `/graphql` route runs Sanctum's `EnsureFrontendRequestsAreStateful`, so the same guard resolves the SPA session cookie first and personal access tokens second.
8. **`Roadmap.status` / `LessonCard.status` are enums, not §4's `String!`**, and `CheatSheet` follows §4's `{rule, formula, examples, pitfalls}` (the factory was generating `{summary, examples}`). §4 is inconsistent here — it already uses enums for `VoiceSession.status` — and `String!` on an enum-backed attribute fails at runtime, since `StringType::serialize()` rejects enum instances.
9. **Lighthouse's query cache runs in `opcache` mode** (`LIGHTHOUSE_QUERY_CACHE_MODE=opcache`), not the default `store` mode. Laravel 13 ships `cache.serializable_classes => false` — no PHP class may be unserialized from a cache store — while Lighthouse's `store` mode caches the parsed query as a `DocumentNode` object. The result is a `__PHP_Incomplete_Class` and a 500 on every repeated identical query, on any store (database in dev, Redis in production). OPcache mode writes plain PHP files to `bootstrap/cache` (already writable in both the host setup and the image) and sidesteps serialization entirely. The same trap awaits any future `Cache::remember()` that stores an object — store scalars or arrays, or extend `cache.serializable_classes` deliberately.

## Local setup

Docker runs **only the backing services**. Laravel, Horizon/Reverb and Nuxt run natively on the host — that keeps Nuxt HMR, xdebug and `artisan` fast, and it is what the commands below assume. The two Dockerfiles are production images for CI/ECS, not part of the dev loop (see [Containers](#containers)).

Prerequisites: PHP 8.5 + Composer, Node 22 + npm, Docker (MySQL/Redis).

```bash
# 1. Infrastructure
cp .env.example .env          # optional — every value in it has a default
docker compose up -d          # MySQL 8.0 on :3306, Redis 7 on :6379 (loopback only)
docker compose ps             # wait until both report (healthy)

# 2. Backend (http://localhost:8000)
cd backend
composer install
cp .env.example .env          # or use the prepared .env (see note below)
php artisan key:generate
php artisan migrate
php artisan db:seed           # seeds the per-language sentinel grammar point (§5)
php artisan serve             # also: php artisan reverb:start, php artisan horizon

# 3. Frontend (http://localhost:3000)
cd frontend
npm install
cp .env.example .env          # only needed to override the defaults
npm run dev
```

**Note on `.env` files**: both apps now target MySQL 8 (the `docker compose` service). `backend/.env.example` ships the matching local credentials, so `cp .env.example .env` works as-is; run `php artisan key:generate` to fill `APP_KEY`. The frontend keeps its own Nuxt config in `frontend/.env` — only `NUXT_PUBLIC_*` keys, no secrets, overridable via `frontend/.env.example`. The repository root has a third, unrelated `.env` that only feeds `docker compose` (host ports + MySQL credentials) — see `.env.example`.

Env names follow the runtime-config path (`public.reverb.appKey` → `NUXT_PUBLIC_REVERB_APP_KEY`), so renaming a key in `nuxt.config.ts` silently renames its variable. `frontend/.env` is loaded by `npm run dev` and `npm run preview`, but **not** by the built server — in production (container/ECS) the same values must be real environment variables.

Queue/cache/sessions still use their MySQL tables (`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` = `database`); switch them to `redis` for the Horizon/ElastiCache target once the `phpredis` extension or `predis/predis` is installed.

Testing uses a separate `language_coach_testing` database on the same server. Create it once and migrate:

```bash
docker exec coach_mysql mysql -uroot -proot_password \
  -e "CREATE DATABASE IF NOT EXISTS language_coach_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON language_coach_testing.* TO 'coach_user'@'%';"
DB_DATABASE=language_coach_testing php artisan migrate
```

## Containers

| Component | Where it runs |
|---|---|
| MySQL 8.0 (`coach_mysql`) | Docker, `127.0.0.1:3306` |
| Redis 7 (`coach_redis`) | Docker, `127.0.0.1:6379` |
| Laravel API | host — `php artisan serve` → `:8000` |
| Horizon / Reverb | host — `php artisan horizon` / `reverb:start` |
| Nuxt | host — `npm run dev` → `:3000` |

Both services publish on loopback only and declare healthchecks, so `docker compose up -d --wait` returns once MySQL has actually finished initialising. Compose reads the root `.env`; every value has a default, so the file is optional. Keep `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` in sync with `backend/.env`.

### Production images

`backend/Dockerfile` and `frontend/Dockerfile` are multi-stage production builds for CI, staging and ECS — never for the dev loop above.

```bash
docker build -t ai-language-coach/api ./backend
docker build -t ai-language-coach/web ./frontend
```

The API image bundles nginx + php-fpm (supervisord, port 8080) alongside the CLI, so one image fills every Laravel role — the command selects which one runs:

| ECS service | Command |
|---|---|
| `laravel-app` | *(default)* — nginx + php-fpm on `:8080` |
| `horizon-worker` | `php artisan horizon` |
| `reverb` | `php artisan reverb:start --host=0.0.0.0 --port=8081` |
| migrations (one-off) | `php artisan migrate --force` |

Both images install with `--no-dev`, and both `.dockerignore` files exclude the `.env` files, so runtime configuration must come from real environment variables (`NUXT_PUBLIC_*` for Nuxt). For the same reason `bootstrap/providers.php` registers `TelescopeServiceProvider` only when the package is actually installed: Telescope lives in `require-dev`, and registering it unconditionally makes a production container fail to boot.

Smoke-test a build locally:

```bash
docker run --rm -p 8080:8080 -e APP_KEY="base64:$(openssl rand -base64 32)" ai-language-coach/api
curl -i localhost:8080/up     # Laravel health route
```

## Configuration already wired

- **Backend**: Sanctum (`install:api`, `HasApiTokens` on `User`), Reverb (`config/reverb.php`, `REVERB_*` keys), Horizon + Telescope providers registered, Sentry config published (DSN empty — fill in before AWS deploy), CORS for `/graphql` with credentials (lock down origins before deploy, §7).
- **GraphQL**: root schema in `graphql/schema.graphql`, one file per aggregate under `graphql/types/` (stitched with `#import`). Implemented: `me`, `roadmap`, `dueReviews`, `mistakes(grammarPointId)` — all owner-scoped — plus the `login`/`register`/`logout` mutations and their validators in `App\GraphQL\Validators`. Queries and mutations are locked behind `@guard`, which resolves through Sanctum (see decision 7). After editing any `.graphql` file run `php artisan lighthouse:clear-cache`: the schema is cached outside `local`.
- **IDE support for the schema**: Lighthouse defines its directives in PHP, so an editor that parses only SDL reports every `@guard`/`@field`/`@eq` as "unknown directive". `php artisan lighthouse:ide-helper --omit-built-in` writes `schema-directives.graphql` (directive definitions), `programmatic-types.graphql` (the enums registered on the `TypeRegistry` — see decision 6) and `_lighthouse_ide_helper.php` next to `composer.json`; the JetBrains GraphQL plugin picks them up from the project root. They are generated artefacts, so they are gitignored and regenerated by `composer update`. `--omit-built-in` drops Lighthouse's copy of `@deprecated`, which the plugin already knows — without it the two definitions collide. Pint skips the generated PHP helper (`notName` in `backend/pint.json`); the two `.graphql` artefacts are not PHP, so Pint never looks at them.
- **Frontend**: Tailwind v4 via `@tailwindcss/vite` (`app/assets/css/main.css`), global urql client as `$urql` (`app/plugins/urql.ts`) — HTTP + WebSocket subscriptions, cookie credentials included, backend URL via `NUXT_PUBLIC_BACKEND_URL`. **Not wired yet**: mutations from the browser need the Sanctum double-submit handshake — `GET /sanctum/csrf-cookie`, then send the `XSRF-TOKEN` cookie back as an `X-XSRF-TOKEN` header on every request (re-read it per request; `login` regenerates the session). Without it POSTs to `/graphql` get a 419.
- **Database**: the plan §3 schema is in place — 8 domain tables (`users` + 7), models with enum casts, factories and seeders. `GrammarPointSeeder` writes one `uncategorized` sentinel per language listed in `config/languages.php`; re-running it is safe.
- **Tests**: Pest 5 + PHPUnit classes, `LazilyRefreshDatabase` against `language_coach_testing`, `php artisan test` green.

## Next milestones

Sept 15–30: ~~migrations per plan §3~~, ~~Sanctum auth + base GraphQL types + owner-check tests (pulled forward from October)~~, CI skeleton · October: Lighthouse+Reverb subscriptions spike, roadmap generation, assessment upload + async job · November: LLM benchmark, LiveKit server + voice fleet · December: Milestone 1 (demo-ready prototype) · February: Milestone 2 (AWS).

**Next up (in order):** CI skeleton (GitHub Actions: Pint, `php artisan test` against a MySQL service, frontend build) → Lighthouse+Reverb subscriptions spike (plan §6, October week 1) → roadmap generation + the assessment pipeline.
