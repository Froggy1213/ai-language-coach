# Практика по сценариям и развязка навигации

План реализации. Статус: **реализовано** на ветке `feat/scenario-practice` (M1–M7); реализация — в §14.

Предыдущий документ — [knowledge-graph.md](knowledge-graph.md) — отложен: граф переезжает на экран курса и вернётся, когда этот план закрыт.

## 1. Что делаем

Второй режим разговора: не «практикуем Present Perfect», а «проходим ситуацию» — деловая встреча, аэропорт, звонок клиенту. ИИ играет роль со своей целью и сопротивлением, ученик играет свою. Разбор ошибок и очередь повторений остаются те же.

| | Сейчас | После |
|---|---|---|
| Вкладка 会話 | редирект на текущий урок | выбор темы и свободный разговор |
| Где начинается урок | `/talk/{cardId}` и вкладка 会話 | `/lesson/{cardId}`, вход с 今日 и ロードマップ |
| Что говорит агент | тема урока + промпт-задание | роль, сцена, цель, ограничения, осложнения |
| Когда исправляет | мягко по ходу | урок — по ходу, сценарий — **только после** |
| Куда попадают ошибки | `mistakes` → `review_items` | **то же самое**, менять нечего |

Ключевое свойство: [`AnalyzeVoiceSessionMistakes`](../backend/app/Mistakes/AnalyzeVoiceSessionMistakes.php) читает только транскрипт и пользователя — урок ей не нужен. Поэтому сценарий с первого дня кормит тот же цикл обучения, а не становится отдельным чат-ботом.

## 2. Решения, требующие подтверждения

**D1. 会話 = темы, курс = ロードマップ + 今日.** Центральная приподнятая кнопка таб-бара (сейчас главный CTA приложения) начнёт вести на выбор темы, а не на урок. Курс остаётся в один тап с 今日. Это осознанный выбор в пользу свободной практики как основного действия; альтернатива — центр ведёт на урок, а темы живут только внутри вкладки.

**D2. Маршрут урока — `/lesson/{cardId}`.** `/talk/{code}` остаётся за сценариями, чтобы префиксы не пересекались. Старый 301 `/practice/:cardId` переадресуется на `/lesson/:cardId`.

**D3. Контент сценариев — английский, включая названия.** Инвариант «интерфейс японский, учебный материал английский» не трогаем. `Airport check-in` — это ровно та лексика, ради которой ученик пришёл. Если на A1 окажется тяжело, добавим `title_ja` отдельным полем позже, не меняя модель.

**D4. В сценарии ИИ не исправляет ошибки в образе.** Любая поправка рушит сцену, а беглость — цель режима. Разбор целиком уходит на 振り返り, куда ошибки и так попадают асинхронно. Это же правило снимает риск, что ролевой промпт начнёт «учить» вместо «играть».

**D5. Сценарии — версионированный контент, не таблица в БД.** `resources/scenarios/{language}.php` по образцу грамматики. `voice_sessions.scenario_code` — просто строковый код без FK: на сценарии ничего не ссылается через внешний ключ, а удалённый сценарий не должен ломать историю сессий.

**D6. Одна мутация `requestVoiceToken(lessonCardId, scenarioCode)` с XOR.** Квота, идемпотентность и LiveKit — одинаковые для обоих режимов, поэтому точка входа одна. Валидация «ровно один аргумент» — в резолвере с типизированной ошибкой.

## 3. Навигация

| Маршрут | Экран |
|---|---|
| `/` | 今日 — следующий урок, CTA → `/lesson/{readyCardId}` |
| `/roadmap` | курс — путь, CTA → `/lesson/{cardId}` |
| **`/talk`** | **выбор темы: список сценариев + 自由に話す** |
| **`/talk/{scenarioCode}`** | сессия сценария |
| **`/lesson/{cardId}`** | сессия урока (переехала из `/talk/{cardId}`) |
| `/practice/{cardId}` | 301 → `/lesson/{cardId}` |
| `/reflect/{id}`, `/review` | без изменений |

Подсветка таб-бара: `/talk*` → 会話, `/lesson*` → ロードマップ. Это делает IA читаемой: во время урока активна вкладка курса, а не разговора.

Ссылки, которые переезжают: `index.vue` (CTA), `RoadmapLesson.vue` (CTA), `TabBar.vue` (центр + активность), `app.vue` (desktop nav), `reflect` (2 ссылки), `review.vue` (1).

Копия: блок `talk` в `strings.ts` переименовывается в `lesson` (レッスン), для сценариев заводится новый `talk` (会話, お題を選ぶ, 自由に話す). Иначе переезд маршрутов ничего не изменит.

## 4. Домен

### 4.1 Миграция

```php
// voice_sessions: урок больше не обязателен
$table->foreignId('lesson_card_id')->nullable()->change();
$table->string('scenario_code', 64)->nullable()->after('lesson_card_id');
$table->index(['user_id', 'scenario_code'], 'idx_user_scenario');
```

`change()` поддержан нативно (Laravel 13, PHP 8.4, без doctrine/dbal). Внешний ключ сохраняется: `NULL` для FK допустим.

Инвариант «ровно один субъект» держим в PHP (`PracticeSubject`), а не в БД: CHECK-констрейнт в MySQL 8.4 возможен, но Laravel не умеет его откатывать через `down()` без сырого SQL, а выигрыш нулевой — писать в таблицу умеет один класс.

### 4.2 `App\Voice\PracticeSubject`

```php
final readonly class PracticeSubject
{
    private function __construct(
        public ?LessonCard $lessonCard,
        public ?ScenarioContent $scenario,
    ) {}

    public static function lesson(LessonCard $card): self;
    public static function scenario(ScenarioContent $scenario): self;

    public function isLesson(): bool;
    public function label(): string; // 'lesson:12' | 'scenario:airport_checkin'
}
```

Конструктор приватный — «ни урока, ни сценария» становится непредставимым состоянием, а не рантайм-проверкой.

### 4.3 Стартер и идемпотентность

`VoiceSessionStarter::start(User $user, PracticeSubject $subject)`:

- устаревшие `pending` чистятся по субъекту;
- `currentSession($user, $subject)` — **осторожно с NULL**: `where('lesson_card_id', null)` даёт `= NULL` и не совпадает никогда. Для сценария фильтр `whereNull('lesson_card_id')->where('scenario_code', $code)`, для урока наоборот;
- квота общая: сценарий и урок считаются в один дневной лимит (`voice.daily_session_limit`, по умолчанию 10);
- строка создаётся с одним заполненным субъектом;
- `room_name` — `practice-{id}` вместо `lesson-{id}`. **Парсинга префикса нет**: вебхук ищет сессию через `where('room_name', $roomName)` в `VoiceSessionLifecycle::transition()`, так что переименование безопасно — обновить надо только комментарий в `LiveKitWebhookController`.

`VoiceAgentDispatcher::dispatch(VoiceSession, PracticeSubject, User)` — метаданные собираются по виду:

```php
// урок (без изменений)
['session_id', 'room_name', 'grammar_point', 'grammar_point_id',
 'practice_prompt', 'target_language', 'level', 'kind' => 'lesson']

// сценарий
['session_id', 'room_name', 'kind' => 'scenario', 'scenario_code',
 'setting', 'ai_role', 'ai_goal', 'learner_role', 'learner_goal',
 'register', 'complications', 'opening_line',
 'target_language', 'level']
```

Полезные фразы в метаданные не идут: они нужны экрану, а не модели. Пейлоад держим < 2 КБ, чтобы не упереться в лимит метаданных диспатча.

### 4.4 Резолвер

`StartVoiceSession::__invoke` разбирает аргументы в `PracticeSubject`:
- оба аргумента или ни одного → `ValidationException` / ошибка `PRACTICE_SUBJECT_INVALID`;
- `lessonCardId` → текущая проверка владельца и статуса (без изменений);
- `scenarioCode` → `ScenarioCatalogue::find($user->target_language, $code)`, отсутствующий код → `SCENARIO_NOT_FOUND`;
- дальше путь общий.

## 5. Контент: каталог сценариев

`resources/scenarios/{language}.php`, классы `App\Scenarios\ScenarioCatalogue` и `ScenarioContent` — зеркало грамматического каталога, включая защиту от path traversal в коде языка.

Поля одной записи:

| Поле | Зачем |
|---|---|
| `title`, `summary` | что выбрать в списке |
| `level` | CEFR-бэнд, с которого сценарий уместен |
| `register` | `formal` / `casual` / `neutral` — задаёт тон |
| `setting` | где мы и что происходит |
| `ai_role` + `ai_goal` | кто ИИ и **чего он хочет** |
| `learner_role` + `learner_goal` | кто ученик и чего хочет он |
| `complications[]` | 2–4 осложнения, которые ИИ вправе ввести |
| `opening_line` | первая реплика в образе, произносится дословно |
| `success_criteria` | по чему судить, что задача выполнена |
| `useful_phrases[]` | 3–5 фраз для панели подсказки |

`ai_goal` и `complications` — то, что превращает сценарий из анкеты в разговор. Сценарий без сопротивления не учит ничему: ученик произносит заготовку и получает «great, have a nice flight».

Пример:

```php
'airport_checkin' => [
    'title' => 'Airport check-in',
    'summary' => 'Check in for a connecting flight, with a bag that is slightly too heavy.',
    'level' => 'A2',
    'register' => 'formal',
    'setting' => 'You are the check-in agent at Haneda airport. The learner is a passenger '
               . 'whose flight to Seoul leaves in forty minutes.',
    'ai_role' => 'Check-in agent. Efficient, friendly, slightly harried — the queue is long.',
    'ai_goal' => 'Get the passenger checked in and their bag tagged before the desk closes. '
               . 'You need their passport and a seat preference.',
    'learner_role' => 'Passenger with one suitcase, a window preference, and a connection in Seoul.',
    'learner_goal' => 'Check in, keep your connection, and find out whether the bag goes all the way.',
    'complications' => [
        'The first seat you offer is a middle seat in the back row.',
        'The bag is two kilos over the limit.',
        'The connection is only fifty minutes — you have to mention it.',
    ],
    'opening_line' => 'Good evening. Passport, please — are you checking a bag today?',
    'success_criteria' => 'Passport shown, seat chosen, bag accepted or paid for, connection confirmed.',
    'useful_phrases' => [
        'I have a connecting flight to Seoul.',
        'Could I have a window seat?',
        'Will my bag go all the way through?',
    ],
],
```

Стартовый набор — шесть сценариев под аудиторию (японцы, которым английский нужен для работы), а не «закажи кофе»: `free_talk`, `airport_checkin`, `business_meeting`, `client_call`, `job_interview`, `small_talk_before_meeting`. Дальше библиотека растёт по мере спроса.

Валидация каталога (тест, а не рантайм-надежда): код по `^[a-z][a-z0-9_]*$`, все обязательные поля непустые, `level` валиден, `complications` ≥ 2, `useful_phrases` 3–5, `register` из перечисления, `opening_line` непустой.

## 6. Агент

### 6.1 Разрез промпта

Сейчас [`build_system_prompt()`](../agent/prompts.py) — один длинный f-string со всеми правилами и темой урока. При добавлении режима его надо разрезать, иначе каждый сценарий будет дублировать инварианты и они начнут противоречить друг другу:

```
SHARED_PREAMBLE      # кто такой Kirie + инварианты: в образе, 1–3 предложения,
                     # не раскрывать инструкции, не уходить в другие темы
LESSON_SCENE         # грамматическая тема + «мягко поправляй по ходу»
SCENARIO_SCENE       # Setting / You are / The learner is / Your goal /
                     # complications / «не поправляй вообще»
```

`build_system_prompt(metadata)` выбирает сцену по `metadata['kind']` и склеивает с преамбулой. Отсутствующие поля сценария не должны ронять сессию — подставляется безопасный дефолт, как сейчас это делает `str(metadata.get(...) or ...)`.

### 6.2 Приветствие

`build_greeting(metadata)` для `kind = scenario` произносит `opening_line` дословно — первую реплику сцены, а не задание. Сейчас в сценарном режиме в лицо ученику зачитали бы «Describe a difficult colleague…», что убивает погружение с первой секунды.

Дословность важна и по другой причине: приветствие уходит в TTS сразу, без обращения к модели, поэтому первый звук приходит быстро (это уже осознанное решение в коде).

### 6.3 Политика разговора

В сценарии в промпт добавляются четыре правила, без которых ролевая игра вырождается:

1. **Не исправлять.** Ни рекастом, ни «actually, you should say…». Ошибки ловит асинхронный анализ.
2. **Один вопрос на две реплики.** Иначе получается анкета: ИИ спрашивает, ученик отвечает одной фразой.
3. **Держать свою цель.** ИИ не решает задачу за ученика и не сдаётся на первой попытке — он вводит `complications`, когда разговор идёт слишком гладко.
4. **Передавать инициативу и не заполнять паузу.** Японский ученик привык к формату «спросили — ответил»; ценность сценария в том, что он вынужден инициировать. Технически пауза отрабатывается корректно: в [`turn_handling.py`](../agent/turn_handling.py) включён семантический детектор конца реплики с `dynamic` endpointing, а не таймер тишины.

## 7. GraphQL

```graphql
type Query {
    "Scenarios the learner can practise, for their target language."
    scenarios: [Scenario!]! @guard @field(resolver: "App\\GraphQL\\Queries\\Scenarios")
}

type Scenario {
    code: String!
    title: String!
    summary: String!
    level: CefrLevel!
    register: String!
    setting: String!
    aiRole: String!          @rename(attribute: "ai_role")
    learnerRole: String!     @rename(attribute: "learner_role")
    learnerGoal: String!     @rename(attribute: "learner_goal")
    usefulPhrases: [String!]! @rename(attribute: "useful_phrases")
}

type Mutation {
    requestVoiceToken(
        "The lesson card to practise; exactly one of lessonCardId or scenarioCode."
        lessonCardId: ID
        "The scenario to practise; exactly one of lessonCardId or scenarioCode."
        scenarioCode: String
    ): VoiceSession! @guard @field(resolver: "App\\Voice\\StartVoiceSession")
}

type VoiceSession {
    lessonCard: LessonCard        # было LessonCard! — урок больше не обязателен
    scenario: Scenario            # заполнено только у сценарной сессии
}
```

`ai_goal` и `complications` **не отдаются клиенту**: их знание снимает сопротивление и превращает сцену в чтение по ролям.

Изменение `lessonCard: LessonCard!` → `LessonCard` безопасно: клиент это поле нигде не выбирает (проверено по `frontend/app`), а `/reflect` читает ошибки, не карточку.

После правки SDL — `npm run codegen`.

## 8. Фронтенд

Новые экраны:
- `app/pages/talk/index.vue` — выбор темы: `自由に話す` первой строкой, затем карточки сценариев (название, суть, уровень, регистр).
- `app/pages/talk/[code].vue` — сессия сценария: шапка (твоя роль и твоя цель), орб, панель `useful_phrases` вместо cheat sheet, завершение → `/reflect/{id}`.

Переезжает:
- `app/pages/talk/[[id]].vue` → `app/pages/lesson/[cardId].vue` (тело почти без изменений: выбор карточки из URL, орб, промпт, cheat sheet).
- `app/pages/practice/[cardId].vue` — 301 на `/lesson/{id}`.

Composables:
- `useVoiceSession.start()` принимает `{ lessonCardId } | { scenarioCode }` и передаёт в мутацию ровно один;
- новый `useScenarios()` поверх `scenarios` запроса.

Копия: `strings.lesson` (レッスン, 文法チェックつき) и `strings.talk` (会話, お題を選ぶ, 自由に話す, この会話は振り返りに記録されます).

Разница режимов должна быть видна текстом: на уроке — «文法チェックつき», на сценарии — «この会話は振り返りに記録されます». Иначе разное поведение ИИ читается как нестабильность.

## 9. Разбор после разговора (M6, отдельным этапом)

Достижение цели — то, что отличает сценарий от чата. Минимальный вариант: расширить существующий анализ вторым результатом (`goal_achieved: bool`, `missed_expressions: string[]`), сложить в новую nullable json-колонку `goal_review` на `voice_sessions`, показать на `/reflect` блоком «цель выполнена / чего не хватило».

Это отдельный промпт: он судит коммуникацию, а не грамматику, и не должен превращаться в ещё один список ошибок. Поэтому M6 идёт после того, как основной режим заработал.

**Статус M6:** реализовано — вердикт хранится в nullable json-колонке `voice_sessions.goal_review`, а экран 振り返り показывает блок «цель выполнена / чего не хватило» (см. §14).

## 10. Тесты

Backend:

| Файл | Что |
|---|---|
| `ScenarioCatalogueTest` (новый) | обязательные поля, `level`, `register`, `complications ≥ 2`, `phrases 3–5`, path traversal в коде языка, неизвестный код → `null` |
| `StartVoiceSessionTest` | старт сценария; неизвестный код → `SCENARIO_NOT_FOUND`; оба аргумента и ни одного → XOR-ошибка; урок по-прежнему работает; идемпотентность по субъекту (сценарий не подхватывает урок и наоборот); квота общая |
| `VoiceSessionLifecycleTest` / webhook | сессия по `room_name` находится после переименования в `practice-{id}` |
| `AnalyzeVoiceSessionMistakesTest` | **регрессия:** анализ работает для сессии без `lesson_card_id` |
| `QueryGraphQLTest` | `scenarios` отдаёт поля и не отдаёт `ai_goal`/`complications`; `lessonCard` = `null` у сценарной сессии |

Агент (pytest):
- `test_prompts.py`: преамбула присутствует в обоих видах; сценарный промпт содержит роль, цель и осложнения; **не содержит** правила исправлять; приветствие берёт `opening_line`; отсутствующие поля не роняют построение.

Фронтенд (e2e):
- `/talk` рендерит список сценариев и `自由に話す`;
- ссылка со списка ведёт на `/talk/{code}`, а с роадмапа — на `/lesson/{cardId}`;
- реальную голосовую сессию e2e не проверяет (нужен LiveKit), это делается вручную.

Ручной эталон качества (единственная защита от тихой деградации промпта): 3 сценария × фиксированные 5 реплик ученика, прогон после каждой правки промпта, оценка по четырём пунктам — остаётся в роли, задаёт уместное, хотя бы раз возражает, ни разу не поправил. ~20 минут.

## 11. Риски

| Риск | Как снимаем |
|---|---|
| Центральная кнопка ведёт на выбор темы, а не на урок | решение D1 принимается явно; метрика после релиза — сколько уроков начинается за день |
| Дрейф ролевого промпта | общие инварианты в одной преамбуле, карточка сценария — только своё; ручной эталон |
| Двое похожих маршрутов путают | префиксы `/talk` и `/lesson` не пересекаются, подсветка таба меняется по префиксу |
| `NULL` в идемпотентности | явные `whereNull`/`whereNotNull` + тест на перекрёстное подхватывание |
| Метаданные диспатча разрастаются | в пейлоад только то, что читает промпт; тест на размер |
| Ролевая игра скатывается в допрос | правило «один вопрос на две реплики» + `complications` |
| Сценарии пишутся про «закажи кофе» | набор собирается под рабочую жизнь японца; generic-сценарии не берём |

## 12. Порядок работ

| Этап | Содержание | Оценка |
|---|---|---|
| **M0** | Подтверждение D1–D6 | ревью |
| **M1** | Миграция, `PracticeSubject`, стартер, резолвер, вебхук-комментарий, тесты домена | 1.5 дня |
| **M2** | `ScenarioCatalogue` + `ScenarioContent` + валидация + 6 сценариев + тесты | 1 день |
| **M3** | Промпт-разрез, приветствие, метаданные, pytest | 1 день |
| **M4** | GraphQL (`scenarios`, XOR, nullable `lessonCard`), codegen | 0.5 дня |
| **M5** | Маршруты и IA, два экрана, копия, composables, e2e | 2 дня |
| **M6** | Разбор достижения цели + экран результата | 1 день |
| **M7** | Ручной эталон, `docs/`, запись в README | 0.5 дня |

Итого ≈ 7.5 дня, без M6 — 6.5.

**Тонкий срез, если хочется проверить спрос до библиотеки:** M1 + M3 с единственным сценарием `free_talk` и кнопкой «自由に話す» на текущем `/talk` — **~2 дня**, без переезда маршрутов и без каталога. Если после десяти сессий видно, что разговоры идут и ошибки попадают в повторения, библиотека и M5 оправданы. Если нет — потеряно два дня, а не неделя.

## 13. За рамками

- Голосовые персоны (разные тембры под роли) — сейчас один голос на язык.
- Достижение цели как метрика прогресса в 今日 и 設定 — сначала M6 на экране разбора.
- Сценарии для других языков: механика язык-независима, контент — только `en`.
- Граф знаний — отдельный документ, вернётся после закрытия этого плана.

## 14. Реализация

Ветка `feat/scenario-practice`. Ниже — где именно лежит каждый этап; сам план выше не переписывается.

| Этап | Где это сделано |
|---|---|
| **M1** | Миграция `backend/database/migrations/2026_11_03_141505_make_lesson_card_id_nullable_and_add_scenario_code_to_voice_sessions.php`; `App\Voice\PracticeSubject`, `VoiceSessionStarter`, `StartVoiceSession`, `VoiceAgentDispatcher`; вебхук ищет сессию по `room_name` (`practice-{id}`). Тесты: `backend/tests/Feature/Voice/StartVoiceSessionTest.php` — сценарий, `SCENARIO_NOT_FOUND`, XOR, идемпотентность по субъекту, общая квота, вебхук; регрессия в `AnalyzeVoiceSessionMistakesTest`. |
| **M2** | `backend/app/Scenarios/{ScenarioCatalogue,ScenarioContent,ScenarioRegister}.php`; каталог `backend/resources/scenarios/en.php` — шесть сценариев (`free_talk`, `airport_checkin`, `business_meeting`, `client_call`, `job_interview`, `small_talk_before_meeting`). Тесты: `backend/tests/Feature/Scenarios/ScenarioCatalogueTest.php`, `backend/tests/Unit/Scenarios/ScenarioContentTest.php`. |
| **M3** | `agent/prompts.py`: `SHARED_PREAMBLE`, `LESSON_SCENE`, `SCENARIO_SCENE`, выбор сцены по `kind`, дословный `opening_line` в приветствии. Тесты: `agent/test_prompts.py` (pytest). |
| **M4** | GraphQL: `scenarios` и XOR-аргументы `requestVoiceToken` в `backend/graphql/schema.graphql`; `backend/graphql/types/scenario.graphql`; nullable `lessonCard` и поля `scenario`/`goalReview` в `backend/graphql/types/voice-session.graphql`; резолверы `App\GraphQL\Queries\Scenarios` и `App\GraphQL\Resolvers\SessionScenario`; кодогенерация в `frontend/app/types/graphql.ts`. Тесты: `backend/tests/Feature/GraphQL/QueryGraphQLTest.php`. |
| **M5** | Экраны `frontend/app/pages/talk/index.vue` и `talk/[code].vue`, `frontend/app/pages/lesson/[cardId].vue`, 301 в `frontend/app/pages/practice/[cardId].vue`; `useScenarios`, `useVoiceSession`, копия в `strings.ts`, документы в `documents.ts`; e2e `e2e/scenarios.spec.ts`. |
| **M6** | Миграция `backend/database/migrations/2026_11_03_150000_add_goal_review_to_voice_sessions.php` (nullable json `goal_review`); `DeepSeekGoalReviewer`/`GoalReviewer` и их вызов из `AnalyzeVoiceSessionMistakes`; блок цели на `frontend/app/pages/reflect/[[id]].vue`. Тесты: `backend/tests/Feature/Mistakes/DeepSeekGoalReviewerTest.php`, сценарии в `AnalyzeVoiceSessionMistakesTest`. |
| **M7** | Этот документ и записи в `README.md`, `frontend/README.md`, `agent/README.md`; e2e-спецификация проверяет маршруты и текстовую разницу режимов, но не голосовую сессию. |
| **M8** | Пользовательские сценарии с доработкой промпта через ИИ и подтверждением: миграция `2026_11_09_000001_create_custom_scenarios_table.php`, модель `CustomScenario`, `ScenarioDrafter`/`DeepSeekScenarioDrafter` (`config('assessments.scenario_draft')`), мутации `draftCustomScenario`, `confirmCustomScenario`, `deleteCustomScenario`, компонент `frontend/app/components/CustomScenarioBuilder.vue` и интеграция на `/talk` и `/talk/[code]`. Тесты: `DeepSeekScenarioDrafterTest`, `CustomScenarioGraphQLTest`, `e2e/scenarios.spec.ts`. |

Ручной эталон качества из §10 остаётся ручным: e2e не запускает голосовую сессию LiveKit, поэтому реальный диалог со сценарием проверяется вручную после правок промпта.
