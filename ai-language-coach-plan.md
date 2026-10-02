# AI Language Coach — План разработки (сентябрь 2026 – февраль 2027)

> **Статус на 02.10.2026.** Закрыто: сентябрьский блок целиком (репозиторий, docker-compose, миграции §3, CI) и весь октябрьский — spike Lighthouse↔Reverb (§5), Sanctum-auth, базовые GraphQL-типы, owner-check тесты, генерация роадмапа, assessment-пайплайн и весь фронт (вход, роадмап, онбординг с записью голоса), сквозной прогон в браузере. Закрыт и бэкенд-контур голоса (25.09): `requestVoiceToken` с idempotency и `VOICE_FLEET_BUSY`, самостоятельная подпись LiveKit-токенов на `firebase/php-jwt`, dispatch агента с job-metadata поверх Twirp, вебхук с проверкой подписи и идемпотентными переходами (`completed`/`abandoned`/`failed` по `room_end_reason`), `POST /api/internal/sessions/{id}/fail` под shared-secret'ом. Досрочно, 02.10, закрыта и локальная половина ноябрьского пункта: `scripts/livekit-dev.sh` поднимает нативный livekit-server и шлёт его вебхуки в бэкенд, экран практики `/practice/{cardId}` доводит сессию до живой комнаты — и этот прогон вскрыл три дефекта, невидимых для тестов на моках (голый JWT в вебхуке, серверный токен без claim'а `room`, `duration_sec` из поля, которого сервер не присылает); все три закрыты с регрессионными тестами, падающими на старом коде. Закрыт и сам Python-агент: воркер живёт в этом же репозитории (`agent/`) и поднимается сервисом `voice-agent` в дефолтном профиле compose, так что комната больше не пустая. Сквозной диалог (dispatch → приветствие → распознавание речи → исправление ошибки по целевому правилу → озвученный ответ) пройден, вскрыв дефект пути отчёта о падении (`/api`-префикс, README решение 29). Позже, 02.10, закрыты инструментация per-turn latency (README, решение 32) и суточный лимит голосовых сессий (README, решение 33). Сквозной прогон контейнерного профиля (`docker compose --profile full up -d`) в реальном браузере дошёл до загрузки записи и вскрыл разделение адресов presigned-upload (`AWS_PUBLIC_ENDPOINT`), `SANCTUM_STATEFUL_DOMAINS` (README, решение 31), потерю переменных окружения Lighthouse и ложные статусы unhealthy у Horizon/Reverb (README, решение 34), а также зависание экрана онбординга при сбое ассессмента (README, решение 35). Следом закрыты два бэкенд-среза декабрьского блока: алгоритм повторений SM-2 (`App\Review\Sm2`, мутации `submitReviewResult` и `completeLessonCard` под `lockForUpdate()`, типизированные коды ошибок и их русский перевод) и асинхронный разбор ошибок сессий (джоб `AnalyzeVoiceSessionMistakes`, промпт `DeepSeekMistakeAnalyzer` ограничен каталогом языка с sentinel `uncategorized`, валидация structured output на бэкенде, однократный разбор, ленивые `review_items` с дефолтами SM-2, подписка `sessionFeedbackReady` и запрос `voiceSession(id)`, README решение 36). 277 тестов зелёных (1206 проверок) в Pest + 15 юнит-тестов Python, схема §3 не менялась. Открыто: бенчмарк first-token latency диалоговой LLM (в `.env` есть только ключ DeepSeek — сравнивать пока не с чем), сам LiveKit-сервер на EC2 с TURN/TLS, клиентский UI фидбека и очереди повторений, детект повторных ошибок `COUNT(DISTINCT session_id)`, e2e smoke-тест и Milestone 1, плюс чеклист §7 перед деплоем. Отметки по этапам — в §6, закрытые пункты чеклиста — в §7. Отклонения по стеку, схеме и контракту от §1/§3/§4/§5 зафиксированы в README → «Key decisions & deviations from the plan», пп. 9–36.

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

Реализовано на 02.10.2026: запросы `me`, `roadmap`, `dueReviews`, `mistakes(grammarPointId)`, `voiceSession(id)` (все с owner-check), мутации `login`/`register`/`logout`, `generateRoadmap`, `createAssessmentUploadUrl`, `submitAssessment`, `requestVoiceToken`, `submitReviewResult` (пересчёт SM-2), `completeLessonCard` (под `lockForUpdate()`), а также подписки `assessmentReady` и `sessionFeedbackReady` (README, решения 7, 10, 13, 22–27, 36). Бэкенд-контракт GraphQL закрыт полностью. Ниже приведён контракт схемы; в декабрьском блоке на фронтенде остаётся клиентский UI фидбека по сессии и очереди повторений.

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

**Оповещение о сбое ассессмента — реализовано 02.10.2026.** При терминальном падении джоба `AnalyzeAssessment::failed()` раньше только выставлял `status = failed` и ошибку в `raw_data`, но ничего не рассылал. Экран `onboarding.vue` ждал события `assessmentReady` и оставался на «Расшифровываем и оцениваем» бесконечно (на живом прогоне ассессмент 12 завис в `failed` с ошибкой отсутствия Deepgram-ключа, пока экран продолжал крутить спиннер). Теперь `failed()` рассылает `AssessmentReady` по тому же топику и в том же конверте `{more, result}`, что и успешный путь (README, решение 21), только из статуса `processing` (чтобы не откатить уже завершённую сессию), а ошибки доставки в Redis логируются (`report`), не маскируя ошибку джоба. Фронтенд получил обработку `assessment.status === 'failed'`: выводит понятное сообщение на русском («Не удалось расшифровать или оценить запись. Попробуйте проверить статус ещё раз или запишите рассказ заново.») и кнопку «Записать снова» рядом с «Проверить сейчас». Закреплено в `AnalyzeAssessmentTest` и `AssessmentReadyChannelTest` (в `setUp()` которого исправлена очистка ключей Redis с учётом connection-префикса).

**`requestVoiceToken` резолвер:**
1. Проверить владение уроком и `status === 'ready'`.
2. Idempotency-гард: если есть `voice_sessions` со `status IN ('pending','active')` для пользователя — вернуть её, не создавать новую.
3. Создать `voice_sessions` (`pending`), явный dispatch с job-metadata (`session_id`, `grammar_point_id`, `practice_prompt`).
4. **Насыщение флота:** ждать назначения джоба с таймаутом (~5 сек). Если воркер не нашёлся — типизированная ошибка `VOICE_FLEET_BUSY`, фронт показывает «высокая нагрузка, попробуйте через минуту» с ретраем, а не висит бесконечно.
5. Выпустить access-token LiveKit самостоятельно (`firebase/php-jwt`, ~50 строк) с коротким TTL (10–15 минут, не дефолт SDK), scoped на `room_name`. Пакет `Agence104\LiveKit` из исходной редакции плана заброшен (все версии тянут `firebase/php-jwt` v6 с advisory) — см. README, решение 1.

**Голосовой контур — реализовано 25.09.2026 (бэкенд).** Все пять шагов резолвера собраны в `App\Voice\StartVoiceSession`: owner-check и `ready` через `whereHas('roadmap')`, idempotency-гард на `pending|active`, создание сессии с `room_name = lesson-{id}`, явный dispatch с job-metadata (`session_id`, `grammar_point`, `grammar_point_id`, `practice_prompt`, `target_language`, `level`) и ожидание воркера. Транспорт — Twirp поверх HTTP (три POST'а: `RoomService/CreateRoom`, `AgentDispatchService/CreateDispatch`, `RoomService/ListParticipants`), авторизация — собственный короткий серверный токен; заброшенный PHP-SDK дал бы ровно это и ничего больше. Вызова «скажи, когда агент войдёт» у LiveKit нет, поэтому шаг 4 — это опрос `ListParticipants` до появления участника с `kind == AGENT`; истёкшее ожидание и есть насыщение флота, и оно отдаётся как типизированная ошибка `VOICE_FLEET_BUSY` (`errors[].extensions.code`), а сессия помечается `failed`, чтобы идемпотентность-гард не вернул её при следующей попытке. Вебхук проверяет подпись по сырому телу (JWT с `sha256`-дайджестом тела, `iss` сверяется с нашим API-key), а `completed`/`abandoned`/`failed` различаются по `room_end_reason` (`ROOM_END_API_DELETE` / `ROOM_END_IDLE_TIMEOUT` / `ROOM_END_SERVER_SHUTDOWN`|`ROOM_END_OPEN_FAILED`), а не по тому, кто закрыл комнату. Каждый переход — условный UPDATE под `lockForUpdate()` только из ожидаемых статусов, поэтому повторная доставка события ничего не меняет и не может перевести `completed` в `abandoned`. `POST /api/internal/sessions/{id}/fail` закрыт shared-secret'ом, который **при пустом значении отказывает всем** (503), а не пропускает; причина — allowlist из четырёх значений. Отклонения и ловушки — README, решения 22–25. Схема §3 не менялась: новых колонок не потребовалось. Осталось от ноябрьского пункта: сам LiveKit-сервер на EC2. Локальная половина закрыта 02.10: `scripts/livekit-dev.sh` (нативный livekit-server с вебхуками на бэкенд) и экран практики `/practice/{cardId}` — см. §6 и README, решения 26–28. Сам воркер тоже закрыт 02.10 и живёт в репозитории (`agent/`, сервис `voice-agent` в compose): dispatch принимается за доли секунды, агент читает job-metadata, входит в комнату как `AGENT` и ведёт диалог; живой прогон вскрыл, что отчёт о падении уходил на путь без префикса `/api` (README, решение 29).

**Вебхук `/api/webhooks/livekit`** (реализовано 25.09.2026): сырое тело, проверка подписи своей реализацией на `firebase/php-jwt` — LiveKit подписывает вебхук тем же ключом, что и access-token'ы, поэтому второго секрета не нужно (BCMath в PHP-образе; готового receiver'а нет — тот же заброшенный пакет). Идемпотентно: переход применяется только из ожидаемого текущего статуса, повторный `room_finished` при уже терминальном статусе игнорируется. `participant_joined` → `active` (только от ученика — приход самого агента сессию не активирует: разговор ещё не начался). `room_finished`: `completed` при `ROOM_END_API_DELETE` (агент дошёл до конца сценария и закрыл комнату сам), `abandoned` при `ROOM_END_IDLE_TIMEOUT`, `failed` при `ROOM_END_SERVER_SHUTDOWN`/`ROOM_END_OPEN_FAILED`; неизвестная причина трактуется как `completed`, а не как уход ученика. `room_started` — намеренный no-op. Событие для незнакомой комнаты получает `200 {applied: false}`, а не ошибку: LiveKit ретраит любой не-2xx, а ретраить нечего.

**Статус `failed`** (реализовано 25.09.2026): `POST /api/internal/sessions/{id}/fail` (shared secret), агент вызывает сам при падении STT/TTS/LLM перед дисконнектом. Причина — allowlist (`stt_failed`, `tts_failed`, `llm_failed`, `agent_error`), потому что значение хранится и показывается в истории. `404` покрывает и «нет такой сессии», и «уже терминальная»: агенту незачем их различать, а повторный отчёт не то, что стоит ретраить. Пустой `VOICE_INTERNAL_SECRET` — 503 на любой запрос, а не открытый эндпоинт, пишущий в БД.

**LiveKit — обновление и drain:** сервер уже умеет нативный graceful drain по `SIGTERM`/`SIGINT`/`SIGQUIT` (не принимает новые комнаты, доигрывает активные, завершается сам) — деплой-скрипт должен просто дать ему время: `stop_grace_period` на порядок больше длины звонка (10–15 мин), а не рвать процесс дефолтным таймаутом. Для Python voice-agent-worker отдельно: пин версии `livekit-agents` и явная проверка SIGTERM-поведения в январском нагрузочном тесте (были открытые баги с надёжностью сигнала при блокирующем I/O в `request_fnc`); `AgentServer(drain_timeout=...)` выставить под реальную длину спринтов, `stopTimeout` в ECS task definition — не короче.

**TURN/TLS:** используется встроенный TURN LiveKit. Сертификат — **Let's Encrypt/certbot напрямую на EC2**, не ACM (ACM не отдаёt приватный ключ наружу, а LiveKit требует `cert_file`/`key_file`). Отдельный домен под TURN либо тот же сертификат с SAN.

**Диалоговая LLM:** обязательный бенчмарк first-token latency до интеграции (§6, нояб. нед. 1). DeepSeek-V3 подтверждён только для async-разбора и CEFR-оценки — там цена важнее скорости.

**Ограничение LLM при разборе ошибок — реализовано 02.10.2026.** Асинхронный разбор ошибок реализован в джобе `App\Mistakes\AnalyzeVoiceSessionMistakes` (очередь Horizon, timeout 300 с, 3 попытки с бэкоффом [30, 120]), который диспатчится из `App\Voice\VoiceSessionLifecycle` при переходе сессии в терминальный статус (`completed`, `abandoned`, `failed`), если в `voice_sessions.transcript` есть хотя бы одна реплика ученика. LLM-граница `App\Mistakes\MistakeAnalyzer` / `DeepSeekMistakeAnalyzer` формирует промпт строго из канонического каталога `grammar_points` для целевого языка пользователя (`$user->target_language`) и ограничивает JSON-схему допустимыми кодами правил (`$allowedCodes`). Если модель не может уверенно сопоставить ошибку или возвращает неизвестный код, ошибка мапится на sentinel `code = "uncategorized"` (у `mistakes.grammar_point_id` ограничение NOT NULL и внешний ключ, поэтому невалидный код уронил бы запись в БД). Как и в ассессменте (README, решение 16), SDK не валидирует structured output — ответ DeepSeek проверяется валидатором в `DeepSeekMistakeAnalyzer` (наличие непустых `user_utterance`, `correction`, `explanation`, `grammar_point_code`), и при невалидной структуре выбрасывается `MistakeAnalysisFailed`, роняющий джоб для ретрая вместо записи битых данных. Джоб защищён от повторного выполнения (проверка наличия ошибок и метки `type => 'analysis'` в транзакции под `lockForUpdate()`), лениво создаёт записи `review_items` с дефолтными параметрами SM-2 (`ease_factor = 2.50`, `interval_days = 1`, `repetition_number = 0`, `next_review_at = NOW() + 1 день`) без перезаписи уже существующих графиков повторения, добавляет отметку `analysis` в транскрипт и оповещает фронтенд через подписку `sessionFeedbackReady(sessionId)`. Ошибки рассылки подписки логируются (`report`), не роняя завершённый анализ. Закреплено в `AnalyzeVoiceSessionMistakesTest` (7 тестов), `DeepSeekMistakeAnalyzerTest` (9 тестов), `VoiceSessionMistakeDispatchTest` (7 тестов), `SessionFeedbackReadyChannelTest` (6 тестов) и `SessionFeedbackReadySubscriptionTest` (README, решение 36).

**Cheat sheet и practice prompt — реализовано 24.09.2026.** Контент живёт в `backend/resources/grammar/{language}.php`: версионируемый каталог (код, категория, CEFR-уровень, `cheat_sheet {rule, formula, examples, pitfalls}`, `practice_prompt`). `GrammarPointSeeder` раскладывает его в `grammar_points` вместе с sentinel'ом, `RoadmapGenerator` — в `lesson_cards` (cheat sheet копируется в карту, чтобы позже её можно было заменить персональной LLM-версией). Английский каталог — 32 пункта A1–C1; схема §3 при этом не менялась. Выбор по уровню: все пункты не выше `users.current_level`, от слабых к сильным, первая карта `ready`, остальные `locked`. Генерация идемпотентна (повтор возвращает существующий активный роадмап), `regenerate()` архивирует предыдущий — его карты остаются, потому что на них ссылаются `mistakes` и `voice_sessions`.

**Инструментация latency по репликам — реализовано 02.10.2026.** Агент в `agent/agent.py` подписывается на событие `metrics_collected` на `AgentSession` (livekit-agents 1.8) и сопоставляет фазы по `speech_id`: `stt_final` — от конца реплики ученика (`max(end_of_utterance_delay, transcription_delay)`, потому что окно тишины turn-детектора ученик тоже ждёт), `llm_first_token` из `LLMMetrics` (`ttft`), `tts_first_chunk` из `TTSMetrics` (`ttfb`, только первый чанк первого предложения хода). `total_turnaround` вычисляется как сумма доступных фаз. По первому чанку TTS (или таймауту 2.0 с) воркер логирует структурированную JSON-строку `TURN_LATENCY {"event": "voice_turn_latency", ...}` в stdout (для CloudWatch Logs) и асинхронно отправляет `{turn_id, transcript, stt_final, llm_first_token, tts_first_chunk, total_turnaround}` в миллисекундах (`ms`) на бэкенд в `POST /api/internal/sessions/{id}/turns`. Эндпоинт защищён middleware `VerifyInternalSecret` (`X-Internal-Secret`), валидирует неотрицательные значения и верхние границы (60 с на этап, 120 с на весь оборот). В `VoiceSessionLifecycle::recordTurn()` в транзакции под `lockForUpdate()` повторно доставленный `turn_id` (или алиас `speech_id`) обновляет запись на месте, исключая дубликаты в JSON-колонке `voice_sessions.transcript`. Терминальные сессии (`completed`, `abandoned`, `failed`) принимают отчёт без изменения статуса, чтобы телеметрия последнего хода не терялась при гонке закрытия комнаты с HTTP-вызовом. Закреплено тестами `RecordVoiceSessionTurnTest` (14 тестов) и `test_turn_metrics.py` (15 тестов). Непосредственный синк в CloudWatch Metrics и дашборд P95 вынесены в чеклист §7.

**Дневной лимит сессий на пользователя — реализовано 02.10.2026.** Пункт чеклиста §7 («Rate-limit сессий/день на пользователя») защищает от бесконтрольного расхода бюджета на LiveKit-диспетчеризацию и внешние модели (STT/TTS/LLM). Конфигурация: `config('voice.daily_session_limit')` (`VOICE_DAILY_SESSION_LIMIT`, дефолт 10 сессий в календарный день). Проверка в `StartVoiceSession` выполняется внутри транзакции под пессимистичной блокировкой строки пользователя (`User::query()->whereKey($user->getKey())->lockForUpdate()->first()`). Блокировка строки пользователя необходима вместо unique constraint, поскольку лимит вычисляется как агрегат за скользящее временное окно (`where('created_at', '>=', Date::now('UTC')->startOfDay())->count()`), а не статическое ограничение колонок; параллельные запросы (двойной клик, несколько вкладок) без блокировки создали бы гонку и превысили квоту. При этом идемпотентность имеет безусловный приоритет: проверка существующей `pending`/`active` сессии для карточки выполняется *до* проверки лимита — ученик может вернуться в текущий звонок даже при исчерпанной квоте. В лимит засчитываются все сессии пользователя за текущие календарные сутки UTC, включая `failed` и `abandoned`, так как создание сессии диспатчит комнату и воркера, потребляя ресурсы инфраструктуры независимо от успешности разговора. Граница суток — полночь по UTC (`Date::now('UTC')->startOfDay()`), так как все таймстемпы хранятся в UTC. При превышении выбрасывается `VoiceDailyLimitReached` (`ClientAware`), отдающий код ошибки `VOICE_DAILY_LIMIT_REACHED` (`errors[].extensions.code`), который экран практики (`useVoiceSession.ts`) транслирует в сообщение: «Дневной лимит голосовых сессий исчерпан. Попробуйте снова завтра.». Закреплено тестами `RequestVoiceTokenTest` (7 тестов).

**SM-2 и завершение карточек — реализовано 02.10.2026.** Чистая арифметика алгоритма SuperMemo 2 вынесена в `App\Review\Sm2`: формула нового `ease_factor` с защитным порогом `MIN_EASE_FACTOR = 1.30`, лесенка интервалов (первое успешное повторение — 1 день, второе — 6 дней, последующие — `round(interval * ease_factor)`), и сброс интервала в 1 день и счетчика повторений в 0 при неуспешной оценке качества (`quality < 3`). Записи `review_items` создаются лениво при первой `mistake` на пару (user, grammar_point) с дефолтами `ease_factor = 2.50`, `interval_days = 1`, `repetition_number = 0`, `next_review_at = NOW() + 1 день`. Мутация `submitReviewResult(grammarPointId, quality)` валидирует оценку от 0 до 5 (`SubmitReviewResultValidator`), находит `review_items` под `lockForUpdate()` с проверкой владельца и возвращает типизированные коды ошибок `REVIEW_ITEM_NOT_FOUND` или `GRAMMAR_POINT_NOT_FOUND`. Мутация `completeLessonCard(lessonCardId)` (`CompleteLessonCardValidator`) под пессимистичной блокировкой `lockForUpdate()` переводит карточку пользователя из `ready` в `completed` и автоматически разблокирует следующую карточку роадмапа (`order_index + 1` → `ready`); операция идемпотентна, а попытка завершить заблокированную карточку отдаёт типизированную ошибку `LESSON_CARD_LOCKED` (`LESSON_CARD_NOT_FOUND` при отсутствии карточки у пользователя). Ошибки переведены на русский язык в `frontend/app/composables/useVoiceSession.ts`. Закреплено в `backend/tests/Unit/Review/Sm2Test.php` (9 тестов), `backend/tests/Feature/Review/SubmitReviewResultTest.php` (8 тестов) и `CompleteLessonCardTest.php` (8 тестов).

**Voice Fleet capacity:** первично регулируется `num_idle_processes`/`max_processes` внутри уже запущенных Fargate-тасков, ECS-автоскейл — вторая, медленная линия. `CapacityPerTask` **и** ёмкость самой LiveKit-ноды — оба бенчатся в январе (§6), не закладываются на глаз.

**Lighthouse subscriptions ↔ Reverb — риск закрыт 21.09.2026 (spike пройден досрочно).** Связка работает: `LIGHTHOUSE_BROADCASTER=reverb` — это тот же pusher-драйвер, но смотрящий в `broadcasting.connections.reverb`, а не в Pusher Cloud. Проверено сквозным прогоном: подписка уходит по HTTP с заголовком `X-Socket-ID` → в ответе приходит приватный канал `private-lighthouse-…` → клиент авторизует его через `POST /graphql/subscriptions/auth` → результат приезжает событием `lighthouse-subscription`. **Polling-fallback не нужен.** Три вещи, без которых путь молча ломается (все три закреплены тестом `SubscriptionWiringTest`): `SubscriptionServiceProvider` обязан быть зарегистрирован в `bootstrap/providers.php` — Lighthouse его не авто-обнаруживает; `LIGHTHOUSE_SUBSCRIPTION_STORAGE=redis` обязателен, иначе `CacheStorageManager` упирается в `cache.serializable_classes => false`; клиент обязан говорить по протоколу Pusher (`laravel-echo`/`pusher-js`), а не `graphql-ws`. Поля подписки объявлять nullable — при подписке они резолвятся в `null`. При запуске через `docker compose --profile full` те же ключи `LIGHTHOUSE_BROADCASTER: reverb`, `LIGHTHOUSE_SUBSCRIPTION_STORAGE: redis` и `LIGHTHOUSE_QUERY_CACHE_MODE: opcache` явно передаются через `x-laravel-environment`, поскольку `backend/.env` намеренно исключён из контейнерных образов (README, решение 34).

**Privacy:** consent на онбординге перед записью голоса; retention транскриптов через S3 lifecycle policy; эндпоинт удаления по запросу; сырое аудио не хранится дольше времени обработки STT.

---

## 6. План по этапам

| Период | Фокус | Результат | Статус |
|---|---|---|---|
| 15–30 сент | Repo, docker-compose (MySQL+Redis+Laravel+Nuxt), CI skeleton, миграции по схеме §3 | `docker-compose up` поднимает всё локально | ✅ 21.09 — с отличием: в Docker MySQL+Redis, Laravel/Nuxt запускаются нативно. Позже, 02.10, в дефолтный профиль добавлены LiveKit и голосовой агент, а весь стек целиком доступен через `--profile full` (README → Containers) |
| Окт, нед 1 | **Spike: Lighthouse subscriptions + Reverb** (pusher-driver подход, fallback на polling если не заведётся) | Известно, работает ли связка, до того как на неё завязан декабрьский план | ✅ 21.09, досрочно — работает, polling-fallback не понадобился (§5) |
| Окт, нед 1–2 | Sanctum-auth, базовые GraphQL-типы, feature-тесты на auth/owner-check | `me`/`roadmap` отдают данные, тесты зелёные | ✅ 21.09, досрочно — 37 тестов зелёные, изоляция владельца покрыта |
| Окт, нед 3–4 | ~~Генерация roadmap~~, ~~`createAssessmentUploadUrl`+`submitAssessment`+async job (Deepgram batch→LLM→CEFR)~~, ~~Sanctum handshake + экран роадмапа/cheat sheet~~, ~~экран онбординга с записью (consent → MediaRecorder → presigned POST → `assessmentReady`)~~ | Пункт закрыт 24.09 целиком: 121 тест, пайплайн и подписка прогнаны вживую (Deepgram/DeepSeek, MinIO, Reverb), вход и запись голоса пройдены в браузере | ✅ |
| Нояб, нед 1 | **Бенчмарк first-token latency диалоговой LLM** | Модель для реплик выбрана по данным |
| Нояб, нед 1–2 | LiveKit self-hosted на EC2 + Elastic IP, TURN/TLS (Let's Encrypt), `requestVoiceToken` с idempotency и `VOICE_FLEET_BUSY` | Бэкенд-половина закрыта 25.09: `requestVoiceToken` (owner-check, `ready`, idempotency, dispatch с metadata, ожидание воркера → `VOICE_FLEET_BUSY`), подпись access-token'ов, вебхук с проверкой подписи и идемпотентными переходами, `POST /api/internal/sessions/{id}/fail`. Осталось: LiveKit-сервер на EC2 с TURN/TLS. Досрочно, 02.10, закрыта и локальная половина: `scripts/livekit-dev.sh` поднимает нативный livekit-server и шлёт его вебхуки в бэкенд, экран `/practice/{cardId}` доводит сессию до комнаты, а живой прогон вскрыл три дефекта, невидимых для моков (голый JWT в вебхуке, серверный токен без claim'а `room` и `duration_sec` из поля, которого сервер не присылает; README, решения 26–28). Тогда же, 02.10, закрыт и сам Python-агент: `agent/` + сервис `voice-agent` в дефолтном профиле compose, сквозной диалог пройден живым прогоном, который вскрыл четвёртый дефект того же класса (отчёт о падении без префикса `/api`, README решение 29) | 🔶 |
| Нояб, нед 3–4 | Интеграция LLM в агента, latency-инструментация, идемпотентная обработка `room_finished`/`failed`, ограничение LLM списком grammar_points | Диалоговый спринт с таймингами, вебхук не дублирует обработку. Идемпотентная обработка закрыта 25.09 (условные переходы под `lockForUpdate()`, README решение 24). 02.10 закрыта интеграция LLM (`agent/agent.py`) и инструментация latency: агент собирает метрики фаз (STT, LLM TTFT, TTS TTFB) и пишет в `voice_sessions.transcript` через `POST /api/internal/sessions/{id}/turns` + структурированный JSON-лог в stdout. Ограничение LLM каноническим списком `grammar_points` относится к async-разбору ошибок (дек, нед 1–2; §7), а прямая отправка в CloudWatch и дашборд P95 остаются задачей перед деплоем (§7) | 🔶 |
| Дек, нед 1–2 | Async-разбор ошибок, `submitReviewResult`/`completeLessonCard` (с lockForUpdate), `assessmentReady`/`sessionFeedbackReady` subscriptions | Бэкенд закрыт 02.10: асинхронный разбор ошибок (`AnalyzeVoiceSessionMistakes` + `DeepSeekMistakeAnalyzer` с каталогом и sentinel), `submitReviewResult` (SM-2), `completeLessonCard` (под `lockForUpdate`), обе подписки (`assessmentReady` и `sessionFeedbackReady`) и запрос `voiceSession(id)`. Не реализовано: клиентский UI (экран фидбека и очередь повторений) и детект повторных ошибок `COUNT(DISTINCT session_id)` | 🔶 02.10 |
| Дек, нед 3–4 | **Milestone 1**: сквозной прогон, e2e smoke-тест, unit-тесты на SM-2 и детект повторных ошибок, баг-фиксинг | Демо-готовый прототип с тестовым покрытием |
| Янв, нед 1–2 | Security: подпись+идемпотентность вебхуков, owner-check, rate-limit, Sentry+Telescope/Pulse, privacy | Чеклист §7 закрыт |
| Янв, нед 3–4 | Нагрузочный тест: `CapacityPerTask` агента **и** ёмкость LiveKit-ноды, SIGTERM-поведение voice-agent-worker, тюнинг `num_idle_processes`/`load_threshold`, AWS Budgets | Известна реальная ёмкость, стоимость часа диалога, надёжность shutdown |
| Фев, нед 1–2 | AWS: RDS, ElastiCache, ECS Fargate (laravel-app, horizon-worker, reverb, voice-agent-worker в публичных subnet без NAT) + EC2 для LiveKit, S3, Secrets Manager, CI/CD | Инфраструктура поднята |
| Фев, нед 3–4 | **Milestone 2**: деплой, smoke-test в проде, финальная документация | Публично доступный сервис |

**Голосовой контур впервые пройден живым сервером (02.10.2026).** Локальный `livekit-server` (`scripts/livekit-dev.sh`, нативно, с вебхуками в бэкенд) и настоящий браузер нашли то, чего сюита на моках увидеть не может, — и это оказались не мелочи. Первое: вебхук подписывается **голым JWT**. LiveKit ставит `Authorization: <jwt>` без префикса `Bearer` и `Content-Type: application/webhook+json`, а парсер требовал `^Bearer\s+`, поэтому каждое реальное событие отбивалось как неподписанное (401) — при зелёном `LiveKitWebhookTest`, который слал `Bearer`. Второе, тяжелее: серверный токен выдавался с грантами `roomCreate/roomList/roomAdmin`, но без claim'а `room`, а room-scoped admin-методы LiveKit (`AgentDispatchService/CreateDispatch`, `RoomService/ListParticipants`) сверяют грант с комнатой из запроса, — то есть `requestVoiceToken` не работал против настоящего сервера ни разу: `401 permissions denied`, который наружу выходил как `Internal server error` со stack trace'ом в теле ответа, а `RequestVoiceTokenTest` этого не видел, потому что мокает `LiveKitApi`. Третье, из того же ряда: `duration_sec` не заполнялся никогда, потому что контроллер читал `room.createdAt`, а сервер присылает `room.creationTime`. Все три закрыты и все три пойманы тестами, которые падают на старом коде (README, решения 26–28). После фиксов сквозной прогон в браузере: без воркера экран честно показывает `VOICE_FLEET_BUSY` по-русски, а сессия уходит в `failed/voice_fleet_busy`; с временным агентом-заглушкой (живёт вне репозитория и не заменяет ноябрьский воркер) `requestVoiceToken` возвращает `active` с токеном, браузер открывает WebSocket к LiveKit, на экране «Идёт сессия» и «Агент на связи», воркер получает job с метаданными урока; после ухода обоих участников комната закрывается по idle-таймауту, `room_finished` доезжает подписанным и переводит сессию в `abandoned`. Комнаты не утекли.

**Тот же прогон с настоящим агентом (02.10.2026, позже в тот же день).** Воркер перестал быть заглушкой и живёт в репозитории: `agent/agent.py` + сервис `voice-agent` в дефолтном профиле `docker-compose`. Участник с `kind == AGENT` появляется в комнате за 0.26–0.40 с при таймауте `VOICE_AGENT_JOIN_TIMEOUT` в 5 с, то есть `requestVoiceToken` отвечает `active` без ожидания на грани; агент читает job-metadata и произносит вступительную реплику, а поданная в комнату фраза возвращается транскриптом, исправлением ошибки по целевому правилу (`present_perfect`) и озвученным ответом. Два звена, которые в этом прогоне оказались нерабочими, — поучительные. Первое: путь отчёта о падении собирался как `/internal/sessions/{id}/fail` без префикса `/api`, который даёт `routes/api.php`, поэтому **каждый** отчёт агента получал `404` и не записывался: сессия, убитая сломанным LLM, оставалась `active` до 300-секундного idle-таймаута и закрывалась как `abandoned`, то есть вина за брошенный звонок ложилась на ученика. `FailVoiceSessionTest` этого не видел и не мог — он сам постит на правильный путь, а расхождение жило в Python-строке. Второе: `LIVEKIT_URL` обслуживает двух разных клиентов — сам бэкенд (Twirp) и браузер ученика (WebRTC), — и в контейнере это не может быть одна строка: браузер не резолвит `livekit`, а API-контейнер не достаёт `127.0.0.1:7880`. Появился nullable `LIVEKIT_PUBLIC_URL`, на который `VoiceSessionConnection@url` падает обратно к `url`, когда он не задан, и то же разделение «внутренний/публичный адрес» теперь явно проведено через переменные профиля `--profile full`. Все три дефекта закрыты (README, решения 29–31): presigned-upload получил разделение адресов через `AWS_PUBLIC_ENDPOINT` (с явным отказом от virtual-host адресации и путей в публичном URL), а контейнерный бэкенд — `SANCTUM_STATEFUL_DOMAINS` для авторизации SPA.

**Сквозной прогон контейнерного профиля `--profile full` в браузере (02.10.2026).** Попытка пройти полный цикл онбординга и авторизации внутри контейнеров (`docker compose --profile full up -d`) в реальном браузере показала, что профиль ранее никогда не мог работать end-to-end: образ API собирается без `backend/.env`, а в YAML-анкоре `x-laravel-environment` отсутствовала существенная часть настроек. Прогон вскрыл следующие проблемы конфигурации и хелсчеков:
1. `LIGHTHOUSE_BROADCASTER: reverb` не был передан — Lighthouse откатывался к дефолту `pusher`, у которого не задан `auth_key`. При попытке авторизации приватного канала `POST /graphql/subscriptions/auth` конструктор `Pusher` падал с `TypeError` (`Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given`) и возвращал HTTP 500, канал не подтверждался, и экран онбординга сразу показывал ошибку подписки ещё до начала загрузки аудио (README, решение 34).
2. `LIGHTHOUSE_QUERY_CACHE_MODE: opcache` отсутствовал — в дефолтном режиме `store` AST-документы сериализовались в кэш, а при `cache.serializable_classes => false` возвращались как `__PHP_Incomplete_Class` с 500 на повторных запросах (`QueryCache::fromStoreOrParse()`).
3. `LIGHTHOUSE_SUBSCRIPTION_STORAGE: redis` также был добавлен в анкор как явное объявление для синхронизации с `backend/.env`. В отличие от кэша запросов, само хранилище подписок в контейнере фактически не ломалось (в `config/lighthouse.php` дефолтом уже задан `'redis'`, который через `REDIS_HOST=redis` разрешает кэш-стор), но явное указание гарантирует полный паритет настроек контейнера с хостом.
4. Контейнеры `horizon` и `reverb` наследовали проверку `wget http://127.0.0.1:8080/up` из Dockerfile бэкенда, которую ни один из них не обслуживает (Horizon — CLI-процесс, Reverb слушает порт 8081 и отдаёт 404 на `GET /`). В результате `docker ps` помечал оба контейнера как `unhealthy` при абсолютно корректно работающих сервисах. Хелсчеки переопределены: Horizon проверяется через `php artisan horizon:status` (код 0 при живом воркере), Reverb — сокет-пробой порта 8081 (`@fsockopen`).

После исправления переменных окружения и хелсчеков прогон в браузере дошёл до отправки записи в бакет MinIO и постановки джоба в очередь Horizon, где упал только из-за намеренно не заполненных ключей внешних провайдеров (Deepgram/DeepSeek). Это вскрыло критичный дефект в пайплайне ассессмента:
- **Терминальный сбой не доходил до экрана ученика.** Метод `AnalyzeAssessment::failed()` записывал статус `failed` и ошибку в `raw_data` (в БД строка 12 упала с `Deepgram API key is not properly configured`), но ничего не бродкастил. Экран `onboarding.vue` ожидал события подписки `assessmentReady` и оставался на «Расшифровываем и оцениваем. Обычно это занимает меньше минуты.» бесконечно. Теперь `failed()` рассылает `AssessmentReady` по тому же топику и в том же конверте `{more, result}`, что и успешный путь (README, решение 21), только из статуса `processing` и без проброса наружу исключений Redis-доставки. Фронтенд получил обработку статуса `failed`: выводит понятное сообщение на русском и кнопку «Записать снова» рядом с «Проверить сейчас» (README, решение 35).

**Дефект входа разобран и закрыт (24.09.2026).** Причин оказалось три: первая объясняет наблюдение из Telescope, вторая и третья нашлись при сквозном прогоне записи голоса — и все три лежат на клиенте.

**1. `login` → `me` → падение на экране роадмапа.** `app/plugins/urql.ts` отдавал клиент только как Nuxt-свойство (`provide: { urql }`), то есть в `$urql`. Этого достаточно для `$urql.query()` в `useAuth()`, но `useQuery()` из `@urql/vue` берёт клиент из Vue-injection и падает с «No urql Client was provided». Падал именно `roadmap.vue` — единственный вызов `useQuery()` в приложении, — и падал **до** запроса `Roadmap`, поэтому в Telescope его и не было: серверная часть входа была в порядке ровно так, как она выглядела. Исправление: плагин дополнительно вызывает `installUrql(nuxtApp.vueApp, client)`, тот же экземпляр остаётся и в `$urql` (README, решение 20). Экран входа в этой картине не «залипал», а показывал ошибку — расхождение с исходной формулировкой дефекта, скорее всего, накопленное состояние HMR в dev-сессии; воспроизведение с чистого профиля дало 500 на роадмапе и ошибку в консоли, а не отклонённый переход.

Проверено в headless Chrome с чистого профиля: гость на `/roadmap` → `/login`; после отправки формы браузер делает `GET Me`, `POST Login`, `GET Me`, **`GET Roadmap`** и рисует роадмап (`21 уроков · сейчас Present Simple`), ошибок в консоли нет; «Выйти» возвращает на `/login`.

**2. Канал подписки не авторизовался, поэтому push'у было некуда прийти.** Прогон записи голоса через браузер (синтетический микрофон, MediaRecorder → presigned POST в MinIO → `submitAssessment`) показал, что пайплайн отрабатывает и `assessmentReady` рассылается, а экран остаётся на «Расшифровываем и оцениваем». Причина: `config/cors.php` перечислял `graphql`, а маршрут авторизации канала — `/graphql/subscriptions/auth`; `Str::is()` сопоставляет `graphql` только с точным путём, поэтому браузер выбрасывал ответ без CORS-заголовков, pusher-js сообщал «Failed to fetch», `pusher:subscribe` не отправлялся, и подписка оставалась мёртвой — при живом сервере и живом WebSocket. Исправление: `graphql/*` в списке путей, плюс `useAssessment().onReady()` теперь дожидается подтверждения подписки (`channel.subscribed()`, 10 с) и только потом отдаёт запись на загрузку — неудачный хендшейк виден на экране, а не превращается в бесконечное ожидание.

**3. Слушатель читал не тот конверт.** После починки CORS событие `lighthouse-subscription` доехало до страницы (видно в кадрах WebSocket), но экран всё равно не двигался: `PusherBroadcaster::broadcast()` отправляет `{more: true, result: <результат выполнения>}`, а подписка читала `payload.data.assessmentReady` вместо `payload.result.data.assessmentReady` — то есть обработчик не мог сработать никогда, даже при доставленном событии. Исправление в `useAssessment()`, форма конверта зафиксирована комментарием.

Проверено вживую целиком, с чистого профиля браузера: запись → `CreateAssessmentUploadUrl` → `POST` в бакет (204) → `SubmitAssessment` (`processing`) → джоб Deepgram+DeepSeek (4 с) → `pusher_internal:subscription_succeeded` → событие `lighthouse-subscription` с `{"id":"9","status":"done","cefrLevel":"B1"}` → экран «Готово — ваш уровень B1», ошибок в консоли нет. `AssessmentReadyChannelTest` дополнен проверкой CORS-заголовка на ответе авторизации: серверный тест не видит заголовок, который нужен браузеру, поэтому проверять его надо явно.

Урок для §7 и для ноябрьского голосового цикла: подписка проверяется только браузером. Серверные тесты (в том числе `SubscriptionWiringTest` и `AssessmentReadyChannelTest`) зелёные на всех трёх дефектах — они не видят ни Vue-injection, ни CORS-заголовков, ни формы конверта. Сквозной прогон в браузере — единственная проверка, которая их ловит.

---

## 7. Чеклист перед AWS-деплоем

- [x] Вебхук валидируется по сырому телу и идемпотентен (25.09.2026 — подпись JWT с `sha256`-дайджестом тела, сверка `iss`, условные переходы под `lockForUpdate()`; `LiveKitWebhookTest`; 02.10.2026 — принимается и голый JWT, который шлёт настоящий сервер, а не только `Bearer`, см. README решение 26 и тест, падающий на старом парсере)
- [x] `requestVoiceToken` — owner-check, статус `ready`, idempotency-гард, `VOICE_FLEET_BUSY` при насыщении (25.09.2026 — `RequestVoiceTokenTest`, 13 тестов; 02.10.2026 — впервые пройден против настоящего livekit-server: dispatch принят, комната открыта, браузер в ней; для этого серверный токен пришлось привязать к комнате, README решение 27)
- [x] Rate-limit сессий/день на пользователя (02.10.2026 — `VOICE_DAILY_SESSION_LIMIT`, дефолт 10 сессий/день; проверка в `StartVoiceSession` под пессимистичной блокировкой строки пользователя, учёт всех сессий за сутки UTC включая `failed`/`abandoned`, приоритет у существующей активной/pending сессии, код ошибки `VOICE_DAILY_LIMIT_REACHED`; `RequestVoiceTokenTest`)
- [x] `failed` заполняется явным вызовом от агента; `completed`/`abandoned` различаются по инициатору (25.09.2026 — по `room_end_reason`: `ROOM_END_API_DELETE` → `completed`, `ROOM_END_IDLE_TIMEOUT` → `abandoned`, `ROOM_END_SERVER_SHUTDOWN`/`OPEN_FAILED` → `failed`; `FailVoiceSessionTest`)
- [ ] Диалоговая LLM выбрана по бенчмарку; DeepSeek-V3 — только async-роли
- [x] Разбор ошибок ограничен списком `grammar_points` языка + sentinel `uncategorized` (02.10.2026 — джоб `AnalyzeVoiceSessionMistakes`, промпт `DeepSeekMistakeAnalyzer` ограничен каталогом языка с enum в JSON-схеме и fallback на sentinel `uncategorized`, валидация ответа на бэкенде, ленивое создание `review_items`; `DeepSeekMistakeAnalyzerTest`, `AnalyzeVoiceSessionMistakesTest`)
- [ ] Per-turn latency в CloudWatch, есть P95-дашборд (включая ёмкость LiveKit-ноды) (02.10.2026 — в коде закрыта первая половина: агент собирает STT/LLM/TTS задержки по LiveKit 1.8 событиям и шлёт в `voice_sessions.transcript` через `POST /api/internal/sessions/{id}/turns` + пишет структурированный JSON в stdout; остаётся: CloudWatch Logs/Metrics sink и P95-дашборд)
- [ ] Детект повторных ошибок — `COUNT(DISTINCT session_id)`
- [ ] Sentry подключен для Laravel и Python-агента
- [ ] Consent, retention-политика, удаление по запросу
- [ ] `num_idle_processes`/`max_processes` — из бенчмарка
- [ ] AWS Budgets + трекинг минут голоса на пользователя
- [ ] Тесты: unit (SM-2, детект ошибок), feature (owner-check), e2e smoke (02.10.2026 — частично: unit-тесты на чистую арифметику SM-2 в `backend/tests/Unit/Review/Sm2Test.php` и feature-тесты на owner-check/мутации/подписки закрыты; детект повторных ошибок `COUNT(DISTINCT session_id)` и e2e smoke-тест ещё не реализованы)
- [ ] LiveKit на EC2 с Elastic IP, TURN-сертификат через Let's Encrypt, `stop_grace_period` под реальную длину звонка
- [x] Lighthouse+Reverb subscriptions подтверждены рабочими (spike 21.09.2026, §5 — polling-fallback не понадобился; 02.10.2026 — подтверждены и под docker compose --profile full после проброса LIGHTHOUSE_BROADCASTER/STORAGE/QUERY_CACHE_MODE в x-laravel-environment, README решение 34)
- [x] Голосовой контур на бэкенде: подпись токенов, dispatch агента, вебхук, `failed`-эндпоинт (25.09.2026 — README, решения 22–25)
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
