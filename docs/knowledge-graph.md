# Граф знаний и адаптивный роадмап

План реализации. Статус: **утверждён к разработке**.

## 1. Что меняется

| Аспект | Сейчас | После |
|---|---|---|
| **Объём роадмапа** | `upTo(current_level)` — обрезается на текущем уровне (у A1 всего 6 карточек, дальше тупик) | Весь граф каталога `A1–C1` (38 узлов): виден весь путь развития |
| **Учёт уровня (`current_level`)** | Ученик B2 начинает с карточки №1 (`present_simple`, A1), остальные 26 карточек `locked` | Все узлы уровней ниже `current_level` сразу получают `completed`; узлы текущего уровня с выполненными пререквизитами сразу `ready` |
| **Открытие уроков** | Линейно `order_index + 1` | DAG пререквизитов (`prerequisites`) **плюс** внеочередное открытие (`locked → ready`), если ученик допускает ошибки по этой теме в разговоре |
| **Адаптивность от ошибок** | Роадмап не знает про ошибки из голосовых сессий | Узлы с повторяющимися/свежими ошибками получают сигнал `needsPractice` (подсветка «слабого места» на карте и рекомендация на `/`, даже если тема из A1 была зачтена автоматом) |
| **Контент (`en.php`)** | 32 темы, часть промптов письменные (*"Write five..."*) | 38 тем (добавлены вопросы, квантификаторы, `-ed/-ing`, `used to`, модалы вероятности, каузатив/make/let) + разговорные `practice_prompt` |
| **Экран `/roadmap`** | Плоский вертикальный список | Интерактивная SVG-карта графа + список (переключатель `マップ / リスト`) |

Ключевая мысль: **роадмап перестаёт быть оглавлением учебника и становится живой картой владения языком.** Линейный `order_index` сохраняется как топологически отсортированный «рекомендуемый маршрут по умолчанию», но разблокировка и приоритет управляются графом пререквизитов и реальными ошибками ученика в речи.

---

## 2. Подтверждённые архитектурные решения

**D1. Полный граф A1–C1 со стартом на своём уровне.**
При генерации роадмапа создаются карточки для всех точек языка (`forLanguage`). Узлы с `level->rank() < user->current_level->rank()` получают статус `completed`. Затем `LessonUnlocks` вычисляет доступные уроки: все узлы, чьи пререквизиты уже `completed`, получают статус `ready`.
- Новичок **A1** получает 3 открытых корня A1 (`present_simple`, `be_and_there`, `plurals_countability`) и видит перспективу до C1.
- Ученик **B1** видит A1–A2 уже пройденными (может зайти в любой из них для повторения), а его стартовые узлы B1 сразу открыты (`ready`).

**D2. Внеочередное открытие и метка «Слабое место» по ошибкам из разговоров.**
Когда `AnalyzeVoiceSessionMistakes` фиксирует ошибки после голосовой сессии (урока или ролевого сценария):
1. Если карточка соответствующей грамматической точки в активном роадмапе ученика была `locked`, она **промоутится в `ready`** (`LessonUnlocks::unlockForMistakes`). Если ученик уже пытается использовать конструкцию в живой речи и ошибается, запирать урок нельзя.
2. В GraphQL на `LessonCard` добавляется вычисляемый признак `needsPractice: Boolean!` и `mistakeCount: Int!` (ошибки за последнее окно в 7 дней / из `RecurringMistakeDetector`).
   - Если карточка `completed` (например, `articles` на A1 у ученика B2), но по ней копятся ошибки — она не сбрасывается в `ready` (прогресс не отнимается), но подсвечивается на карте и выводится как рекомендация для повторения.
   - На главной (`/`) выбор `recommendedCard` отдаёт приоритет узлу с `needsPractice`, иначе берёт первый `ready` по `order_index`.

**D3. Разблокировка монотонна (только на повышение).**
Карточка переходит `locked → ready → completed` и никогда не понижается обратно в `locked`.

**D4. `level` в GraphQL — nullable `CefrLevel`.**
У служебной точки `uncategorized` уровня нет (`null`), у всех каталожных точек он обязателен.

**D5. Топологический порядок в `en.php`.**
Записи в `resources/grammar/en.php` упорядочены по возрастанию CEFR-уровня и топологически: любой пререквизит всегда идёт раньше зависимого узла (`order_index(prereq) < order_index(node)`). `present_simple` остаётся №1.

**D6. Карта на фронтенде — детерминированный SVG + DOM.**
Никакого Canvas/force-layout: чистая функция раскладки по полосам CEFR (A1 → C1), кривые Безье для рёбер пререквизитов, доступность с клавиатуры (`tabindex`, `aria-label`) и поддержка мобильного фокуса.

---

## 3. Контент: граф из 38 узлов (`backend/resources/grammar/en.php`)

Добавлены 6 ключевых разговорных тем для японоговорящих (`question_forms`, `quantifiers`, `ed_ing_adjectives`, `used_to`, `modals_deduction`, `causative_make_let`), все письменные `practice_prompt` переведены в устный формат.

| # | Код | Ур. | Категория | Пререквизиты |
|---|---|---|---|---|
| **A1** | | | | |
| 1 | `present_simple` | A1 | `tenses` | — (корень) |
| 2 | `be_and_there` | A1 | `verbs` | — (корень) |
| 3 | `question_forms` *(new)* | A1 | `clauses` | `present_simple`, `be_and_there` |
| 4 | `present_continuous` | A1 | `tenses` | `present_simple`, `be_and_there` |
| 5 | `past_simple` | A1 | `tenses` | `present_simple` |
| 6 | `plurals_countability` | A1 | `nouns` | — (корень) |
| 7 | `articles` | A1 | `nouns` | `plurals_countability` |
| 8 | `quantifiers` *(new)* | A1 | `nouns` | `plurals_countability` |
| **A2** | | | | |
| 9 | `prepositions_time_place` | A2 | `prepositions` | `be_and_there` |
| 10 | `past_continuous` | A2 | `tenses` | `past_simple`, `present_continuous` |
| 11 | `present_perfect` | A2 | `tenses` | `past_simple` |
| 12 | `used_to` *(new)* | A2 | `verbs` | `past_simple`, `present_continuous` |
| 13 | `future_going_to` | A2 | `tenses` | `be_and_there`, `present_continuous` |
| 14 | `future_will` | A2 | `tenses` | `present_simple` |
| 15 | `comparatives_superlatives` | A2 | `adjectives` | `be_and_there` |
| 16 | `ed_ing_adjectives` *(new)* | A2 | `adjectives` | `be_and_there` |
| 17 | `modals_ability_permission` | A2 | `modals` | `present_simple`, `question_forms` |
| **B1** | | | | |
| 18 | `present_perfect_continuous` | B1 | `tenses` | `present_perfect`, `present_continuous` |
| 19 | `past_perfect` | B1 | `tenses` | `past_simple`, `present_perfect` |
| 20 | `first_conditional` | B1 | `conditionals` | `present_simple`, `future_will` |
| 21 | `second_conditional` | B1 | `conditionals` | `first_conditional`, `past_simple` |
| 22 | `passive_present_past` | B1 | `passive` | `be_and_there`, `present_perfect` |
| 23 | `relative_clauses` | B1 | `clauses` | `articles`, `be_and_there` |
| 24 | `modals_obligation_advice` | B1 | `modals` | `modals_ability_permission` |
| 25 | `modals_deduction` *(new)* | B1 | `modals` | `modals_ability_permission`, `present_perfect` |
| 26 | `reported_speech` | B1 | `clauses` | `past_simple`, `present_perfect`, `question_forms` |
| **B2** | | | | |
| 27 | `third_conditional` | B2 | `conditionals` | `second_conditional`, `past_perfect` |
| 28 | `passive_perfect_modal` | B2 | `passive` | `passive_present_past`, `modals_obligation_advice` |
| 29 | `gerunds_infinitives` | B2 | `verbs` | `present_continuous`, `used_to` |
| 30 | `causative_make_let` *(new)* | B2 | `verbs` | `passive_present_past`, `gerunds_infinitives` |
| 31 | `future_perfect_continuous` | B2 | `tenses` | `future_will`, `present_perfect_continuous`, `future_going_to` |
| 32 | `wish_if_only` | B2 | `clauses` | `second_conditional`, `past_perfect` |
| 33 | `concession_linkers` | B2 | `discourse` | `relative_clauses`, `gerunds_infinitives` |
| **C1** | | | | |
| 34 | `mixed_conditionals` | C1 | `conditionals` | `third_conditional`, `wish_if_only` |
| 35 | `inversion_emphasis` | C1 | `advanced` | `present_perfect`, `third_conditional` |
| 36 | `cleft_sentences` | C1 | `advanced` | `relative_clauses`, `passive_present_past` |
| 37 | `participle_clauses` | C1 | `clauses` | `relative_clauses`, `passive_perfect_modal` |
| 38 | `subjunctive_mandative` | C1 | `advanced` | `modals_obligation_advice`, `reported_speech` |

---

## 4. Модель данных и инварианты

### 4.1 Миграции
1. `add_level_to_grammar_points_table`:
   - `$table->string('level', 2)->nullable()->after('category');`
2. `create_grammar_point_prerequisites_table`:
   - `grammar_point_id` (`constrained()->cascadeOnDelete()`)
   - `prerequisite_grammar_point_id` (`constrained('grammar_points')->cascadeOnDelete()`)
   - уникальный индекс `['grammar_point_id', 'prerequisite_grammar_point_id']`

### 4.2 Инварианты каталога (`GrammarCatalogue`)
Проверяются при чтении каталога и закрепляются в `GrammarCatalogueTest`:
1. Каждый код пререквизита существует в том же языке.
2. Нет самоссылок и дублей пререквизитов внутри одного узла.
3. Монотонность уровней: `level(prerequisite)->rank() <= level(node)->rank()`.
4. Граф ацикличен (DAG).
5. Топологический порядок в файле: `position(prerequisite) < position(node)`.

---

## 5. Доменная логика разблокировки и адаптивности

### 5.1 `App\Grammar\LessonUnlocks`
Единый сервис расчёта статусов:
- `isUnlocked(string $code, array $completedCodes, array $prerequisitesByCode): bool` — все ли пререквизиты узла входят в `$completedCodes`.
- `initialStatuses(array $contents, CefrLevel $learnerLevel): array<string, LessonCardStatus>`:
  1. Все точки с `$content->level->rank() < $learnerLevel->rank()` получают `LessonCardStatus::Completed`.
  2. Остальные точки получают `LessonCardStatus::Ready`, если `isUnlocked(...) === true`, иначе `LessonCardStatus::Locked`.
- `syncRoadmapUnlocks(Roadmap $roadmap, array $forceReadyGrammarPointIds = []): int`:
  Пересчитывает `locked → ready` для всех карточек роадмапа, чьи пререквизиты выполнены ИЛИ чей `grammar_point_id` передан в `$forceReadyGrammarPointIds` (адаптивное открытие по ошибкам). Возвращает количество открытых карточек.

### 5.2 Где вызывается `syncRoadmapUnlocks`
1. **`RoadmapGenerator`** — при создании карточек через `initialStatuses(...)` + учёт уже накопленных пользователем ошибок.
2. **`CompleteLessonCard`** — после перевода карточки в `completed` вызывает `syncRoadmapUnlocks($roadmap)` и проверяет, остались ли незавершённые карточки.
3. **`AnalyzeVoiceSessionMistakes`** — внутри транзакции сохранения ошибок собирает `grammar_point_id` найденных ошибок (кроме `uncategorized`) и, если у пользователя есть активный роадмап, вызывает `syncRoadmapUnlocks($roadmap, $mistakeGrammarPointIds)`, автоматически открывая запертые проблемные темы.
4. **Консольная команда `php artisan roadmap:rebuild-unlocks {--dry-run} {--user=}`** — бэкфилл для существующих роадмапов.

---

## 6. GraphQL-схема

В `backend/graphql/types/grammar-point.graphql`:
```graphql
type GrammarPoint {
    # ...существующие поля
    "CEFR band this point is taught at; null for the uncategorized sentinel."
    level: CefrLevel

    "Every one of these must be completed before this point is unlocked on the standard path."
    prerequisites: [GrammarPoint!]! @belongsToMany @orderBy(column: "code")
}
```

В `backend/graphql/types/roadmap.graphql`:
```graphql
type LessonCard {
    # ...существующие поля
    "How many mistakes the learner made on this grammar point in the recent window."
    recentMistakeCount: Int!

    "True when recent mistakes indicate this card should be practised or reviewed next."
    needsPractice: Boolean!
}
```
*(Поля `recentMistakeCount` и `needsPractice` резолвятся батчем без N+1 по данным `mistakes` за последние 7 дней).*

---

## 7. Frontend (`/roadmap` и `/`)

1. **Компонент карты `RoadmapGraph.vue` + `utils/roadmap-graph.ts`:**
   - Детерминированная раскладка по 5 горизонтальным уровням `A1` → `C1`.
   - Кривые Безье для рёбер пререквизитов и подсветка связей при фокусе/наведении.
   - Четыре визуальных состояния узла:
     - `ready` (доступно сейчас — акцент);
     - `needsPractice` (слабое место по итогам разговоров — янтарный/терракотовый маркер внимания);
     - `completed` (освоено — sage);
     - `locked` (заперто — приглушённый контур с подсказкой пререквизитов).
   - Переключатель `マップ / リスト` на `/roadmap` и песочница `/dev/graph`.
2. **Главный экран `/` (`index.vue` и `useRoadmap.ts`):**
   - Если в роадмапе есть карточка с `needsPractice` (частые ошибки в недавних разговорах), она предлагается с пояснением, почему коуч вынес её вперёд; иначе предлагается следующая `ready`-карточка на уровне ученика.
