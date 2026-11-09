# Решения

Append-only: новые записи в конце. Формат — [`README.md`](README.md) в этой папке.

## 2026-11-04 — Память: два слоя, язык по образцу соседних README

**Решение:** маршрутизация и законы — в корневом `AGENTS.md`; текущее состояние и история
выборов — в `docs/memory/`. Язык: корень и журнал по-русски (как `README.md` и план),
подсистемные `AGENTS.md` по-английски (как `frontend/README.md`, `agent/README.md`,
`e2e/README.md`).
**Почему:** единственный автоматически загружаемый канал в харнессе — цепочка `AGENTS.md` от
корня репозитория вниз до рабочей директории; остальное читается только по решению агента.
Смешение языков повторяет сложившуюся конвенцию репозитория, а не вводит новую.
**Отвергнуто:** всё по-английски — противоречит README и плану; всё по-русски — спорит с
подсистемными README.
**Файлы:** `AGENTS.md`, `docs/memory/*`, `frontend/AGENTS.md`, `agent/AGENTS.md`, `e2e/AGENTS.md`
**Статус:** folded into `AGENTS.md` §Язык

## 2026-11-04 — Лог решений 1–62 не возвращаем в README

**Решение:** архив решений остаётся в истории git (`git show 8a05be5^:README.md`); в рабочее
дерево его не возвращаем, новые решения пишем сюда.
**Почему:** `8a05be5` удалил раздел сознательно, назвав содержимое «историческими эссе», а
README после этого — компактная справка; возврат раздувает файл, который читают в каждой сессии.
**Отвергнуто:** вернуть раздел в README (отменяет `8a05be5`); скопировать 62 пункта в
`docs/memory/` (журнал не хранит документацию).
**Следствие:** 39 ссылок «README, решение N» остаются висящими — это долг, записан в
`progress.md` → Открытые вопросы.
**Файлы:** `README.md`, `ai-language-coach-plan.md`, `e2e/README.md`
**Статус:** open

## 2026-11-04 — `backend/AGENTS.md` оставлен без правок

**Решение:** файл остаётся сгенерированным Laravel Boost; проектные законы бэкенда живут в
корневом `AGENTS.md` и в README.
**Почему:** файл помечен маркером `<laravel-boost-guidelines>`, то есть генерируется
инструментом; ручная секция в нём — кандидат на потерю при перегенерации (команду
перегенерации не проверяли) и не видна из корня, где проектные законы ищут.
**Отвергнуто:** дописать секцию «Project laws» в `backend/AGENTS.md`.
**Файлы:** `backend/AGENTS.md`, `AGENTS.md`
**Статус:** folded into `AGENTS.md` §Карта

## 2026-11-07 — Полный DAG `A1–C1`, зачёт уровней ниже `current_level` и адаптивность по ошибкам

**Решение:**
1. Роадмап строится по всему каталогу языка (`A1–C1`, 38 узлов в `en.php` с разговорными темами),
   а не обрезается по `upTo(current_level)`.
2. При генерации узлы уровней строго ниже `user->current_level` сразу получают статус `completed`,
   а узлы на уровне `current_level` (и выше) с выполненными пререквизитами — `ready`.
3. Разблокировка управляется DAG пререквизитов (`LessonUnlocks`) плюс внеочередным открытием
   (`locked → ready`), если в голосовой сессии ученик допустил ошибку по этой теме.
4. На `LessonCard` в GraphQL отдаются `recentMistakeCount` и `needsPractice`: пройденные темы не
   откатываются назад из `completed`, но подсвечиваются как «слабые места» на SVG-карте `/roadmap`
   и поднимаются в рекомендации на главной `/`.
**Почему:** `upTo(current_level)` создавал двойной перекос — ученик `B2` был вынужден проходить весь
`A1–A2` с нуля по одной карточке, а ученик `A1` упирался в потолок из 6 карточек без пути дальше;
при этом данные об ошибках в живой речи никак не влияли на маршрут обучения.
**Отвергнуто:** линейный `order_index + 1`; обрезка роадмапа сверху по `upTo`; сброс `completed`
обратно в `ready` при ошибках (отнимает у ученика прогресс).
**Файлы:** `docs/knowledge-graph.md`, `backend/resources/grammar/en.php`, `backend/app/Grammar/*`,
`backend/app/Mistakes/AnalyzeVoiceSessionMistakes.php`, `frontend/app/components/RoadmapGraph.vue`,
`frontend/app/utils/roadmap-graph.ts`, `frontend/app/composables/useRoadmap.ts`
**Статус:** active

## 2026-11-09 — Пользовательские сценарии: двухшаговый черновик ИИ (`draftCustomScenario` → `confirmCustomScenario`)

**Решение:**
1. Ученик задаёт короткое описание ситуации (на японском или английском) плюс опциональные регистр и уровень CEFR.
2. `draftCustomScenario` вызывает `DeepSeekScenarioDrafter` и сохраняет полный сценарий в `custom_scenarios` со статусом черновика (`confirmed_at = NULL`).
3. Клиент получает стандартный тип `Scenario` (без `ai_goal` и `complications`), показывает пользователю карточку сцены (`title`, `summary`, `setting`, `aiRole`, `learnerRole`, `learnerGoal`, `usefulPhrases`) для правки вручную или повторной генерации через ИИ и ждёт подтверждения.
4. `confirmCustomScenario` валидирует и сохраняет отредактированные видимые поля, проставляет `confirmed_at` и открывает сценарий для `scenarios`, `requestVoiceToken` и `DeepSeekGoalReviewer`.
**Почему:** скрытые цель ИИ (`ai_goal`) и осложнения (`complications`) нельзя отдавать в браузер на шаге подтверждения (иначе ролевая игра превращается в чтение скрипта), поэтому между генерацией ИИ и подтверждением пользователя они должны храниться на сервере в строке черновика.
**Отвергнуто:** передавать `ai_goal` и `complications` клиенту и обратно в мутацию сохранения; одноразовые сценарии без сохранения в список `/talk`.
**Файлы:** `backend/database/migrations/2026_11_09_000001_create_custom_scenarios_table.php`, `backend/app/Models/CustomScenario.php`, `backend/app/Scenarios/{ScenarioDrafter,DeepSeekScenarioDrafter,ScenarioDraft,ScenarioCatalogue}.php`, `backend/app/GraphQL/Mutations/{DraftCustomScenario,ConfirmCustomScenario,DeleteCustomScenario}.php`, `frontend/app/components/CustomScenarioBuilder.vue`, `frontend/app/pages/talk/*`
**Статус:** active
