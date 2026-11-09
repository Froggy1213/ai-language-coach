# Progress

Updated: 2026-11-09 · session: пользовательские сценарии с доработкой промпта ИИ и подтверждением

## Сейчас

Ветка `feat/scenario-practice`. Добавлена функция создания и кастомизации собственных сценариев
разговора с двухшаговым циклом «короткий ввод → доработка промпта ИИ → подтверждение и правка
пользователем»:

- **Бэкенд и БД**:
  - Миграция `2026_11_09_000001_create_custom_scenarios_table.php` и модель `CustomScenario`
    (хранит как видимые поля сцены, так и серверные `ai_goal`, `complications`, `opening_line`,
    `success_criteria`, плюс `confirmed_at`).
  - `ScenarioDrafter` / `DeepSeekScenarioDrafter` (`config('assessments.scenario_draft')`) —
    разворачивает короткое описание ученика (на японском или английском) в полноценную карточку
    ролевого сценария на целевом языке с внутренней целью ИИ и осложнениями.
  - GraphQL: поля `isCustom` и `userPrompt` на `Scenario`; мутации `draftCustomScenario`,
    `confirmCustomScenario` и `deleteCustomScenario`.
  - `ScenarioCatalogue::forUser()` и `findForUser()` интегрируют подтверждённые пользовательские
    сценарии в список `/talk`, запуск сессии (`StartVoiceSession`), карточку сессии
    (`SessionScenario`) и разбор достижения цели (`DeepSeekGoalReviewer`).
- **Фронтенд**:
  - `CustomScenarioBuilder.vue`: шаг 1 — ввод описания ситуации, тона и уровня CEFR; шаг 2 —
    просмотр доработанного ИИ сценария, ручное редактирование любых видимых полей и фраз (3–5),
    перегенерация через ИИ и подтверждение («確定して会話を始める» или «確定して一覧に保存»).
  - На `/talk` добавлена секция `自分のお題` с бейджем `カスタム` и удалением; на `/talk/[code]`
    для кастомных сценариев доступно редактирование/перегенерация (`内容を調整する`) и удаление.

## Проверено в этой сессии

- `pint --test` — чисто.
- `php artisan lighthouse:validate-schema` — схема валидна.
- Бэкенд-тесты сценариев, GraphQL, старта голосовой сессии и разбора целей (`ScenarioContentTest`,
  `ScenarioCatalogueTest`, `DeepSeekScenarioDrafterTest`, `CustomScenarioGraphQLTest`,
  `QueryGraphQLTest`, `StartVoiceSessionTest`, `DeepSeekGoalReviewerTest`) — 72 passed / 437 assertions.
- `npm --prefix frontend run codegen && git diff --exit-code -- frontend/app/types/graphql.ts` — чисто.
- `npm --prefix frontend run typecheck` — чисто.
- `npm --prefix frontend run build` — чисто.

## Дальше

- При поднятом Docker-стеке (`docker compose --profile full up -d`) прогнать `php artisan migrate`
  и `E2E_CHANNEL=chrome npm run e2e:smoke`.

## Открытые вопросы

- 39 ссылок «README, решение N» в текущих документах висят в пустоту: раздел решений удалён из
  README коммитом `8a05be5`. Полный лог (пп. 1–62) — `git show 8a05be5^:README.md`.
- Датированный статус-блок плана (15.10.2026) расходится с кодом: `/practice/{cardId}` ведёт на
  `/lesson/{cardId}`, а роадмап теперь является 38-узловым DAG `A1–C1`.

## Блокировано

Ничего не заблокировано.

## Прошлая сессия

- 2026-11-09 — скриншоты в README, актуализация `.gitignore`, пересборка Docker-образов и E2E.
- 2026-11-07 — реализация графа знаний и адаптивного роадмапа; гейты pint/codegen/typecheck/build.
- 2026-11-04 — создан слой памяти проекта (`AGENTS.md`, `docs/memory/`, подсистемные `AGENTS.md`).
