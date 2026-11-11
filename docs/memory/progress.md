# Progress

Updated: 2026-11-11 · session: Stripe-биллинг — подписки, минуты, paywall

## Сейчас

Ветка `main`, в рабочем дереве **незакоммиченная** реализация платной подписки через Stripe.
Продукт теперь умеет продавать минуты, а не «приложение»: три платных тира с годовыми ценами,
15 бесплатных минут навсегда и жёсткий лимит по минутам, посчитанный из
`voice_sessions.duration_sec`.

- **Планы** (`backend/config/billing.php`): Entry 799 ¥/мес или 7 990 ¥/год (150 мин/мес),
  Standard 1 480 / 14 800 (300), Pro 2 980 / 29 800 (700). Годовой тариф даёт те же минуты
  **помесячно**, а не годовой пакет. Японские названия — в `frontend/app/constants/strings.ts`
  по коду плана.
- **Оплата**: hosted Stripe Checkout + Billing Portal через `stripe/stripe-php` напрямую
  (Cashier не используется). Карты не проходят через наш домен.
- **Вебхук** `POST /api/webhooks/stripe`: подпись по сырому телу, таблица `stripe_events`
  (`insertOrIgnore` в одной транзакции с эффектом), повторная доставка — no-op.
- **Лимит минут**: `VoiceAllowanceResolver` считает использование по `duration_sec` за месячное
  окно от даты подписки (для годовой — тоже помесячно), незавершённая сессия учитывается по
  elapsed с потолком 15 минут. Проверка — в `VoiceSessionStarter` под `lockForUpdate`.
  Отказы: `VOICE_MINUTES_EXHAUSTED` (used/included/resetsAt в extensions) и
  `VOICE_SUBSCRIPTION_INACTIVE`.
- **Интерфейс**: публичная `/pricing` (гостям тоже), блок «プランと利用状況» в `/settings`,
  диалог `VoiceLimitGate` на `/lesson/:cardId` и `/talk/:code` вместо голого текста ошибки.
- **Удаление аккаунта** сначала отменяет подписку в Stripe; при сбое — `BILLING_CANCEL_FAILED`
  и аккаунт остаётся, чтобы не потерять единственный указатель на подписку.
- **Защита прода**: `ProductionSanityCheck` отказывает в старте с `sk_test_`-ключом.
- **Инфраструктура**: `STRIPE_*` проброшены в `docker-compose.prod.yml` и в ECS
  (`laravel_secret_keys` + пустые значения в `secrets.tf`); `FRONTEND_URL` задан как `APP_DOMAIN`.

## Проверено в этой сессии

- `php artisan test` — **592 passed / 2940 assertions** (включая `VoiceAllowanceTest`,
  `StripeWebhookTest`, `BillingGraphQLTest`, тесты отмены подписки при удалении аккаунта и
  `ProductionSanityCheckTest`).
- `pint --test` — чисто; `lighthouse:validate-schema` — валидна; `route:list` видит
  `/api/webhooks/stripe` (503 без секрета — ожидаемо).
- `npm run codegen` — стабилен (хеш до/после совпал), `npm run typecheck`, `npm run build` — чисто.
- E2E smoke — **18/18**, уже без `E2E_CHANNEL=chrome`; добавлен `pricing.spec.ts`.
- Стек пересобран (`api`, `web`) и поднят: 10 контейнеров healthy; `/up` 200;
  `billingPlans` GraphQL отдаёт три плана.

## Дальше

1. **Stripe-аккаунт** (руками): создать 6 price-объектов (JPY, tax-inclusive), добавить вебхук
   `https://$APP_DOMAIN/api/webhooks/stripe` на четыре события, заполнить `STRIPE_SECRET`,
   `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_*`; прогнать тестовый checkout картой `4242 4242 4242 4242`.
2. **Закоммитить** биллинг (сейчас всё в рабочем дереве).
3. **Деплой-блокеры из прошлой сессии** (не сделаны): TLS-прокси перед LiveKit 7880, ECR
   `IMMUTABLE` против `:latest`, `REVERB_HOST=127.0.0.1` в ECS, scheduler в ECS, RDS TLS,
   `GrammarPointSeeder` в migration-таск.
4. **Биллинг-долги**: proration/смена плана через портал (UI пока не показывает разницу),
   Stripe Tax/JCT (сейчас выключен), dunning-письма, top-up пакеты минут, политика возвратов,
   интеграция `voice:usage-report` с включёнными минутами плана.

## Открытые вопросы

- Цены указаны как tax-inclusive, Stripe Tax выключен. Регистрация JCT и выставление налогов —
  решение оператора, не кода.
- Политика возвратов и отмена годовой подписки (доступ до конца периода — сейчас так) не описаны
  в пользовательских документах.
- 39 ссылок «README, решение N» всё ещё висят в пустоту (см. прошлую запись).

## Блокировано

Ничего в коде. Реальный платёж нельзя проверить без Stripe-ключей и price-объектов.

## Прошлая сессия

- 2026-11-11 — Stripe-биллинг: планы, минуты, Checkout/Portal, вебхук, paywall, `/pricing`.
- 2026-11-09 — приватность: согласие на голос, retention assessment-транскриптов, PII в Sentry.
- 2026-11-09 — 復習 как recall-first карточка по своим ошибкам.
- 2026-11-09 — кастомные сценарии: пересборка образов, миграция `custom_scenarios`, E2E-фикс GET-моков.
- 2026-11-09 — граф знаний: пересборка образов, миграции, seeder, E2E.
