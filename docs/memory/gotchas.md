# Ловушки

Append-only, только то, что реально стоило времени и проверено. Дата у каждой записи.

## 2026-11-04 — Поиск по репозиторию тонет в vendor

`glob "*.md"` по корню вернул 1473 пути, почти все — `backend/vendor/**`. Поиск от корня без
пути или `include`-фильтра бесполезен: ищи от конкретной подсистемы, а `grep` держи внутри
`backend/app`, `frontend/app`, `agent`, `e2e`.

## 2026-11-04 — Номера решений в документах висят в пустоту

Проза ссылается на «README, решение 25/34/62» (39 таких ссылок в текущих `.md`), но раздел
«Key decisions & deviations from the plan» удалён из README коммитом `8a05be5`. Полный текст
восстанавливается из истории: `git show 8a05be5^:README.md` (раздел начинается со строки 106).
Ссылка не битая — просто указывает в git.

## 2026-11-04 — Без docker гейты бэкенда и e2e не идут

Тесты бэкенда подключаются к MySQL `127.0.0.1:3306` (`backend/phpunit.xml`), Redis нужен
резолверу подписок. Docker-демон на этой машине не запущен (сокет
`~/.orbstack/run/docker.sock` отсутствует), поэтому `php artisan test`,
`./scripts/verify-images.sh` и Playwright падают не из-за кода. Сначала `docker compose up -d`,
для e2e — `docker compose --profile full up -d`.

## 2026-11-04 — Локальный toolchain не равен CI

PHP 8.5.12 совпадает с CI. Node на хосте 26.13 против 22 в CI, Python 3.15.2 против 3.11.
Зелёные локальные `npm run build` и `unittest` — более слабое доказательство, чем зелёный CI:
расхождение версий — первый подозреваемый, когда CI красный, а локально всё проходит.

## 2026-11-04 — `backend/AGENTS.md` ссылается на несуществующие каталоги

Сгенерированные Boost-правила требуют читать `.ai/rules/index.md` и активировать скиллы из
`**/skills/**`. Ни того, ни другого в репозитории нет (проверено 2026-11-04). Не искать и не
создавать их без отдельного запроса; проектные законы — в корневом `AGENTS.md`.

## 2026-11-04 — Playwright запускается только через установленный Chrome

Своего Chromium у Playwright на этой машине нет: без `E2E_CHANNEL=chrome` прогон падает на
запуске браузера, а не в приложении (по `e2e/README.md`).

## 2026-11-07 — `bunny('Instrument Sans')` вешает сборку backend-образа

В `backend/vite.config.js` по умолчанию был подключен плагин `bunny` шрифтов из
`laravel-vite-plugin/fonts`. На этапе `RUN npm run build` в `backend/Dockerfile` сборка пыталась
скачать шрифты с bunny.net по сети, получая `ETIMEDOUT`. Удаление шрифта из vite.config решает
проблему (бэкенд — GraphQL API, blade-шаблоны не используются).

## 2026-11-09 — MySQL отклоняет FK с именем длиннее 64 символов

`$table->foreignId('prerequisite_grammar_point_id')->constrained('grammar_points')` генерирует
имя `grammar_point_prerequisites_prerequisite_grammar_point_id_foreign` — 65 символов, и MySQL
падает с `1059 Identifier name ... is too long`. Хуже: MySQL DDL не транзакционен, поэтому
`CREATE TABLE` и первый FK остаются в базе, а миграция не попадает в `migrations`; следующий
`migrate` упирается в «таблица уже существует». Лечение — явные короткие имена третьим
аргументом `constrained()` (см. `2026_11_07_000002_create_grammar_point_prerequisites_table.php`)
и ручной `DROP TABLE` частично созданной таблицы перед повтором. Дефект ловится `php artisan
test` (тесты идут на MySQL `language_coach_testing`), но только при прогоне миграций с нуля —
на уже поднятой БД он всплывает лишь на `migrate`.
