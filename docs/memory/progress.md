# Progress

Updated: 2026-11-16 · session: settings-hub — `/settings` разделён на хаб и четыре экрана

## Сейчас

Ветка `main`. Поверх пре-релизного аудита, графа роадмапа и управления профилем/паролем
страница настроек перестроена по принципу «один экран — одна задача»:

- **Хаб** (`frontend/app/pages/settings/index.vue`): карточка личности (имя, email, CEFR),
  строки входа в «アカウント», «プラン», «表示» и «データ», выход и юридические ссылки. Строки —
  `SettingsRow`, общая рамка подэкранов — `SettingsPage`.
- **`/settings/account`**: форма имени/email (без дублирующего `dl`), язык и уровень только для
  чтения с пояснениями, смена пароля.
- **`/settings/billing`**: план, статус Stripe (включая `unpaid`), остаток минут с прогрессом,
  Portal; читает `?checkout=success`, показывает подтверждение и вычищает query, не блокируя
  загрузку плана. `BillingCheckout` возвращает Checkout и Portal на этот маршрут,
  `VoiceLimitGate` ведёт туда же. Для бесплатного плана подпись — «これまでの利用», для
  платного — «今月の利用».
- **`/settings/appearance`**: тема двумя превью (нативные radio) вместо сегментного контрола.
- **`/settings/privacy`**: запись согласия на голос с датой, объяснение и ссылка на политику,
  удаление аккаунта за паролем и `DELETE`; после записи согласия фокус уходит на live-регион
  статуса.
- `useHashScroll` доводит `#password`/`#delete` до якоря при холодной загрузке; `planLabel`
  вынесен в `utils/plan-label.ts` и не печатает неизвестный код плана по-английски.
- Копия — только в `settings`/`billing` в `frontend/app/constants/strings.ts`; японских литералов
  в шаблонах нет.
- `e2e/settings.spec.ts` ходит по хабу и всем четырём подэкранам (включая `?checkout=success`);
  `e2e/pricing.spec.ts` проверяет возврат с отменённого Checkout. Таблицы маршрутов обновлены в
  `frontend/README.md`, `e2e/README.md`, `AGENTS.md`, `docs/design/README.md`.
- `docs/design/settings-mobile.jpg` переснят с прод-сборки (хаб, тёмная тема).

## Проверено

- **2026-11-16 (settings-hub):** `npm run typecheck` и `npm run build` — чисто; полный `@smoke`
  против пересобранного `coach_web` — `18 passed` (52.7 s), включая `auth.spec.ts`,
  расширенный `settings.spec.ts` и `pricing.spec.ts`. Проверено вручную: `?checkout=success`
  показывает notice и исчезает после `router.replace`; холодная загрузка
  `/settings/account#password` и `/settings/privacy#delete` скроллит к якорю.
- Бэкенд: `php artisan test --compact --filter=BillingGraphQLTest` — `11 passed (49 assertions)`;
  `./vendor/bin/pint --test` — `PASS 308 files`; `npm run codegen` воспроизводим (sha256
  `graphql.ts` до и после совпадает). Diff `graphql.ts` против HEAD — это незакоммиченная схема
  `updateProfile`/`updatePassword` прошлой сессии, не дрейф генерации.
- Визуальная проверка: хаб и все четыре подэкрана сняты Playwright в тёмной и светлой темах,
  десктоп 1280×900 и мобайл 390×844; в консоли только известный отказ Echo-сокета на неверном
  порту Reverb в dev-конфиге.
- Итоговый образ `ai_language_coach-web` пересобран и поднят в `docker compose`; `coach_web` —
  healthy, прямые загрузки `/settings` и `/settings/account` отдают 200.
- `graphify update .` — `3700` узлов, `7768` рёбер, `253` сообщества.

## Дальше

1. **Stripe-аккаунт** (руками, когда подключаются оплаты): создать 6 price-объектов (JPY,
   tax-inclusive), добавить вебхук `https://$APP_DOMAIN/api/webhooks/stripe`, заполнить
   `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_*`.
2. **Деплой-блокеры AWS ECS** (актуально только при выборе AWS ECS вместо single-host
   `docker-compose.prod.yml`): ECR `IMMUTABLE` против `:latest`, `REVERB_HOST=127.0.0.1` в ECS,
   scheduler в ECS, RDS TLS (`MYSQL_ATTR_SSL_CA`), `GrammarPointSeeder` в migration-таск.

## Открытые вопросы

- В `docs/design/` лежит только рендер хаба; подэкраны описаны текстом. Добавлять ли отдельные
  рендеры `settings-account`, `settings-billing` и т.д., если они понадобятся как референс.
- Семантический проход graphify по документам (`.md`, PDF) по-прежнему требует LLM-бэкенда и
  отложен до реальной потребности.
- Скилл graphify лежит в репозитории (`.agents/skills`), а не глобально: `~/.dsh/skills` и
  `~/.agents/skills` — запись вне воркспейса, нужен approval.

## Блокировано

Ничего в коде.

## Прошлая сессия

- 2026-11-16 — управление профилем и паролем в `/settings`, защита от дублей Checkout, legal-страницы.
- 2026-11-16 — graphify: граф кода, DSH-скилл, `--code-only` без LLM, `graphify-out/` в `.gitignore`.
- 2026-11-11 — Stripe-биллинг: планы, минуты, Checkout/Portal, вебхук, paywall, `/pricing`.
