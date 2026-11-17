# Progress

Updated: 2026-11-17 · session: email-verification-password-reset — сброс пароля, подтверждение email и транспорт Resend

## Сейчас

Ветка `main`. Поверх разделённых настроек (`/settings/*`) добавлены подтверждение email,
сброс пароля и продакшн-почта через Resend:

- **Бэкенд:** `User` реализует `MustVerifyEmail` с японскими уведомлениями
  (`VerifyEmailNotification`, `ResetPasswordNotification`); ссылки строятся от
  `config('app.frontend_url')`. `EmailVerificationToken` подписывает ссылки HMAC-SHA256 по
  `APP_KEY` с TTL 60 минут. Регистрация (`Register`) и смена адреса в `UpdateProfile`
  отправляют письмо через `rescue(..., report: true)`. Добавлены GraphQL-мутации
  `forgotPassword`, `resetPassword`, `sendEmailVerificationNotification`, `verifyEmail`, поле
  `User.emailVerifiedAt`, валидаторы и зависимость `resend/resend-php`.
- **Фронтенд:** добавлены публичные маршруты `/forgot-password`, `/reset-password` (guest-only)
  и `/verify-email` (для гостей и авторизованных), ссылка и notice `?reset=success` на `/login`,
  а также бейдж статуса (`確認済み` / `未確認`) и повторная отправка в `/settings/account`.
  Японские строки вынесены в `frontend/app/constants/strings.ts`, коды ошибок — в
  `frontend/app/utils/graphql-error.ts`, типы сгенерированы в `frontend/app/types/graphql.ts`.
- **Инфра и конфиги:** локально по умолчанию остаётся `MAIL_MAILER=log`; в
  `.env.production.example`, `docker-compose.prod.yml` и Terraform (`variables.tf`, `ecs.tf`,
  `secrets.tf`) добавлены `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME=Kirie`,
  `FRONTEND_URL` и секрет `RESEND_API_KEY`. Таблицы маршрутов обновлены в `frontend/README.md`
  и `AGENTS.md`.

## Проверено

- **2026-11-17 (email-verification-password-reset):** полный прогон `php artisan test --compact`
  — `607 passed (3014 assertions)`, включая 9 новых тестов в `AuthGraphQLTest` с
  `Notification::fake()`; `./vendor/bin/pint --test` — `passed`;
  `php artisan lighthouse:validate-schema` — `The defined schema is valid.`;
  `composer validate --no-check-publish` — valid; резолв драйвера `ResendTransport` — успешно.
- **Фронтенд:** `npm run codegen` детерминирован (sha256 `app/types/graphql.ts` до и после
  совпадает: `d3e49ddb...`); `npm run typecheck` — passed (2026 ms); `npm run build` — passed
  (1.87 MB / 457 kB gzip); японских UI-литералов в шаблонах/скриптах затронутых `.vue` нет.
- **Инфра:** `docker compose --profile full -f docker-compose.yml -f docker-compose.prod.yml config`
  с тестовым env-файлом успешно рендерит `MAIL_*`, `RESEND_API_KEY` и `FRONTEND_URL`.
  `terraform fmt` / `terraform validate` не запускались (`terraform` не установлен — unverified).
  E2E-тесты Playwright для новых маршрутов и реальная доставка через Resend API пока не
  проверялись (unverified).

## Дальше

1. **Resend-домен и ключ** (при выкладке): подтвердить отправляющий домен в Resend под
   `MAIL_FROM_ADDRESS`, заполнить `RESEND_API_KEY` в `.env.production` или AWS Secrets Manager
   (`<name_prefix>/app-secrets` до `terraform apply`, т.к. у `secret_string` стоит `ignore_changes`).
2. **Stripe-аккаунт** (руками, когда подключаются оплаты): создать 6 price-объектов (JPY,
   tax-inclusive), добавить вебхук `https://$APP_DOMAIN/api/webhooks/stripe`, заполнить
   `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_*`.
3. **Деплой-блокеры AWS ECS** (актуально только при выборе AWS ECS вместо single-host
   `docker-compose.prod.yml`): ECR `IMMUTABLE` против `:latest`, `REVERB_HOST=127.0.0.1` в ECS,
   scheduler в ECS, RDS TLS (`MYSQL_ATTR_SSL_CA`), `GrammarPointSeeder` в migration-таск.

## Открытые вопросы

- Нужны ли отдельные Playwright `@smoke`-тесты для `/forgot-password`, `/reset-password` и
  `/verify-email` (с извлечением ссылки из `storage/logs/laravel.log` при `MAIL_MAILER=log`).
- Семантический проход graphify по документам (`.md`, PDF) отложен до реальной потребности;
  после коммита изменений стоит обновить граф через `graphify update .`.
- В `docs/design/` лежит только рендер хаба настроек; подэкраны описаны текстом.

## Блокировано

Ничего в коде (`terraform` CLI отсутствует на локальной машине).

## Прошлая сессия

- 2026-11-16 — `/settings` разделён на хаб (`index`) и четыре экрана (`account`, `billing`, `appearance`, `privacy`).
- 2026-11-16 — управление профилем и паролем в `/settings`, защита от дублей Checkout, legal-страницы.
- 2026-11-16 — graphify: граф кода, локальный скилл, `--code-only` без LLM.
