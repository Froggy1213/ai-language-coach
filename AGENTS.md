# Kirie — голосовой AI-коуч английского

Продукт: японоязычный ученик учит английский (CEFR A1–C1). Внутри — ассессмент уровня по
записи голоса, персональный грамматический роадмап, голосовой диалог с агентом в реальном
времени (режим урока с целевым правилом и режим ролевого сценария с разбором после
разговора) и интервальное повторение ошибок по SM-2. Интерфейс **японский**, учебный
контент **английский** и приходит из API. Бренд на экране — **Kirie**; репозиторий и имена
образов остаются `ai-language-coach`.

Этот файл — маршрутизация: где что лежит, чем гейтится изменение, что связывает. Канон —
в документах, на которые он ссылается; не дублируй их здесь.

## Холодный старт

1. `docs/memory/progress.md` — где остановилась прошлая сессия.
2. `docs/memory/decisions.md` — фильтр по своей области; не пере-решай без новых данных.
3. `docs/memory/gotchas.md` — перед правкой того, что там названо.
4. `README.md` — стек, локальный запуск, архитектурные инварианты, CI.
5. `AGENTS.md` подсистемы, в которой будешь менять код (карта ниже).

## Карта

| Путь | Что это | Канон |
|---|---|---|
| `backend/` | Laravel 13 + Lighthouse GraphQL (`/graphql`), Sanctum (SPA-куки + CSRF), Reverb, Horizon. Домен — папка в `backend/app/`: `Voice`, `Grammar`, `Review`, `Scenarios`, `Assessments`, `Mistakes`, `Privacy`, `Billing`, `Observability`; рядом инфраструктурные `GraphQL`, `Console`, `Providers`, `Support`, `Enums`, `Rules`, `Models`, `Http`. Схема — `backend/graphql/schema.graphql` + доменные файлы в `backend/graphql/types/` | `backend/AGENTS.md` (Laravel Boost), `README.md` |
| `frontend/` | Nuxt 4 SPA (`ssr: false`), Vue 3, Tailwind 4, `@urql/vue`, Echo + pusher-js. Маршруты: `/`, `/roadmap`, `/lesson/{cardId}`, `/talk`, `/talk/{code}`, `/reflect/{id}`, `/review`, `/level`, `/pricing`, `/settings`, `/login`, `/register`, `/dev/*`. Старые `/onboarding`, `/practice/{cardId}`, `/session/{id}` — страницы-редиректы 301 | `frontend/README.md`, `docs/design/README.md` |
| `agent/` | Python-воркер `livekit-agents`: STT Deepgram, TTS Cartesia, диалоговая LLM. Один модуль — один вопрос, таблица в README | `agent/README.md` |
| `e2e/` | Playwright smoke против живого стека | `e2e/README.md` |
| `infra/`, `docker/`, `docs/deployment.md` | Terraform (ECS/RDS/ElastiCache/LiveKit EC2), Caddy, однохостовый прод | `docs/deployment.md` |
| `load/`, `benchmarks/` | Ёмкость голосового пула, TTFT диалоговых LLM | `load/README.md`, `benchmarks/README.md` |
| `ai-language-coach-plan.md` | Исходный архитектурный план. **Датированные статус-блоки описывают состояние на свою дату**, не сейчас | — |
| `docs/scenario-practice.md`, `docs/knowledge-graph.md` | Планы фич: второй режим разговора и граф вместо линейного роадмапа | — |

Правило: **код старше прозы.** Если датированный документ расходится с кодом — верь коду и
поправь документ.

## Гейты

| Что | Команда |
|---|---|
| Бэкенд-тесты | `cd backend && php artisan test --compact` |
| Стиль PHP | `cd backend && ./vendor/bin/pint --test` (чинить: `pint --dirty`) |
| GraphQL-типы | `cd frontend && npm run codegen && git diff --exit-code -- app/types/graphql.ts` |
| Типы фронта | `cd frontend && npm run typecheck` |
| Сборка фронта | `cd frontend && npm run build` |
| Тесты агента | `cd agent && python3 -m unittest discover -s . -p "test_*.py"` |
| Контракт промптов сценария | `cd agent && pytest -q test_prompts.py` |
| Контейнеры | `./scripts/verify-images.sh` |
| E2E | `E2E_CHANNEL=chrome npm run e2e:smoke` (против `docker compose --profile full`) |

Тесты бэкенда идут в MySQL `language_coach_testing` на `127.0.0.1:3306` (`backend/phpunit.xml`),
не в SQLite: без поднятого docker-стека они не запустятся. Устройство CI и причины —
`.github/workflows/ci.yml`.

## Законы, которые реально связывают

1. **`ProductionSanityCheck` отказывает в старте.** При `APP_ENV=production` приложение не
   поднимется, если `APP_DEBUG=true`, куки не `Secure`, в `CORS_ALLOWED_ORIGINS` или
   `REVERB_ALLOWED_ORIGINS` есть `*`, либо `APP_KEY`/`LIVEKIT_API_SECRET`/`VOICE_INTERNAL_SECRET`
   совпали с dev-дефолтами из `backend/config/dev-defaults.php`. Проверка висит на
   `AppServiceProvider`, поэтому стреляет и в `composer install` без `.env`.
2. **Голосовая сессия — ровно один субъект.** `requestVoiceToken` принимает либо карточку
   урока, либо код сценария, не оба; дневной лимит берётся под `lockForUpdate()`, идемпотентность
   держится на статусах `pending|active`, занятый флот отдаёт типизированную `VOICE_FLEET_BUSY`,
   а не 500. Жизненный цикл — только через `App\Voice\VoiceSessionLifecycle` (вебхук LiveKit и
   внутренние `/api/internal/sessions/...` под `X-Internal-Secret`), переходы условные:
   повторная доставка события ничего не меняет.
3. **GraphQL-схема — источник типов фронта.** Изменил `.graphql` → `php artisan lighthouse:clear-cache`
   и `npm --prefix frontend run codegen`. `frontend/app/types/graphql.ts` руками не правится.
   Нативные PHP-enum'ы регистрируются через `App\GraphQL\Types\NativeEnumType` и дублируются в
   `backend/graphql/codegen-enums.graphql`; `enum`-блок в SDL для них — коллизия.
4. **Японской строки в шаблоне быть не должно.** Вся интерфейсная копия — в
   `frontend/app/constants/strings.ts`; учебный контент английский и живёт в
   `backend/resources/grammar/{language}.php`, а не в БД.
5. **Диалоговая LLM — не DeepSeek.** Провайдеры, чей измеренный TTFT выше бюджета, перечислены в
   `SLOW_DIALOGUE_PROVIDERS` (`agent/dialogue.py`) и блокируются на старте; DeepSeek остаётся на
   async-ролях (ассессмент, разбор ошибок). Обход — только явным `ALLOW_SLOW_DIALOGUE_LLM=true`,
   и это осознанное решение, а не настройка.
6. **В Laravel 13 объекты в кэш не кладём.** `cache.serializable_classes => false`, отсюда
   `LIGHTHOUSE_QUERY_CACHE_MODE=opcache` вместо `store`. Любой `Cache::remember()` с объектом даёт
   тот же `__PHP_Incomplete_Class`.
7. **Подписки — только через `useLighthouseSubscription`.** Он держит сокет-ID, авторизацию
   приватного канала, конверт `{more, result}` и teardown; транспорт — Pusher-протокол, не
   `graphql-ws`. Подписываемые поля объявляются nullable: при подписке они резолвятся в `null`.
8. **Биллинг идемпотентен, минуты считаются по ledger.** `stripe_events` дедуплицирует
   доставки в одной транзакции с эффектом; лимит минут читается из `voice_sessions.duration_sec`
   за месячное окно от даты подписки, отдельного счётчика нет; `ProductionSanityCheck` не пускает
   `sk_test_` в production; удаление аккаунта сначала отменяет подписку в Stripe.

## Память проекта

`docs/memory/` — журнал, а не документация: `progress.md` (сейчас), `decisions.md` (почему,
append-only), `gotchas.md` (ловушки, append-only). Формат — `docs/memory/README.md`.

После изменения проекта:

- `progress.md` **переписывается** (не дописывается): состояние, что дальше, что открыто;
- `decisions.md` — когда выбрал то, что следующая сессия иначе пере-решит: выбор, причина,
  отвергнутые альтернативы, файлы;
- `gotchas.md` — когда на что-то ушло больше десяти минут.

Кода в журнал не вставлять. Канонические документы не дублировать — ссылаться на раздел.

## Язык

Пользователь пишет по-русски — отвечай по-русски. Документация: корень и `docs/memory/` —
по-русски (как `README.md` и план); `frontend/`, `agent/`, `e2e/` — по-английски (как их
собственные README). Код, идентификаторы и коммиты — как в репозитории, то есть по-английски.
