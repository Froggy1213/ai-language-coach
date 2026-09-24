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
| `dij-digital/deepgram-laravel` | ^0.1 | Deepgram batch STT via `Listen::transcribeUrl()` — `Read`/`Speak` are still stubs in 0.1.3 |
| `aws/aws-sdk-php` | ^3.395 | Presigned S3 POST for assessment uploads (declared directly; `laravel/ai` already pulled it in) |
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
9. **The cheat sheet content lives in `backend/resources/grammar/{language}.php`, not in the database.** The plan's §3 schema has no room for it and does not need any: `grammar_points` keeps the id-bearing rows `mistakes` and `lesson_cards` point at, while the versioned catalogue holds the CEFR band, the cheat sheet and the practice prompt per code. `GrammarPointSeeder` copies one into the other, `RoadmapGenerator` reads both, and `GrammarCatalogueTest` fails if the catalogue is malformed or a band is missing. Content is therefore reviewed like code, and adding a language is one file plus a line in `config/languages.php`.
10. **`generateRoadmap` mutation and `LessonCard.practicePrompt` are additions to §4.** §4 defines `roadmap` as a query but no way to build one — generation happens inside the assessment job, which does not exist yet — so the mutation is the entry point for the onboarding screen and for a user with no assessment. It is idempotent and never takes a user id. `practicePrompt` is the text the voice agent will ask the learner to talk about; §4's `LessonCard` omitted it even though `requestVoiceToken` needs it as job metadata. Roadmap generation needs **no migration**: `grammar_points` is unchanged.
11. **`User.roadmap` and `Query.roadmap` only ever return the active roadmap.** Regeneration archives the previous roadmap instead of deleting it (mistakes and voice sessions keep pointing at its cards), so an unscoped `@hasOne` — or §4's `@whereAuth(relation: "user") @first`, which has no status filter and no ordering — could hand the frontend the plan the learner has already left behind. The query resolves through `App\GraphQL\Queries\Roadmap`, the relation carries the filter, and `QueryGraphQLTest` pins the archived-plus-active case. Generation itself locks the user's row, so a retried mutation and a retried assessment job cannot both decide that no roadmap exists.
12. **Lighthouse's query cache runs in `opcache` mode** (`LIGHTHOUSE_QUERY_CACHE_MODE=opcache`), not the default `store` mode. Laravel 13 ships `cache.serializable_classes => false` — no PHP class may be unserialized from a cache store — while Lighthouse's `store` mode caches the parsed query as a `DocumentNode` object. The result is a `__PHP_Incomplete_Class` and a 500 on every repeated identical query, on any store (database in dev, Redis in production). OPcache mode writes plain PHP files to `bootstrap/cache` (already writable in both the host setup and the image) and sidesteps serialization entirely. The same trap awaits any future `Cache::remember()` that stores an object — store scalars or arrays, or extend `cache.serializable_classes` deliberately.
13. **The assessment upload is a presigned POST, and `PresignedUpload` gained a `fields` list.** §4's `PresignedUpload { uploadUrl, fileUrl }` fits a presigned PUT, but only an S3 POST policy can carry `content-length-range` and the `Content-Type` condition §5 asks for — so the mutation also returns the form fields to send with the file (`[UploadField!]!`). `submitAssessment` repeats the checks against the object that actually landed in the bucket (owner prefix, size, content type) instead of trusting the client, which is also why the storage layer, not the resolver, parses keys.
14. **S3 is reached through `aws/aws-sdk-php` directly, not `league/flysystem-aws-s3-v3`.** The Laravel filesystem driver is not installed and is not needed: presigning is SDK signing, not a storage adapter, and `headObject`/`deleteObject` cover the rest. The client is built from the `filesystems.disks.s3` config so credentials stay in one place, and an unset `AWS_BUCKET` produces a message naming the setting instead of a broken signature.
15. **The assessment job owns the failure paths, and the recording outlives them.** `AnalyzeAssessment` skips anything that is no longer `processing` (a retry or a duplicate delivery must not pay for STT twice), deletes the raw audio only after the analysis succeeded — so a retry can still transcribe — and its `failed()` writes `status = failed` plus the error into `raw_data`, otherwise the onboarding screen would wait on `processing` forever. `retry_after` on the database and Redis connections (360s) and the Horizon supervisor timeout (300s) were raised to fit the job's 300-second timeout.
16. **Structured LLM output is validated here, not by the SDK.** `laravel/ai` appends the schema to the instructions, asks DeepSeek for a JSON object, and hands back whatever decodes — an unusable answer silently becomes `[]`. `DeepSeekCefrAssessor` therefore runs the response through a validator (band must be one of A1–C1, summary present, both feedback lists non-empty) and fails the job otherwise, so nothing but a real judgement reaches `users.current_level`. The provider is always named explicitly (`ai.default` points at OpenAI) and the model id is `ASSESSMENT_ANALYSIS_MODEL` because it has to be confirmed against the DeepSeek account before the AWS deploy (§7).
17. **The recording is transcribed from its bytes, and the upload policy names the bucket.** Three defects only a live run could show: the provider was handed the URL of a private object (403), the POST policy was missing the `bucket` condition S3 requires, and path-style endpoints put the bucket in front of the key, so `submitAssessment` refused the very URLs the presign had produced. `AssessmentAudioStorage::fetch()` now returns the bytes, the job spools them for the length of one call, and the key parser strips a leading bucket segment.
18. **The channel-authorization route is registered by our own router** (`App\GraphQL\Subscriptions\SubscriptionRouter`, wired through `lighthouse.subscriptions.broadcasters.reverb.routes`). Lighthouse registers it bare, and without session middleware a browser's `laravel_session` cookie is never read, so `authorize()` sees a guest and every channel comes back forbidden. A test cannot catch that on its own — `Sanctum::actingAs()` hides the missing session — so `AssessmentReadyChannelTest` asserts the middleware is on the route as well as that authorization succeeds.
19. **A subscription is bounded by `LIGHTHOUSE_SUBSCRIPTION_STORAGE_TTL`, and a broadcast that cannot be delivered is reported rather than thrown.** Lighthouse restores *every* subscriber of a topic before filtering, so one whose user has been deleted takes the whole push down with a `ModelNotFoundException` — the assessment is already saved by then, so `AnalyzeAssessment` catches and reports it and the client's re-check shows the result. The TTL keeps such entries from accumulating, and the test suite runs under its own `REDIS_PREFIX` so subscribers created against `language_coach_testing` can never poison the dev topic. When the §7 account-deletion endpoint lands, it must clear the user's subscribers before the row goes.

## Local setup

Docker runs **only the backing services**. Laravel, Horizon/Reverb and Nuxt run natively on the host — that keeps Nuxt HMR, xdebug and `artisan` fast, and it is what the commands below assume. The two Dockerfiles are production images for CI/ECS, not part of the dev loop (see [Containers](#containers)).

Prerequisites: PHP 8.5 + Composer, Node 22 + npm, Docker (MySQL/Redis), and the **`redis` PHP extension** — Homebrew's PHP ships without it, so `pecl install redis` is part of setting up a machine. If pecl fails with `failed to mkdir /opt/homebrew/lib/php/pecl/<api>`, create that directory and re-run it. Subscription storage and Horizon both reach Redis through this extension, so it is required, not optional.

```bash
# 1. Infrastructure
cp .env.example .env          # optional — every value in it has a default
docker compose up -d          # MySQL 8.0, Redis 7 and MinIO on loopback
docker compose ps             # wait until all report (healthy)

# 2. Backend (http://localhost:8000)
cd backend
composer install
cp .env.example .env          # or use the prepared .env (see note below)
php artisan key:generate
php artisan migrate
php artisan db:seed           # seeds the grammar catalogue and the sentinel (§5)
php artisan serve             # also: php artisan queue:work, php artisan reverb:start

# 3. Frontend (http://localhost:3000)
cd frontend
npm install
cp .env.example .env          # only needed to override the defaults
npm run dev
```

**Note on `.env` files**: both apps now target MySQL 8 (the `docker compose` service). `backend/.env.example` ships the matching local credentials, so `cp .env.example .env` works as-is; run `php artisan key:generate` to fill `APP_KEY`. The frontend keeps its own Nuxt config in `frontend/.env` — only `NUXT_PUBLIC_*` keys, no secrets, overridable via `frontend/.env.example`. The repository root has a third, unrelated `.env` that only feeds `docker compose` (host ports + MySQL credentials) — see `.env.example`.

Env names follow the runtime-config path (`public.reverb.appKey` → `NUXT_PUBLIC_REVERB_APP_KEY`), so renaming a key in `nuxt.config.ts` silently renames its variable. `frontend/.env` is loaded by `npm run dev` and `npm run preview`, but **not** by the built server — in production (container/ECS) the same values must be real environment variables.

Cache, sessions and queues still use their MySQL tables (`CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` = `database`) — deliberate while the dev loop runs on the host; switch them to `redis` for the Horizon/ElastiCache target. One thing already requires Redis today, though: `LIGHTHOUSE_SUBSCRIPTION_STORAGE=redis` (see the subscriptions bullet under [Configuration already wired](#configuration-already-wired)), which is why the extension above is listed as a prerequisite.

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

## CI

`.github/workflows/ci.yml` runs on every pull request and on every push to `main`; a newer push to the same branch cancels the run it supersedes.

| Job | What it does |
|---|---|
| Backend — tests & code style | `php artisan test` against MySQL 8 and Redis 7 service containers — database name and credentials mirror `backend/phpunit.xml`, so CI and a local run exercise the same configuration — then `vendor/bin/pint --test` |
| Frontend — build | `npm ci` (peer conflicts tolerated through `frontend/.npmrc`) followed by `nuxt build` |

**Branch protection is not configured**, so a red run reports the problem but does not stop a merge — that is a repository setting, not something the workflow can enforce.

## Configuration already wired

- **Backend**: Sanctum (`install:api`, `HasApiTokens` on `User`), Reverb (`config/reverb.php`, `REVERB_*` keys), Horizon + Telescope providers registered, Sentry config published (DSN empty — fill in before AWS deploy), CORS for `/graphql` with credentials (lock down origins before deploy, §7).
- **GraphQL**: root schema in `graphql/schema.graphql`, one file per aggregate under `graphql/types/` (stitched with `#import`). Implemented: `me`, `roadmap`, `dueReviews`, `mistakes(grammarPointId)` — all owner-scoped — plus the `login`/`register`/`logout`/`generateRoadmap`/`createAssessmentUploadUrl`/`submitAssessment` mutations, the `assessmentReady` subscription, and their validators in `App\GraphQL\Validators`. Queries and mutations are locked behind `@guard`, which resolves through Sanctum (see decision 7). After editing any `.graphql` file run `php artisan lighthouse:clear-cache`: the schema is cached outside `local`.
- **IDE support for the schema**: Lighthouse defines its directives in PHP, so an editor that parses only SDL reports every `@guard`/`@field`/`@eq` as "unknown directive". `php artisan lighthouse:ide-helper --omit-built-in` writes `schema-directives.graphql` (directive definitions), `programmatic-types.graphql` (the enums registered on the `TypeRegistry` — see decision 6) and `_lighthouse_ide_helper.php` next to `composer.json`; the JetBrains GraphQL plugin picks them up from the project root. They are generated artefacts, so they are gitignored and regenerated by `composer update`. `--omit-built-in` drops Lighthouse's copy of `@deprecated`, which the plugin already knows — without it the two definitions collide. Pint skips the generated PHP helper (`notName` in `backend/pint.json`); the two `.graphql` artefacts are not PHP, so Pint never looks at them.
- **GraphQL subscriptions** (spike verified 2026-09-21, plan §5): Lighthouse subscriptions do work over self-hosted Reverb. The flow is the Pusher protocol end to end — send the subscription over HTTP with an `X-Socket-ID` header, read the channel from `extensions.lighthouse_subscriptions.channel`, authorize that private channel at `POST /graphql/subscriptions/auth`, then receive results as the `lighthouse-subscription` event. Three dependencies, each of which fails silently: `SubscriptionServiceProvider` must stay registered in `bootstrap/providers.php` (Lighthouse does not auto-discover it, and without it `@subscription` is an unknown directive and the auth route never registers); `LIGHTHOUSE_SUBSCRIPTION_STORAGE` must stay `redis` (any cache store goes through `CacheStorageManager`, and Laravel 13's `cache.serializable_classes => false` turns the stored `Subscriber` into an unusable `__PHP_Incomplete_Class`, which surfaces as a 500 from the auth route); and the client must speak Pusher — `laravel-echo`/`pusher-js`, not `graphql-ws`. Declare subscription fields as nullable (`String`, not `String!`): at subscribe time the field resolves to `null` and a non-null type rejects the subscription response. `tests/Feature/GraphQL/SubscriptionWiringTest.php` pins the first two.
- **Frontend**: Nuxt in SPA mode (`ssr: false` — the session cookie lives in the browser, so there is nothing to render on the server), Tailwind v4 via `@tailwindcss/vite` (`app/assets/css/main.css`), and a global urql client as `$urql` (`app/plugins/urql.ts`). Requests go through `app/utils/csrf-fetch.ts`, which carries the **Sanctum double-submit handshake**: it fetches `GET /sanctum/csrf-cookie` when the cookie is missing (deduplicated, so parallel queries share one request) and re-reads the `XSRF-TOKEN` cookie into an `X-XSRF-TOKEN` header on every request — `login` regenerates the session, so a cached header is what turns the next mutation into a 419. Pages: `/login`, `/register`, `/roadmap`, `/onboarding`; `app/middleware/auth.global.ts` resolves the session once per page load and bounces guests to `/login`; `useAuth()` keeps `me` in `useState` for the header, the guard and the pages. Subscriptions run over Echo (`app/plugins/echo.ts`, Reverb broadcaster) with a custom authorizer: Echo's default one sends neither cookies nor the CSRF header, so the channel authorization would be refused. UI copy is Russian, matching the product audience. **Not wired yet**: the roadmap screen has no practice button — `requestVoiceToken` and the voice agent arrive in November.
- **Recording screen** (`/onboarding`, `useAssessment()`): consent first, then `MediaRecorder` (webm/opus in Chrome and Firefox, mp4 in Safari — the API accepts both), then three transports in a row — presign and submit as ordinary mutations, the file as a multipart POST straight to the bucket, and the result as a Lighthouse subscription. The subscription is opened *before* the upload is submitted, so a fast analysis cannot finish before anyone is listening; the socket id has to exist first, because that is what Lighthouse pushes the result to. A "Проверить сейчас" button re-reads `me` when a push is missed.
- **Database**: the plan §3 schema is in place — 8 domain tables (`users` + 7), models with enum casts, factories and seeders. `GrammarPointSeeder` writes one `uncategorized` sentinel per language listed in `config/languages.php` plus every entry of the grammar catalogue (32 English points across A1–C1); re-running it is safe and refreshes titles in place.
- **Roadmap generation**: `App\Grammar\RoadmapGenerator` turns the learner's `current_level` into a roadmap — every catalogued point at or below that band, weakest first, first card `ready` and the rest `locked`, each card carrying its cheat sheet and practice prompt. Exposed three ways: the `generateRoadmap` GraphQL mutation (idempotent), `regenerate()` for the assessment job that follows a new CEFR level, and `php artisan roadmap:generate {id|email} [--regenerate]` for local work. The catalogue itself is documented under [Key decisions](#key-decisions--deviations-from-the-plan) 9.
- **Assessment pipeline**: `createAssessmentUploadUrl` presigns a limited S3 POST under `assessments/{userId}/`, `submitAssessment` re-checks the stored object (owner, ≤15 MB, audio content type) and queues `App\Assessments\AnalyzeAssessment`. The job reads the recording back out of the bucket, runs Deepgram batch STT (`App\Assessments\DeepgramTranscriber`), then the CEFR judgement (`App\Assessments\DeepSeekCefrAssessor`), writes `assessments.status`/`cefr_level`/`raw_data`, moves `users.current_level`, regenerates the roadmap, deletes the recording and broadcasts `assessmentReady`. Needs `DEEPGRAM_API_KEY`, `DEEPSEEK_API_KEY` and the `AWS_*` block (pointed at MinIO by default); limits live in `config/assessments.php`.
- **Running the pipeline locally**: `docker compose up -d` (MinIO included), `php artisan queue:work` and `php artisan reverb:start` alongside `php artisan serve` and `npm run dev`. Uploads land in the `coach-audio` bucket and disappear again once the transcript exists.
- **Tests**: Pest 5 + PHPUnit classes, `LazilyRefreshDatabase` against `language_coach_testing`, `php artisan test` green — 120 tests, covering the S3 policy limits and key parsing, the Deepgram request shape, the job's success, skip, retry, terminal-failure and undeliverable-broadcast paths, the mutations' ownership and validation, and the whole subscription handshake (channel, authorization, refusal for another learner).

## Next milestones

Sept 15–30: ~~migrations per plan §3~~, ~~Sanctum auth + base GraphQL types + owner-check tests (pulled forward from October)~~, ~~CI skeleton~~ · October: ~~Lighthouse+Reverb subscriptions spike~~, ~~roadmap generation~~, ~~assessment upload + async job~~, ~~auth + roadmap screens~~, ~~onboarding recording screen~~ · November: LLM benchmark, LiveKit server + voice fleet · December: Milestone 1 (demo-ready prototype) · February: Milestone 2 (AWS).

**Try it locally:** `docker compose up -d`, then `php artisan serve` + `php artisan queue:work` + `php artisan reverb:start` in `backend/` and `npm run dev` in `frontend/`. The seeded account is `test@example.com` / `password`. **Known issue (24.09):** signing in from the browser does not reach the roadmap yet — the API accepts the login (Telescope shows `login` → 200 and the following `me` returning the user) but the SPA never requests `roadmap`, so the form looks stuck; see the plan's open defect entry. The recording flow behind **Проверить уровень** is built and its backend half was verified live, but it has not been walked through in a browser either.

**Next up (in order):** the November items from §6 — the first-token-latency benchmark for the dialogue LLM and self-hosted LiveKit with `requestVoiceToken` — and, before the AWS deploy, the §7 checklist (rate limits, Sentry, consent/retention policy, `AWS_BUCKET` swapped from MinIO to the real bucket). The roadmap screen grows practice links once the voice agent exists.
