# AI Language Coach — “Kirie”

Голосовой AI-коуч для изучения **английского** (CEFR A1–C1) для японоязычных учеников: онбординг-ассессмент уровня, персональный грамматический роадмап, диалоговая практика с голосовым агентом в реальном времени и интервальное повторение (SM-2) по ошибкам.

- **Интерфейс:** японский (`frontend/app/constants/strings.ts`), учебный контент — английский (из API).
- **Бренд:** **Kirie** (`APP_NAME=Kirie` в бэкенде и `app.name` во фронтенде).

### Документация по модулям

| Документ | Содержание |
|---|---|
| [`ai-language-coach-plan.md`](ai-language-coach-plan.md) | Архитектурный план и майлстоуны |
| [`frontend/README.md`](frontend/README.md) | Nuxt 4 SPA, маршруты, дизайн-система и голосовой орб |
| [`docs/design/README.md`](docs/design/README.md) | Дизайн-система и референсные рендеры экранов |
| [`agent/README.md`](agent/README.md) | Python голосовой воркер (`livekit-agents`) |
| [`e2e/README.md`](e2e/README.md) | Playwright E2E smoke-тесты |
| [`benchmarks/README.md`](benchmarks/README.md) | Бенчмарк задержки первого токена диалоговых LLM |
| [`load/README.md`](load/README.md) | Нагрузочное тестирование голосового пула и проверка SIGTERM drain |
| [`infra/README.md`](infra/README.md) | Terraform IaC (AWS ECS, RDS, ElastiCache, LiveKit EC2) |
| [`docs/deployment.md`](docs/deployment.md) | Runbook однохостового продакшн-деплоя (`docker-compose.prod.yml`) |
| [`PRIVACY.md`](PRIVACY.md) | Политика согласия, хранения и удаления голосовых данных |

---

## Stack

| Слой | Технологии |
|---|---|
| Frontend | Nuxt 4 (Vue 3, SPA `ssr: false`) + Tailwind CSS 4 + `@urql/vue` |
| Auth | Laravel Sanctum (SPA session cookie + CSRF) |
| API | Laravel 13 + Lighthouse GraphQL (`/graphql`) |
| Realtime | Laravel Reverb (Pusher-протокол для GraphQL-подписок через Laravel Echo) |
| Queues | Redis 7 + Laravel Horizon |
| DB | MySQL 8.4 |
| Media | LiveKit Server (WebRTC) |
| Voice Agent | Python 3.11 + `livekit-agents` |
| STT / TTS | Deepgram Nova-3 / Cartesia Sonic-3 |
| LLM (async) | DeepSeek-V3 через `laravel/ai` (анализ ошибок и CEFR-оценка) |
| LLM (dialogue) | Groq / Cerebras / OpenAI (`LLM_PROVIDER`; медленные провайдеры вроде DeepSeek блокируются на старте) |
| Observability | Sentry (PII-scrubber), CloudWatch Metrics (`TurnLatencyMs`, `VoiceSeconds`), Laravel Telescope (dev) |

### Основные зависимости

**Backend (`backend/composer.json`):**
- **Prod:** `laravel/framework` (^13.17), `nuwave/lighthouse` (^6.70), `laravel/sanctum` (^4.0), `laravel/reverb` (^1.11), `laravel/horizon` (^5.49), `laravel/ai` (^0.11.2), `dij-digital/deepgram-laravel` (^0.1.3), `aws/aws-sdk-php` (^3.395), `firebase/php-jwt` (^7.1), `sentry/sentry-laravel` (^4.27).
- **Dev:** `pestphp/pest` (^5.2), `phpunit/phpunit` (^13.3), `laravel/pint` (^1.27), `laravel/telescope` (^5.24), `rector/rector` (^2.6).

**Frontend (`frontend/package.json`):**
- **Prod:** `nuxt` (^4.5), `vue` (^3.5), `vue-router` (^5.3), `@urql/vue` (^2.1), `graphql` (^17.0), `laravel-echo` (^2.5), `pusher-js` (^8.6), `livekit-client` (^2.22), `tailwindcss` + `@tailwindcss/vite` (^4.3).
- **Dev:** `@graphql-codegen/cli` (^7.4), `@graphql-codegen/typescript` (^6.1), `typescript` (~5.9, закреплён для совместимости с `vue-tsc`), `vue-tsc` (^3.3).

---

## Local Setup

### Вариант 1: Полный запуск в Docker

```bash
docker compose --profile full up -d
```

### Вариант 2: Гибридный режим разработки (инфраструктура в Docker, PHP/Nuxt на хосте)

**Требования:** PHP 8.4+ (в CI и образах `8.5.12`) с расширением `redis` (`pecl install redis`), Composer, Node 22+, Docker.

```bash
# 1. Инфраструктура (MySQL 8.4, Redis 7, MinIO, LiveKit Server, Voice Agent)
cp .env.example .env
docker compose up -d
docker compose ps

# 2. Backend (http://localhost:8000)
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve             # также в отдельных терминалах: php artisan queue:work и php artisan reverb:start

# 3. Frontend (http://localhost:3000)
cd frontend
npm install
cp .env.example .env
npm run dev
```

> **Примечание по LiveKit:** Контейнер `coach_livekit` уже запускается в шаге 1 (`ws://localhost:7880`). Альтернативный скрипт `./scripts/livekit-dev.sh` нужен только для запуска нативного `livekit-server` без Docker (не запускайте оба одновременно — они занимают один порт `7880`).

### База данных для тестов

Тесты используют отдельную базу `language_coach_testing`:

```bash
docker exec coach_mysql mysql -uroot -proot_password \
  -e "CREATE DATABASE IF NOT EXISTS language_coach_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON language_coach_testing.* TO 'coach_user'@'%';"
cd backend && DB_DATABASE=language_coach_testing php artisan migrate
```

Демо-аккаунт после `php artisan db:seed`: `test@example.com` / `password`.

---

## Контейнеры и Production-образы

| Компонент | Локальный адрес / режим |
|---|---|
| MySQL 8.4 (`coach_mysql`) | `127.0.0.1:3306` |
| Redis 7 (`coach_redis`) | `127.0.0.1:6379` |
| MinIO (`coach_minio`) | `127.0.0.1:9000` (API) / `127.0.0.1:9001` (Console) |
| LiveKit Server (`coach_livekit`) | `127.0.0.1:7880` |
| Voice Agent (`coach_voice_agent`) | Воркер `ai-language-coach`, healthcheck `:8081` |
| Laravel API / Horizon / Reverb | На хосте (`:8000` / `:8080`) или в Docker через `--profile full` |
| Nuxt Frontend | На хосте (`:3000`) или в Docker через `--profile full` |

### Сборка продакшн-образов

```bash
docker build -t ai-language-coach/api ./backend
docker build -t ai-language-coach/web ./frontend
docker build -t ai-language-coach/agent ./agent
```

Образ `ai-language-coach/api` (nginx + php-fpm под supervisord на `:8080`) используется для всех ролей Laravel в ECS и `docker-compose.prod.yml`:
- `laravel-app`: дефолтный `CMD` (nginx + php-fpm на `:8080`)
- `horizon-worker`: `php artisan horizon`
- `reverb`: `php artisan reverb:start --host=0.0.0.0 --port=8081`
- `scheduler`: `php artisan schedule:work`
- `migrations`: `php artisan migrate --force`

Быстрая проверка собранных образов:
```bash
./scripts/verify-images.sh
```

---

## Архитектурные инварианты и конфигурация

- **Безопасность продакшн-окружения (`ProductionSanityCheck`):** При `APP_ENV=production` приложение отказывается стартовать, если `APP_DEBUG=true`, `SESSION_SECURE_COOKIE!=true`, `CORS_ALLOWED_ORIGINS` или `REVERB_ALLOWED_ORIGINS` содержат `*`, либо если `APP_KEY`, `LIVEKIT_API_SECRET` или `VOICE_INTERNAL_SECRET` совпадают с дефолтными dev-значениями из `backend/config/dev-defaults.php`.
- **GraphQL и кодогенерация типов:**
  - Схема разделена на `backend/graphql/schema.graphql` и доменные файлы в `backend/graphql/types/*.graphql`.
  - Нативные PHP-enum'ы регистрируются через `App\GraphQL\Types\NativeEnumType` и дублируются в `backend/graphql/codegen-enums.graphql` для генератора типов фронтенда.
  - После изменений в `.graphql` выполняйте `php artisan lighthouse:clear-cache` и `npm --prefix frontend run codegen`.
  - Включены лимиты глубины и сложности запросов (`LIGHTHOUSE_MAX_QUERY_DEPTH=10`, `LIGHTHOUSE_MAX_QUERY_COMPLEXITY=50`), а также кэширование AST в режиме `LIGHTHOUSE_QUERY_CACHE_MODE=opcache` (совместимо с `cache.serializable_classes => false` в Laravel 13).
- **GraphQL-подписки через Reverb:**
  - Требуют `LIGHTHOUSE_BROADCASTER=reverb` и `LIGHTHOUSE_SUBSCRIPTION_STORAGE=redis`.
  - Клиентский хендшейк инкапсулирован в `frontend/app/composables/useLighthouseSubscription.ts` (авторизация приватного канала `/graphql/subscriptions/auth` и распаковка конверта `{more, result}`).
- **Голосовые сессии и телеметрия:**
  - Вызов `requestVoiceToken(lessonCardId)` проверяет дневной лимит (`VOICE_DAILY_SESSION_LIMIT`, по умолчанию 10 сессий/сутки в UTC под `lockForUpdate()`), создаёт комнату LiveKit, диспатчит агента и ждёт его входа до `VOICE_AGENT_JOIN_TIMEOUT`.
  - Жизненный цикл сессии управляется идемпотентно через `App\Voice\VoiceSessionLifecycle` (вебхуки `/api/webhooks/livekit` и внутренние эндпоинты агента `/api/internal/sessions/{id}/fail` и `/turns`, защищённые `X-Internal-Secret`).
- **Приватность и хранение данных (`PRIVACY.md`):**
  - Согласие фиксируется серверно в `users.voice_consent_at` при первой отправке записи или старте звонка.
  - Удаление аккаунта (`deleteAccount` / `php artisan privacy:delete-user`) каскадно очищает БД, активные Redis-подписки и объекты в S3.
  - Ежедневные задачи `privacy:purge-transcripts` (90 дней) и `privacy:purge-orphan-audio` (7 дней) очищают устаревшие данные.

---

## Тестирование и CI (`.github/workflows/ci.yml`)

| Проверка | Команда |
|---|---|
| **Backend Tests (Pest/PHPUnit)** | `cd backend && php artisan test --compact` |
| **Backend Code Style (Pint)** | `cd backend && ./vendor/bin/pint --test` |
| **Frontend GraphQL Codegen & Typecheck** | `cd frontend && npm run codegen && npm run typecheck && npm run build` |
| **Voice Agent Tests (Python)** | `cd agent && python3 -m unittest discover -s . -p "test_*.py"` |
| **E2E Smoke Suite (Playwright)** | `npx playwright test` |
| **Container Smoke Verification** | `./scripts/verify-images.sh` |
