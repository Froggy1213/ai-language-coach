# AI Language Coach — План разработки (сентябрь 2026 – февраль 2027)

> **Статус на 24.09.2026.** Закрыто: сентябрьский блок целиком (репозиторий, docker-compose, миграции §3, CI) и октябрьские недели 1–2 — spike Lighthouse↔Reverb пройден (§5), Sanctum-auth, базовые GraphQL-типы и owner-check тесты готовы. Плюс обе половины недель 3–4 на бэкенде: генерация роадмапа (каталог grammar points A1–C1 с cheat sheet'ами, `RoadmapGenerator`, мутация `generateRoadmap`, artisan-команда) и assessment-пайплайн (`createAssessmentUploadUrl` с presigned POST-лимитами → `submitAssessment` → Horizon-джоб Deepgram batch → CEFR-анализ DeepSeek → `users.current_level` → регенерация роадмапа → подписка `assessmentReady`). 110 тестов зелёных, схема §3 не менялась. Фронт закрыт целиком: SPA-режим, Sanctum double-submit handshake, экраны входа/регистрации/роадмапа и онбординг с записью голоса (consent → MediaRecorder → presigned POST → подписка `assessmentReady` через Echo/Reverb). Вся цепочка прогнана вживую локально: реальные Deepgram и DeepSeek, MinIO вместо S3, событие подписки доставлено настоящему WebSocket-клиенту. Для локального прогона добавлены MinIO в docker-compose и передача аудио байтами вместо URL приватного бакета. Отметки по этапам — в §6, закрытые пункты чеклиста — в §7. Отклонения по стеку, схеме и контракту от §1/§3/§4/§5 зафиксированы в README → «Key decisions & deviations from the plan», пп. 9–16.

## 0. Рамки проекта

Единый монолит без ре-платформинга между этапами: **Laravel + Lighthouse GraphQL + Nuxt 4 + MySQL + Redis + self-hosted LiveKit**, разворачиваемый на AWS. Осознанно исключены: Kubernetes/KEDA, Kafka/RabbitMQ, PostgreSQL+pgvector, нативные мобильные клиенты, LiveKit Cloud.

**Milestone 1 — конец декабря 2026:** базовое Web+DB приложение (CRUD, аутентификация, GraphQL), голосовой цикл работает end-to-end локально.
**Milestone 2 — конец февраля 2027:** сервис развёрнут на AWS.

**Явные границы scope (решения, не недосмотры):**
- CEFR ограничен A1–C1 (из исходного продуктового ТЗ) — C2 вне охвата V1.
- `mistakes` классифицируются только по `grammar_points`; лексические/фонетические ошибки вне охвата V1.
- **LiveKit V1 — один EC2-узел, без HA.** Падение ноды = голосовой цикл недоступен; остальной сервис (роадмап, cheat sheet, auth) продолжает работать. Деградация graceful, blue-green вне охвата V1.
- **Laravel Reverb V1 — один инстанс**, без Redis/DB-driver горизонтального скейлинга (он у Reverb есть «из коробки», просто не нужен на этом масштабе).
- **NAT Gateway не используется в V1** — `voice-agent-worker`/`horizon-worker` в публичном subnet с публичным IP и жёсткой security group вместо приватного subnet + NAT (~$32/мес + $0.045/ГБ обработки + отдельный egress). Переход в приватный subnet — задача харденинга при появлении требований комплаенса, не сейчас.

---

## 1. Финальный стек

| Слой | Технология | Примечание |
|---|---|---|
| Frontend | Nuxt 4 (Vue 3) + Tailwind 4 | SPA-режим |
| Auth | Laravel Sanctum (SPA cookie) | Один домен/поддомены |
| API | Laravel 13 + Lighthouse (GraphQL) | PHP 8.5, обычный PHP-FPM, без Octane |
| Realtime к фронту | Laravel Reverb | Один инстанс на V1; связка с Lighthouse subscriptions проверена — §5 |
| Очереди | Redis + Laravel Horizon | Отдельный ECS-сервис |
| DB | MySQL 8.0 (RDS) | JSON-колонки под cheat sheet/transcript |
| Media | LiveKit Server (self-hosted, **EC2 + Elastic IP**) | Не Fargate; нативный SIGTERM-drain |
| Voice Agent | Python 3.11, livekit-agents | ECS Fargate, публичный subnet (без NAT) |
| STT | Deepgram Nova-2 | WebSocket streaming + batch (для ассессмента) |
| TTS | Cartesia Sonic | Streaming |
| LLM (диалог, real-time) | **TBD по бенчмарку** (§6, нояб. нед. 1) | Latency решает, не цена |
| LLM (async: разбор ошибок + CEFR-оценка ассессмента) | DeepSeek-V3 | Латентность не критична |
| Хранилище аудио | S3 (presigned upload, лимиты — см. §5) | Lifecycle policy на транскрипты |
| Observability | Sentry (Laravel + Python), Telescope (dev) / Pulse (prod) | Обязательно до AWS-деплоя |

---

## 2. Архитектура

```
[ Nuxt 3 ] ──HTTPS/WSS (ALB, только Laravel/Nuxt/Reverb)──► [ ECS Fargate: laravel-app ]
     │                                                              │
     │                                                              ├─► MySQL 8.0 (RDS)
     │                                                              ├─► Redis (ElastiCache)
     │                                                              ├─► ECS Fargate: horizon-worker (публичный subnet, без NAT)
     │                                                              └─► ECS Fargate: reverb (1 инстанс) ──WS──► Nuxt
     │
     │ WebRTC (livekit-client), прямое соединение — не через ALB
     ▼
[ LiveKit Server — EC2 + Elastic IP, встроенный TURN/TLS, Let's Encrypt-сертификат (не ACM) ]
     │ explicit dispatch + job metadata, bounded wait → VOICE_FLEET_BUSY при насыщении
     ▼
[ Voice Agent Fleet: Python, livekit-agents, ECS Fargate, публичный subnet ]
     │  num_idle_processes/max_processes — тёплый пул; drain_timeout настроен под SIGTERM
     ├─► Deepgram (STT) / Cartesia (TTS) / диалоговая LLM (по бенчмарку)
     ├─► CloudWatch (per-turn latency)
     └─► webhook room_finished (подписан, идемпотентно) ──► Horizon job ──► DeepSeek-V3 (разбор, ограничен списком grammar_points) ──► mistakes + review_items
```

---

## 3. Схема БД (MySQL, финальная)

```sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    target_language VARCHAR(8) NOT NULL,
    current_level ENUM('A1','A2','B1','B2','C1') NOT NULL DEFAULT 'A1',
    timezone VARCHAR(64) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- status: processing (STT+LLM ещё не отработали) → done
CREATE TABLE assessments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('processing','done','failed') NOT NULL DEFAULT 'processing',
    cefr_level ENUM('A1','A2','B1','B2','C1') NULL,
    audio_url VARCHAR(512) NOT NULL,
    raw_data JSON NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_created (user_id, created_at)
);

CREATE TABLE roadmaps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    status ENUM('active','completed','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE grammar_points (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    language VARCHAR(8) NOT NULL,
    code VARCHAR(64) NOT NULL,               -- "uncategorized" — sentinel на язык, см. §5
    title VARCHAR(255) NOT NULL,
    category VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NULL,
    UNIQUE KEY uq_language_code (language, code)
);

CREATE TABLE lesson_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    roadmap_id BIGINT UNSIGNED NOT NULL,
    grammar_point_id BIGINT UNSIGNED NOT NULL,
    order_index INT UNSIGNED NOT NULL,
    status ENUM('locked','ready','completed') NOT NULL DEFAULT 'locked',
    cheat_sheet JSON NOT NULL,
    practice_prompt TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (roadmap_id) REFERENCES roadmaps(id) ON DELETE CASCADE,
    FOREIGN KEY (grammar_point_id) REFERENCES grammar_points(id),
    INDEX idx_roadmap_order (roadmap_id, order_index)
);

CREATE TABLE voice_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_card_id BIGINT UNSIGNED NOT NULL,
    room_name VARCHAR(128) NOT NULL,
    status ENUM('pending','active','completed','failed','abandoned') NOT NULL DEFAULT 'pending',
    -- completed = сессию завершил сам агент (дошёл до конца сценария);
    -- abandoned = комната закрылась по empty_timeout без участия агента
    fail_reason VARCHAR(255) NULL,
    duration_sec INT UNSIGNED NULL,
    transcript JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_card_id) REFERENCES lesson_cards(id),
    UNIQUE KEY uq_room_name (room_name),
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_user_status (user_id, status)
);

CREATE TABLE mistakes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    grammar_point_id BIGINT UNSIGNED NOT NULL,  -- fallback на sentinel "uncategorized", см. §5
    user_utterance TEXT NOT NULL,
    correction TEXT NOT NULL,
    explanation TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (session_id) REFERENCES voice_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (grammar_point_id) REFERENCES grammar_points(id),
    INDEX idx_user_gp_created (user_id, grammar_point_id, created_at)
);

-- Создаётся лениво при первой mistake на пару (user, grammar_point) — см. §5
CREATE TABLE review_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    grammar_point_id BIGINT UNSIGNED NOT NULL,
    ease_factor DECIMAL(4,2) NOT NULL DEFAULT 2.50,
    interval_days INT UNSIGNED NOT NULL DEFAULT 1,
    repetition_number INT UNSIGNED NOT NULL DEFAULT 0,
    next_review_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (grammar_point_id) REFERENCES grammar_points(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_gp (user_id, grammar_point_id),
    INDEX idx_user_review (user_id, next_review_at)
);
```

```sql
SELECT grammar_point_id, COUNT(DISTINCT session_id) AS session_count
FROM mistakes
WHERE user_id = ? AND created_at >= NOW() - INTERVAL 7 DAY
GROUP BY grammar_point_id
HAVING session_count >= 3;
```

---

## 4. GraphQL-контракт (Lighthouse)

Реализовано на 24.09.2026: запросы `me`, `roadmap`, `dueReviews`, `mistakes(grammarPointId)` (все с owner-check) и мутации `login`/`register`/`logout` — вход тоже живёт в GraphQL, см. README, решение 7 — плюс `generateRoadmap` (идемпотентная генерация роадмапа по `current_level`, README, решение 10) и добавленное к контракту поле `LessonCard.practicePrompt`. Остальное ниже — контракт на будущее, а не описание текущего среза.

```graphql
enum CefrLevel { A1 A2 B1 B2 C1 }
enum SessionStatus { pending active completed failed abandoned }
enum AssessmentStatus { processing done failed }

type User {
  id: ID!
  name: String!
  targetLanguage: String!
  currentLevel: CefrLevel!
  roadmap: Roadmap @hasOne
}

type Roadmap {
  id: ID!
  title: String!
  status: String!
  lessonCards: [LessonCard!]! @hasMany
}

type LessonCard {
  id: ID!
  orderIndex: Int!
  status: String!
  cheatSheet: CheatSheet!
  practicePrompt: String!   # добавлено: текст, о котором агент просит говорить (§5)
  grammarPoint: GrammarPoint! @belongsTo
}

type CheatSheet { rule: String! formula: String! examples: [String!]! pitfalls: [String!]! }
type GrammarPoint { id: ID! language: String! title: String! category: String! }

type Assessment { id: ID! status: AssessmentStatus! cefrLevel: CefrLevel }

type VoiceSession {
  id: ID!
  status: SessionStatus!
  livekitUrl: String
  livekitToken: String
  mistakes: [Mistake!]! @hasMany
}

type Mistake {
  id: ID!
  userUtterance: String!
  correction: String!
  explanation: String!
  grammarPoint: GrammarPoint! @belongsTo
}

# ИЗМЕНЕНО: добавлен `fields` — presigned POST без полей формы не отправить (§5)
type PresignedUpload { uploadUrl: String! fileUrl: String! fields: [UploadField!]! }
type UploadField { name: String! value: String! }

# ИСПРАВЛЕНО: было [GrammarPoint!]!, без интервалов очередь не отрисовать
type ReviewItem {
  id: ID!
  grammarPoint: GrammarPoint! @belongsTo
  easeFactor: Float!
  intervalDays: Int!
  nextReviewAt: String!
}

type Query {
  me: User! @guard
  roadmap: Roadmap @guard
  dueReviews: [ReviewItem!]! @guard
  mistakes(grammarPointId: ID): [Mistake!]! @guard
}

type Mutation {
  generateRoadmap: Roadmap! @guard   # добавлено: идемпотентно, из current_level (§5)
  createAssessmentUploadUrl(contentType: String!): PresignedUpload! @guard
  submitAssessment(audioUrl: String!): Assessment! @guard
  requestVoiceToken(lessonCardId: ID!): VoiceSession! @guard
  submitReviewResult(grammarPointId: ID!, quality: Int!): ReviewItem! @guard
  completeLessonCard(lessonCardId: ID!): LessonCard! @guard
}

type Subscription {
  assessmentReady(userId: ID!): Assessment!
    @subscription(class: "App\\GraphQL\\Subscriptions\\AssessmentReady")
  sessionFeedbackReady(sessionId: ID!): VoiceSession!
    @subscription(class: "App\\GraphQL\\Subscriptions\\SessionFeedbackReady")
}
```

---

## 5. Голосовой пайплайн и вспомогательные потоки — контракт реализации

**Ассессмент — полный поток:** `createAssessmentUploadUrl` (presigned S3 POST, лимит размера ~15 МБ и MIME `audio/webm|wav|mp3` через условия политики) → клиент грузит файл в S3 → `submitAssessment(audioUrl)` создаёт `assessments` со статусом `processing` и диспатчит Horizon job: Deepgram batch STT → LLM-анализ транскрипта → CEFR-оценка → `assessments.status = done` → генерация `roadmaps`/`lesson_cards`. Фронт получает готовность через `assessmentReady(userId)` subscription, не синхронным ожиданием.

**Ассессмент — реализовано 24.09.2026.** Ключ объекта — `assessments/{userId}/{ulid}.{ext}`, лимиты и MIME в `config/assessments.php`; `submitAssessment` не доверяет клиенту и повторно проверяет объект в бакете (владелец по префиксу, размер, content type). Джоб `AnalyzeAssessment` (timeout 300 с, 3 попытки с бэкоффом 30/120 c, `retry_after` очередей и timeout Horizon подняты под это) пропускает всё, что уже не `processing`; сырое аудио удаляется **после** успешного анализа, чтобы повторная попытка могла перечитать запись; при исчерпании попыток `failed()` пишет `status = failed` и текст ошибки в `raw_data`, иначе экран онбординга ждал бы `processing` вечно. Structured output `laravel/ai` не валидирует — ответ модели проходит через валидатор в `DeepSeekCefrAssessor` (бэнд только A1–C1, непустые summary/strengths/weaknesses), иначе джоб падает. Провайдер LLM указывается явно (`ai.default` смотрит в OpenAI), модель — `ASSESSMENT_ANALYSIS_MODEL` (уточнить под аккаунт до AWS, §7).

**`requestVoiceToken` резолвер:**
1. Проверить владение уроком и `status === 'ready'`.
2. Idempotency-гард: если есть `voice_sessions` со `status IN ('pending','active')` для пользователя — вернуть её, не создавать новую.
3. Создать `voice_sessions` (`pending`), явный dispatch с job-metadata (`session_id`, `grammar_point_id`, `practice_prompt`).
4. **Насыщение флота:** ждать назначения джоба с таймаутом (~5 сек). Если воркер не нашёлся — типизированная ошибка `VOICE_FLEET_BUSY`, фронт показывает «высокая нагрузка, попробуйте через минуту» с ретраем, а не висит бесконечно.
5. Выпустить access-token LiveKit самостоятельно (`firebase/php-jwt`, ~50 строк) с коротким TTL (10–15 минут, не дефолт SDK), scoped на `room_name`. Пакет `Agence104\LiveKit` из исходной редакции плана заброшен (все версии тянут `firebase/php-jwt` v6 с advisory) — см. README, решение 1.

**Вебхук `/api/webhooks/livekit`:** сырое тело, HMAC-проверка подписи своей реализацией на `firebase/php-jwt` (BCMath в PHP-образе; готового receiver'а нет — тот же заброшенный пакет). Идемпотентно: переход применяется только из ожидаемого текущего статуса, повторный `room_finished` при уже терминальном статусе игнорируется. `participant_joined` → `active`. `room_finished`: `completed`, если инициатором был сам агент (дошёл до конца сценария и корректно закрылся); `abandoned`, если комната закрылась по `empty_timeout` без участия агента.

**Статус `failed`:** `POST /internal/sessions/{id}/fail` (shared secret), агент вызывает сам при падении STT/TTS/LLM перед дисконнектом.

**LiveKit — обновление и drain:** сервер уже умеет нативный graceful drain по `SIGTERM`/`SIGINT`/`SIGQUIT` (не принимает новые комнаты, доигрывает активные, завершается сам) — деплой-скрипт должен просто дать ему время: `stop_grace_period` на порядок больше длины звонка (10–15 мин), а не рвать процесс дефолтным таймаутом. Для Python voice-agent-worker отдельно: пин версии `livekit-agents` и явная проверка SIGTERM-поведения в январском нагрузочном тесте (были открытые баги с надёжностью сигнала при блокирующем I/O в `request_fnc`); `AgentServer(drain_timeout=...)` выставить под реальную длину спринтов, `stopTimeout` в ECS task definition — не короче.

**TURN/TLS:** используется встроенный TURN LiveKit. Сертификат — **Let's Encrypt/certbot напрямую на EC2**, не ACM (ACM не отдаёt приватный ключ наружу, а LiveKit требует `cert_file`/`key_file`). Отдельный домен под TURN либо тот же сертификат с SAN.

**Диалоговая LLM:** обязательный бенчмарк first-token latency до интеграции (§6, нояб. нед. 1). DeepSeek-V3 подтверждён только для async-разбора и CEFR-оценки — там цена важнее скорости.

**Ограничение LLM при разборе ошибок:** промпт async-разбора получает канонический список `grammar_points` (id+code), отфильтрованный по `target_language` пользователя, вывод — строго структурированный по этому списку. Для случая, когда модель не может уверенно сопоставить ошибку ни одному пункту — sentinel `grammar_point` `code = "uncategorized"` на каждый язык, а не отказ записи (у `mistakes.grammar_point_id` NOT NULL).

**Cheat sheet и practice prompt — реализовано 24.09.2026.** Контент живёт в `backend/resources/grammar/{language}.php`: версионируемый каталог (код, категория, CEFR-уровень, `cheat_sheet {rule, formula, examples, pitfalls}`, `practice_prompt`). `GrammarPointSeeder` раскладывает его в `grammar_points` вместе с sentinel'ом, `RoadmapGenerator` — в `lesson_cards` (cheat sheet копируется в карту, чтобы позже её можно было заменить персональной LLM-версией). Английский каталог — 32 пункта A1–C1; схема §3 при этом не менялась. Выбор по уровню: все пункты не выше `users.current_level`, от слабых к сильным, первая карта `ready`, остальные `locked`. Генерация идемпотентна (повтор возвращает существующий активный роадмап), `regenerate()` архивирует предыдущий — его карты остаются, потому что на них ссылаются `mistakes` и `voice_sessions`.

**Latency:** per-turn `{stt_final, llm_first_token, tts_first_chunk, total_turnaround}` в `voice_sessions.transcript` + отдельно в CloudWatch напрямую из Python-процесса.

**SM-2:** `review_items` создаётся лениво при первой `mistake` на пару (user, grammar_point), если строки ещё нет — дефолты `ease_factor=2.50, interval_days=1, next_review_at=NOW()+1 день`. `submitReviewResult(grammarPointId, quality)` пересчитывает по формуле SM-2. `completeLessonCard(lessonCardId)` — в транзакции с `lockForUpdate()` — переводит карту в `completed` и разблокирует следующую (`order_index+1` → `ready`).

**Voice Fleet capacity:** первично регулируется `num_idle_processes`/`max_processes` внутри уже запущенных Fargate-тасков, ECS-автоскейл — вторая, медленная линия. `CapacityPerTask` **и** ёмкость самой LiveKit-ноды — оба бенчатся в январе (§6), не закладываются на глаз.

**Lighthouse subscriptions ↔ Reverb — риск закрыт 21.09.2026 (spike пройден досрочно).** Связка работает: `LIGHTHOUSE_BROADCASTER=reverb` — это тот же pusher-драйвер, но смотрящий в `broadcasting.connections.reverb`, а не в Pusher Cloud. Проверено сквозным прогоном: подписка уходит по HTTP с заголовком `X-Socket-ID` → в ответе приходит приватный канал `private-lighthouse-…` → клиент авторизует его через `POST /graphql/subscriptions/auth` → результат приезжает событием `lighthouse-subscription`. **Polling-fallback не нужен.** Три вещи, без которых путь молча ломается (все три закреплены тестом `SubscriptionWiringTest`): `SubscriptionServiceProvider` обязан быть зарегистрирован в `bootstrap/providers.php` — Lighthouse его не авто-обнаруживает; `LIGHTHOUSE_SUBSCRIPTION_STORAGE=redis` обязателен, иначе `CacheStorageManager` упирается в `cache.serializable_classes => false`; клиент обязан говорить по протоколу Pusher (`laravel-echo`/`pusher-js`), а не `graphql-ws`. Поля подписки объявлять nullable — при подписке они резолвятся в `null`.

**Privacy:** consent на онбординге перед записью голоса; retention транскриптов через S3 lifecycle policy; эндпоинт удаления по запросу; сырое аудио не хранится дольше времени обработки STT.

---

## 6. План по этапам

| Период | Фокус | Результат | Статус |
|---|---|---|---|
| 15–30 сент | Repo, docker-compose (MySQL+Redis+Laravel+Nuxt), CI skeleton, миграции по схеме §3 | `docker-compose up` поднимает всё локально | ✅ 21.09 — с отличием: в Docker только MySQL+Redis, Laravel/Nuxt запускаются нативно (README → Containers) |
| Окт, нед 1 | **Spike: Lighthouse subscriptions + Reverb** (pusher-driver подход, fallback на polling если не заведётся) | Известно, работает ли связка, до того как на неё завязан декабрьский план | ✅ 21.09, досрочно — работает, polling-fallback не понадобился (§5) |
| Окт, нед 1–2 | Sanctum-auth, базовые GraphQL-типы, feature-тесты на auth/owner-check | `me`/`roadmap` отдают данные, тесты зелёные | ✅ 21.09, досрочно — 37 тестов зелёные, изоляция владельца покрыта |
| Окт, нед 3–4 | ~~Генерация roadmap~~, ~~`createAssessmentUploadUrl`+`submitAssessment`+async job (Deepgram batch→LLM→CEFR)~~, ~~Sanctum handshake + экран роадмапа/cheat sheet~~, ~~экран онбординга с записью (consent → MediaRecorder → presigned POST → `assessmentReady`)~~ | Пункт закрыт 24.09 целиком: 120 тестов, пайплайн и подписка прогнаны вживую (Deepgram/DeepSeek, MinIO, Reverb) | ✅ |
| Нояб, нед 1 | **Бенчмарк first-token latency диалоговой LLM** | Модель для реплик выбрана по данным |
| Нояб, нед 1–2 | LiveKit self-hosted на EC2 + Elastic IP, TURN/TLS (Let's Encrypt), `requestVoiceToken` с idempotency и `VOICE_FLEET_BUSY` | Голосовое соединение работает по сети, насыщение флота обработано |
| Нояб, нед 3–4 | Интеграция LLM в агента, latency-инструментация, идемпотентная обработка `room_finished`/`failed`, ограничение LLM списком grammar_points | Диалоговый спринт с таймингами, вебхук не дублирует обработку |
| Дек, нед 1–2 | Async-разбор ошибок, `submitReviewResult`/`completeLessonCard` (с lockForUpdate), `assessmentReady`/`sessionFeedbackReady` subscriptions | Фидбек и разблокировка урока работают |
| Дек, нед 3–4 | **Milestone 1**: сквозной прогон, e2e smoke-тест, unit-тесты на SM-2 и детект повторных ошибок, баг-фиксинг | Демо-готовый прототип с тестовым покрытием |
| Янв, нед 1–2 | Security: подпись+идемпотентность вебхуков, owner-check, rate-limit, Sentry+Telescope/Pulse, privacy | Чеклист §7 закрыт |
| Янв, нед 3–4 | Нагрузочный тест: `CapacityPerTask` агента **и** ёмкость LiveKit-ноды, SIGTERM-поведение voice-agent-worker, тюнинг `num_idle_processes`/`load_threshold`, AWS Budgets | Известна реальная ёмкость, стоимость часа диалога, надёжность shutdown |
| Фев, нед 1–2 | AWS: RDS, ElastiCache, ECS Fargate (laravel-app, horizon-worker, reverb, voice-agent-worker в публичных subnet без NAT) + EC2 для LiveKit, S3, Secrets Manager, CI/CD | Инфраструктура поднята |
| Фев, нед 3–4 | **Milestone 2**: деплой, smoke-test в проде, финальная документация | Публично доступный сервис |

---

## 7. Чеклист перед AWS-деплоем

- [ ] Вебхук валидируется по сырому телу и идемпотентен
- [ ] `requestVoiceToken` — owner-check, статус `ready`, idempotency-гард, `VOICE_FLEET_BUSY` при насыщении
- [ ] Rate-limit сессий/день на пользователя
- [ ] `failed` заполняется явным вызовом от агента; `completed`/`abandoned` различаются по инициатору
- [ ] Диалоговая LLM выбрана по бенчмарку; DeepSeek-V3 — только async-роли
- [ ] Разбор ошибок ограничен списком `grammar_points` языка + sentinel `uncategorized`
- [ ] Per-turn latency в CloudWatch, есть P95-дашборд (включая ёмкость LiveKit-ноды)
- [ ] Детект повторных ошибок — `COUNT(DISTINCT session_id)`
- [ ] Sentry подключен для Laravel и Python-агента
- [ ] Consent, retention-политика, удаление по запросу
- [ ] `num_idle_processes`/`max_processes` — из бенчмарка
- [ ] AWS Budgets + трекинг минут голоса на пользователя
- [ ] Тесты: unit (SM-2, детект ошибок), feature (owner-check), e2e smoke
- [ ] LiveKit на EC2 с Elastic IP, TURN-сертификат через Let's Encrypt, `stop_grace_period` под реальную длину звонка
- [x] Lighthouse+Reverb subscriptions подтверждены рабочими (spike 21.09.2026, §5) — polling-fallback не понадобился
- [ ] `voice-agent-worker`/`horizon-worker` в публичном subnet с жёсткой SG (NAT Gateway не используется в V1)

---

## 8. AWS-инфраструктура (Milestone 2)

- **LiveKit — EC2**, Elastic IP, security group с UDP-диапазоном под RTC + TURN/TLS порт, Let's Encrypt-сертификат, деплой-скрипт с длинным `stop_grace_period` (нативный SIGTERM-drain, не кастомный endpoint)
- **RDS MySQL** — `db.t4g.micro/small`
- **ElastiCache Redis** — Horizon-очереди и кэш
- **ECS Fargate**, 4 сервиса: `laravel-app` (за ALB), `horizon-worker`, `reverb` (1 инстанс), `voice-agent-worker` — последние два в **публичном subnet с публичным IP**, NAT Gateway не заводится в V1
- **S3** — presigned upload ассессмента (лимиты по размеру/MIME) + архив транскриптов с lifecycle policy
- **Secrets Manager** — ключи Deepgram/Cartesia/LLM/LiveKit
- **ALB + ACM** — только перед `laravel-app`/Nuxt/Reverb (обычный HTTPS/WSS); не перед LiveKit
- **CloudWatch alarms** — P95 latency, error rate, ECS task count
- **AWS Budgets** — алерт по расходу на STT/TTS/LLM
- **CI/CD** — GitHub Actions → ECR → ECS; LiveKit на EC2 — отдельный шаг (SSH + `docker-compose pull && up -d` с drain, либо Ansible)
