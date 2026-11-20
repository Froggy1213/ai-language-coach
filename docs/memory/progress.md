# Progress

Updated: 2026-11-20 · session: review-packages-1-4 — полный цикл правок по код-ревью (Пакеты 1–4)

## Сейчас

Ветка `main` (все изменения Пакетов 1–4 в рабочем дереве, не закоммичены). Закрыты все четыре пакета из полного код-ревью репозитория:

- **Пакет 1 (Биллинг и Paywall):** `VoiceAllowanceResolver` исключает терминальные подписки (`canceled`, `incomplete_expired`) с приоритетом `active`/`trialing` и откатом на 30 free-минут/мес; обе границы `monthlyWindow` считаются от исходного `$anchor` (фикс 29–31 чисел); `VOICE_MINUTES_EXHAUSTED` передаёт `planCode` и `hasSubscription` в GraphQL extensions и `VoiceLimitGate.vue`; `SubscriptionSynchronizer` устойчив к вебхукам удалённых пользователей и out-of-order событиям; `StripeSdkGateway::cancelSubscription` идемпотентен при 404/`resource_missing`; `BillingCheckout` блокирует дубли при `unpaid` и отдаёт `BILLING_NO_SUBSCRIPTION` для портала без Stripe-профиля; обновлены `useBilling.ts`, `pricing.vue` и `settings/billing.vue`.
- **Пакет 2 (Стабильность и безопасность бэкенда):** `S3Client` использует default credential chain (ECS Task Role) при пустых ключах; 16 именованных `RateLimiter`-бакетов `gql-*` (включая `gql-review-submit` 60/мин) и повышенные лимиты `/fail` (`600,1`) и `/turns` (`1200,1`); `RoadmapLessonCards` устраняет N+1 на `Roadmap.lessonCards`; `LiveKitApi` трактует 404 в `participants`/`deleteRoom` как отсутствие комнаты; `VoiceSessionLifecycle` добавляет `activate` и запись/кап `duration_sec` при fail/abandon активной сессии; единый порядок блокировок `Roadmap -> LessonCard` и фильтрация архивных роадмапов; скраббинг экранированных кавычек, сообщений breadcrumb и новых ключей в `SentryEventScrubber`; потолок `Sm2::MAX_INTERVAL_DAYS = 365` и миграция `review_items.next_review_at` в `DATETIME`.
- **Пакет 3 (Python-агент и фронтенд WebRTC/подписок/ошибок/Закон #4):**
  - `agent/`: `TurnMetricsTracker.drain()` немедленно сбрасывает ожидающие ходы и ждёт `_background_tasks`; `_attach_uncorrelated_tts()` не привязывает приветствие или отменённый TTS к первому ходу; `turn_metrics.py` отбрасывает `cancelled` `LLMMetrics`; `session.py` вызывает `ctx.shutdown()` при ошибках инициализации/рантайма, ждёт grace period `6.0s` на `participant_disconnected` и чистит Markdown перед TTS; `backend.py` переиспользует `aiohttp.ClientSession` и ретраит 5xx/сетевые ошибки в `report_session_turn()`; `prompts.py` запрещает Markdown и оборачивает данные сценария в `<scenario_data>`.
  - `frontend/`: монотонный `attemptId`, `removeAllListeners()` и `reset()` в `useVoiceSession.ts`; проверка `disposed`/`generation` после `await openLighthouseSubscription` в `useLighthouseSubscription.ts`; привязка к `pendingAssessmentId` и `onReconnect` в `useAssessment.ts` и `level.vue`; ретрай HTTP 419 в `csrf-fetch.ts`; сохранение сессии при сетевых сбоях `ME_QUERY` и показ ошибок `logout` в `useAuth.ts`; локализация сетевых/401/429/5xx ошибок в `graphql-error.ts` и `voice-errors.ts`; фиксы `reflect/[[id]].vue`, `CustomScenarioBuilder.vue` (`--surface-sunken`, сохранение `register`/`level`), `lesson/[cardId].vue` и `talk/[code].vue` (`:finishing`, смена параметра маршрута), `PronunciationPanel.vue`, `UiNotice.vue` (`role="alert"`), `Escape` в модалках, `additionalTypenames: ['ReviewItem']` для `dueCount`, обновление Deepgram/Resend и опечатки в `strings.ts`, вынос всех захардкоженных японских строк в `strings.ts` (Закон #4).
- **Пакет 4 (Инфраструктура, Docker, CI/CD и гигиена):** S3 `Suspended` версионирование + 1-дневная очистка noncurrent/multipart в `s3.tf`; Cloud Map DNS для Reverb (`REVERB_SCHEME=http`, SG 8081), сужение ALB до `/app/*`, `scheduler` ECS сервис (` www-data`), `cloudwatch:PutMetricData` и `ignore_changes = [desired_count]` в `ecs.tf`; пустой `SENTRY_DSN` в `secrets.tf`; гейт `cd.yml` через `workflow_run` от `CI` и деплой по immutable тегам `sha-<commit>`; фикс фильтра и SNS алармов в `cloudwatch.tf`; `7882/udp` + Caddy TLS `:443` на LiveKit EC2 и проверка `https://$LIVEKIT_HOST/` в `smoke-test.sh`; не-root `www-data` таргеты в `backend/Dockerfile`, чистка `docker-compose*.yml`, дефолт `'opcache'` и `storage_ttl = 3600` в `config/lighthouse.php`, удаление `voice-samples/*.mp3` и `docker/livekit.yaml`, актуализация `README.md`.

## Проверено

- **Backend:**
  - `cd backend && php artisan lighthouse:clear-cache` — passed.
  - `cd backend && ./vendor/bin/pint --test` — `{"tool":"pint","result":"passed"}`.
  - `cd backend && php artisan test --compact` (против `mysql` и `redis` в OrbStack/Docker) — `627 passed` (`3159 assertions`, `17042ms`).
- **Agent:**
  - `python3 -m unittest discover -s agent -p "test_*.py"` — `Ran 128 tests in 1.408s — OK (skipped=16)` (включая все 21 контрактный тест из `test_prompts.py`).
- **Frontend:**
  - `cd frontend && HOME=/tmp NUXT_TELEMETRY_DISABLED=1 npm run codegen` — `frontend/app/types/graphql.ts` синхронизирован со схемой.
  - `cd frontend && HOME=/tmp NUXT_TELEMETRY_DISABLED=1 npm run typecheck` — `Type check passed`.
  - `cd frontend && HOME=/tmp NUXT_TELEMETRY_DISABLED=1 npm run build` — `Client built`, `Server built`, `✔ Nuxt Nitro server built`, `✨ Build complete!`.
- **Infra / Docker / Scripts:**
  - `bash -n scripts/smoke-test.sh infra/livekit/user-data.sh` — passed (exit 0).
  - `docker compose -f docker-compose.yml --profile full config -q` и override с `docker-compose.prod.yml` — passed (exit 0).
  - Парсинг YAML (`docker-compose.yml`, `docker-compose.prod.yml`, `.github/workflows/cd.yml`, `infra/livekit/*.tpl`) и статическая проверка ссылок/плейсхолдеров `infra/terraform/*.tf` — passed.

## Дальше

1. Просмотреть `git status` / `git diff` и закоммитить изменения Пакетов 1–4.
2. Живой браузерный прогон `/lesson/{cardId}` против локального стека (`docker compose --profile full`) и E2E smoke (`npm run e2e:smoke`).

## Открытые вопросы

- Не проверялись вживую внешний Stripe checkout, Resend и раскатка Terraform на реальном AWS-аккаунте (`terraform` CLI на хосте не установлен).

## Блокировано

Ничего.

## Прошлая сессия

- 2026-11-18 — голос восстановлен: удалённый голос Cartesia заменён на Deepgram Aura, free-план 30 минут в месяц, телеметрия TTS.
- 2026-11-17 — подтверждение email, сброс пароля, Resend.
- 2026-11-16 — `/settings` разделён на хаб и четыре экрана; профиль и пароль.
