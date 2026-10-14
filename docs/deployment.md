# Deployment

Two paths are supported, and they share the same three images. Pick one; the
application is identical either way.

| Path | Use it when | Entry point |
|---|---|---|
| **Single Docker host** | a VPS, a home server, a staging box — one machine, Docker only | [`docker-compose.prod.yml`](../docker-compose.prod.yml) |
| **AWS ECS Fargate** | production with a managed database, ALB and autoscaling | [`infra/README.md`](../infra/README.md), [`.github/workflows/cd.yml`](../.github/workflows/cd.yml) |

Everything below in sections 1–9 is the single-host path. Section 10 covers the
AWS path and what it needs that this one does not.

---

## 1. What runs where

| Service | Image | Role |
|---|---|---|
| `proxy` | `caddy:2.10-alpine` | TLS termination, one origin for SPA + API + Reverb |
| `web` | `ai-language-coach-web` | Nuxt 4 (SPA) served by Nitro on `:3000` |
| `api` | `ai-language-coach-api` | nginx + php-fpm on `:8080` (Lighthouse GraphQL) |
| `horizon` | `ai-language-coach-api` | `php artisan horizon` — queue worker |
| `reverb` | `ai-language-coach-api` | `php artisan reverb:start` — WebSocket subscriptions |
| `scheduler` | `ai-language-coach-api` | `php artisan schedule:work` — the retention purges |
| `mysql` | `mysql:8.4` | database, loopback-only |
| `redis` | `redis:7-alpine` | queues, cache, Lighthouse subscription registry |
| `minio` | `quay.io/minio/minio` | object storage **if** you are not using S3 |
| `livekit` | `livekit/livekit-server:v1.8.4` | media server **if** you are not running the separate node |
| `voice-agent` | `ai-language-coach-agent` | Python `livekit-agents` worker |

The same `api` image fills four roles; only the command differs. That is why a
bug in `backend/Dockerfile` is a bug in four services at once, and why
`scripts/verify-images.sh` builds and boots the image before a deploy rather than
during one.

## 2. Before the first `up`

- **Docker Engine 24+ with Compose 2.24+.** The overlay uses `!override` to
  replace LiveKit's published ports. Check with `docker compose version`.
- **DNS.** `APP_DOMAIN` must resolve to the host before the first start, or Caddy
  cannot obtain a certificate. Ports 80 and 443 must be reachable from the
  internet (80 is needed for the ACME HTTP challenge even though all traffic is
  then redirected to 443).
- **A voice setup.** Voice sessions need a media server the learner's browser can
  reach directly, which means a public address and a TURN relay for networks that
  block UDP. The recommended deployment is the dedicated node in `infra/livekit/`
  with `wss://livekit.example.com`; the bundled `livekit` service is a
  development server and has no TURN.
- **Provider keys** for Deepgram (speech-to-text) and an analysis model
  (CEFR assessment, mistake analysis).

## 3. Configuration

```bash
cp .env.production.example .env.production
```

Fill in every value marked `REQUIRED`. Values written `${VAR:?message}` in
`docker-compose.prod.yml` are refused by Compose until they are set, so the
configuration is validated before anything starts rather than at the first
request. Generate the three secrets that must not be reused from development:

```bash
php artisan key:generate --show          # APP_KEY   (run in backend/)
openssl rand -hex 32                     # VOICE_INTERNAL_SECRET
openssl rand -base64 40                  # LIVEKIT_API_SECRET (must be 32+ chars)
```

`APP_KEY` signs sessions, cookies and signed URLs. Rotating it logs every learner
out and invalidates every signed URL, so generate it once and keep it with your
backups.

`.env.production` is git-ignored and holds every secret the deployment has.

## 4. First deploy

```bash
# 1. Build the images on the host (they are not pulled from a registry yet).
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml \
  --profile full build api web voice-agent

# 2. Start the database and cache first, so the migration below has somewhere to go.
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml \
  --profile full up -d mysql redis

# 3. Create the schema and load the grammar catalogue.
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm api php artisan migrate --force
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm api php artisan db:seed --class=GrammarPointSeeder

# 4. Start everything that serves traffic.
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml \
  --profile full up -d \
  api horizon reverb scheduler web proxy
```

Two notes on step 3:

- **Never run `db:seed` without `--class`.** `DatabaseSeeder` creates the demo
  account `test@example.com` with the password `password`. That account exists for
  the local development loop and the browser test suite; in a deployment it is an
  open door.
- `GrammarPointSeeder` is safe to re-run: it refreshes titles in place and writes
  one `uncategorized` sentinel per configured language. Without it the roadmap
  generator has no catalogue to build from.

`migrate --force` is required because the scheduler and Horizon refuse to run
migrations interactively in production.

## 5. Verify the deployment

```bash
# Health, in order of how much they prove.
curl -fsS https://coach.example.com/up                  # Laravel health route
curl -fsS -o /dev/null -w '%{http_code}\n' \
  -X POST https://coach.example.com/graphql \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ __typename }"}'                       # GraphQL over the proxy

# The whole checklist, including the LiveKit node and the webhook signature check.
./scripts/smoke-test.sh --app-url https://coach.example.com \
                        --livekit livekit.example.com

# Container roles: every service should be "running" or "healthy".
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
```

The browser suite in `e2e/` is **not** a production check: it signs in as
`test@example.com`, which must not exist in a deployment. Run it against the
local stack or a staging copy of it (see `e2e/README.md`).

## 6. Upgrades

The images bake the application code in — there are no bind mounts — so a deploy
is always "rebuild, then recreate":

```bash
git pull
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml \
  --profile full build api web voice-agent
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm api php artisan migrate --force
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml \
  --profile full up -d \
  api horizon reverb scheduler web proxy
```

Horizon, Reverb and the scheduler pick up new code only when their container is
recreated, which `up -d` does after an image change. A `restart` is not a deploy.

## 7. Backups

Everything that matters lives in three volumes: `mysql_data`, `minio_data` (if
used) and `caddy_data` (certificates — cheap to reissue, still annoying to lose).

```bash
docker exec coach_mysql sh -c \
  'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$MYSQL_DATABASE"' \
  | gzip > backup-$(date +%F).sql.gz
```

Do this on a schedule, and copy the result **off the host**: a backup that lives
on the machine it backs up is not a backup.

## 8. What this path deliberately does not cover

- **TURN/TLS for WebRTC.** Learners behind a restrictive network need a TURN
  relay on 5349. `infra/livekit/` provisions it; the bundled server cannot.
- **Off-host backups, monitoring and log shipping.** Run `docker compose logs`
  into something that keeps them, or point `SENTRY_*` at a Sentry project.
- **Zero-downtime deploys.** `up -d` recreates containers, so a deploy has a few
  seconds of 502 through the proxy. Run two hosts behind a load balancer, or
  accept a short window.

## 9. Troubleshooting

| Symptom | Cause |
|---|---|
| Container exits immediately, log says `Production sanity check failed: SESSION_SECURE_COOKIE` | A development default reached production. The message names the setting; `ProductionSanityCheck` also refuses a committed `APP_KEY`, `LIVEKIT_API_SECRET` or `VOICE_INTERNAL_SECRET`. |
| Sign-in works, the next click returns 419 | The SPA and the API are on different origins, or `SANCTUM_STATEFUL_DOMAINS` does not list the browser's host. On loopback, `app/utils/backend-endpoint.ts` aligns the two automatically; behind a proxy they must be the same origin. |
| The page loads but every asset 404s, or the SPA is served instead of the API | The proxy's path routing sent the request to the wrong service. `/graphql`, `/api/*`, `/sanctum/*` and `/up` belong to `api:8080`, `/app/*` and `/apps/*` to `reverb:8081`, everything else to `web:3000`. |
| `docker compose config` fails with `required variable ... is missing` | A value marked REQUIRED is empty in `.env.production`. That is the check working. |
| A container boots with a value that looks like a sentence | Compose does **not** strip inline comments in an env file: `KEY=value # note` sets the literal `value # note`. Keep the explanation on its own line, as `.env.production.example` does. |
| Certificate errors in the Caddy log | DNS for `APP_DOMAIN` does not point here yet, or port 80/443 is blocked. |
| Subscriptions never arrive | `reverb` is down, or `/app/*` is not routed to it. The browser console shows the failed WebSocket. |
| Retention purges never run | The `scheduler` service is not in the `up` list. |

## 10. The AWS path

`infra/terraform` provisions the same topology on managed services (ALB, ECS
Fargate, RDS MySQL, ElastiCache Redis, S3, Secrets Manager); `infra/README.md` is
the runbook. `.github/workflows/cd.yml` builds the three images, pushes them to
ECR, runs migrations as a one-off task and rolls the services — but only once the
repository variable `AWS_DEPLOY_ENABLED` is `true` and `AWS_ROLE_TO_ASSUME` is
set, because the account does not exist yet. Until then a push to `main` reports
"deployment skipped" and exits green.

Two things that path still needs before it is a real deployment:

- **A scheduler.** ECS runs services, not cron. The two retention purges in
  `routes/console.php` need an EventBridge Scheduler rule invoking
  `php artisan schedule:run` (or a `scheduler` ECS service running
  `schedule:work`, as the Compose overlay does).
- **The secrets themselves.** `infra/terraform/secrets.tf` creates the entries;
  their values are filled in by hand, and `ProductionSanityCheck` refuses to boot
  on the committed development defaults, so an empty secret is a crash on start
  rather than an insecure deployment.
