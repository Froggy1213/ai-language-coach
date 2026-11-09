# Progress

Updated: 2026-11-09 · session: 復習 как recall-first карточка по своим ошибкам

## Сейчас

Ветка `main`, редизайн 復習 выкачен в образы и закоммичен (`origin/main` синхронизирован).

**Что изменено:**

- **Бэкенд**: `dueReviews` переведён с `@all(scopes: ["due"])` на резолвер `App\GraphQL\Queries\DueReviews` —
  очередь и последняя ошибка на каждый grammar point за два batch-запроса. `ReviewItem.latestMistake`
  (`App\GraphQL\Resolvers\ReviewItemLatestMistake`) отдаёт `userUtterance/correction/explanation`;
  fallback-резолвер покрывает одиночный item из `submitReviewResult`.
- **Фронтенд**: `review.vue` — одна карточка за раз. Prompt — собственная фраза ученика из разговора,
  ответ за кнопкой «答えを見る», затем четыре оценки `もう一度/難しい/できた/完璧` → SM-2 `0/3/4/5`,
  прогресс `n / total`, после оценки «次はN日後», финал — «今日の復習は完了» с датой следующего повторения.
  Сырые `easeFactor/intervalDays` из UI убраны. `useReviews.submitReview` возвращает пересчитанный item.
- **Тесты**: два backend-теста на latestMistake (включая защиту от чужой ошибки и null), новый E2E-сценарий
  с замоканным `DueReviews` и `SubmitReviewResult`; обновлён первый review-спек под новую карточку.
- **Образы**: `ai_language_coach-api` и `-web` пересобраны, контейнеры пересозданы, Lighthouse cache очищен.

## Проверено в этой сессии

- `pint --test` — чисто (271 файл).
- `php artisan test` — 558 passed / 2800 assertions.
- `lighthouse:validate-schema` — схема валидна.
- `npm run codegen` → `git diff` только ожидаемый `latestMistake`; `npm run typecheck` — чисто;
  `npm run build` — чисто.
- `E2E_CHANNEL=chrome npm run e2e:smoke` — 17/17 (включая оба review-теста).
- Все 10 контейнеров healthy; `/up` 200.

## Дальше

- Живую очередь на стеке не проверить: у `test@example.com` сейчас 0 due-элементов, карточка покрыта
  E2E-моком и backend-тестами. Для ручной проверки можно сдвинуть `next_review_at` одного item в прошлое.
- Следующий кандидат на работу — экран 振り返り (`/reflect/:id`): те же ошибки, но без recall-цикла.

## Открытые вопросы

- 39 ссылок «README, решение N» в текущих документах висят в пустоту: раздел решений удалён из
  README коммитом `8a05be5`. Полный лог (пп. 1–62) — `git show 8a05be5^:README.md`.
- Датированный статус-блок плана (15.10.2026) расходится с кодом: `/practice/{cardId}` ведёт на
  `/lesson/{cardId}`, а роадмап теперь является 38-узловым DAG `A1–C1`.

## Блокировано

Ничего не заблокировано.

## Прошлая сессия

- 2026-11-09 — кастомные сценарии: пересборка образов, миграция `custom_scenarios`, E2E-фикс GET-моков.
- 2026-11-09 — граф знаний: пересборка образов, миграции, seeder, E2E.
- 2026-11-04 — создан слой памяти проекта (`AGENTS.md`, `docs/memory/`, подсистемные `AGENTS.md`).
