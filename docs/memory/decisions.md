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

## 2026-11-09 — 復習: recall-first по собственной ошибке, четыре оценки вместо 0–5

**Решение:**
1. Карточка 復習 показывает `mistakes.user_utterance` последней ошибки по правилу как prompt; исправление и объяснение открываются кнопкой «答えを見る»
2. Оценка — четыре подписанные кнопки (`もう一度 / 難しい / できた / 完璧`), отображаемые на SM-2 `0 / 3 / 4 / 5`; сырые `easeFactor`/`intervalDays` из UI убраны.
3. `dueReviews` резолвится `App\GraphQL\Queries\DueReviews`: очередь и последняя ошибка на каждый grammar point — двумя batch-запросами без N+1; `ReviewItem.latestMistake` отдаёт её, fallback-резолвер покрывает одиночный item из мутации.
4. Поток — по одной карточке, прогресс `n / total`, после оценки «次は6日後», в конце — экран завершения с датой следующего повторения.
**Почему:** страница просила оценить память, не давая ничего вспомнить; при этом каждый `review_item` рождён из `mistake` с фразой ученика, исправлением и объяснением, а design-док говорит, что review хочет «the mistakes and nothing else».
**Отвергнуто:** cheat sheet правила как prompt (менее лично); оставить 0–5 (без легенды непонятно, особенно на телефоне); дублировать SM-2 на клиенте для превью интервалов до сохранения.
**Файлы:** `backend/graphql/schema.graphql`, `backend/graphql/types/review-item.graphql`, `backend/app/GraphQL/Queries/DueReviews.php`, `backend/app/GraphQL/Resolvers/ReviewItemLatestMistake.php`, `backend/tests/Feature/GraphQL/QueryGraphQLTest.php`, `frontend/app/pages/review.vue`, `frontend/app/composables/useReviews.ts`, `frontend/app/graphql/documents.ts`, `frontend/app/constants/strings.ts`, `frontend/app/types/graphql.ts`, `e2e/review.spec.ts`, `frontend/README.md`
**Статус:** active

## 2026-11-09 — Явное согласие на голос, retention assessment-транскриптов и PII вне exception message

**Решение:**
1. Согласие на запись/обработку голоса фиксируется только мутацией `recordVoiceConsent`; UI показывает отдельный диалог перед первой сессией на `/lesson` и `/talk`, а `/level` записывает согласие по чекбоксу. `requestVoiceToken` и `submitAssessment` отказывают с `VOICE_CONSENT_REQUIRED`, если `voice_consent_at` пуст.
2. `AnalyzeAssessment` продолжает хранить транскрипт в `assessments.raw_data`, но `privacy:purge-transcripts` теперь удаляет `raw_data.transcript` старше 90 дней (JSON_REMOVE), сохраняя уровень, pronunciation и summary.
3. Сообщения исключений LLM-анализа больше не содержат сериализованный payload; `SentryEventScrubber` дополнительно чистит message события и значения исключений по JSON-ключам (`user_utterance`, `transcript`, `correction`, …).
**Почему:** ревью показало, что `voice_consent_at` фиксировал первый вызов, а не решение ученика; транскрипт онбординга не попадал под retention; payload с речью ученика уходил в Sentry/CloudWatch через текст исключения.
**Отвергнуто:** оставить неявную фиксацию согласия (нет доказательства действия); отдельная команда только для assessments (дублирование расписания); класть payload в лог с ручной фильтрацией.
**Файлы:** `backend/app/GraphQL/Mutations/RecordVoiceConsent.php`, `backend/app/Voice/StartVoiceSession.php`, `backend/app/Voice/VoiceSessionStarter.php`, `backend/app/GraphQL/Mutations/SubmitAssessment.php`, `backend/app/Console/Commands/PurgeTranscripts.php`, `backend/app/Observability/SentryEventScrubber.php`, `backend/app/{Mistakes,Assessments,Scenarios}/DeepSeek*.php`, `frontend/app/components/VoiceConsentGate.vue`, `frontend/app/composables/useVoiceConsent.ts`, `frontend/app/pages/{lesson,talk}/**`, `frontend/app/pages/level.vue`, `PRIVACY.md`
**Статус:** active

## 2026-11-11 — Stripe-подписки с помесячным лимитом минут

**Решение:**
1. Три платных тира (`entry` 799/7 990 ¥, `standard` 1 480/14 800 ¥, `pro` 2 980/29 800 ¥)
   и 15 бесплатных минут навсегда. Суммы и включённые минуты — в `backend/config/billing.php`,
   японские названия и описания — в `frontend/app/constants/strings.ts` по коду плана; API
   отдаёт только код, цену и минуты.
2. Hosted Stripe Checkout + Billing Portal через `stripe/stripe-php` напрямую, без Cashier и без
   Elements: карты не проходят через наш домен, отмена и смена карты живут в портале Stripe.
3. Минуты считаются из `voice_sessions.duration_sec`, а не отдельным счётчиком. Окно — месяц от
   даты подписки (`usage_anchor_at`), в том числе для годового тарифа; незавершённая сессия
   учитывается по elapsed с потолком `voice.max_session_seconds`.
4. Проверка стоит в `VoiceSessionStarter` внутри транзакции под `lockForUpdate`, рядом с дневным
   лимитом; отказ — типизированные `VOICE_MINUTES_EXHAUSTED` (used/included/resetsAt в
   extensions) и `VOICE_SUBSCRIPTION_INACTIVE` (status).
5. Вебхук `POST /api/webhooks/stripe` проверяет подпись по сырому телу (`constructEvent`) и
   пишет `stripe_events.stripe_event_id` через `insertOrIgnore` в одной транзакции с эффектом:
   повторная доставка — no-op, упавшая обработка откатывает и дедуп-строку, и Stripe может
   повторить.
6. Удаление аккаунта сначала отменяет подписку в Stripe. Если Stripe недоступен или отказал —
   `BILLING_CANCEL_FAILED`, аккаунт остаётся: локальная строка — единственный указатель на
   подписку, и удалить её, пока ученика продолжают списывать, значит сделать ошибку необратимой.
7. `ProductionSanityCheck` отказывает в старте с `sk_test_`-ключом: тестовый checkout выглядит
   здоровым и не берёт денег.

**Почему:** 799 ¥ покрывают лишь ~150 минут, поэтому продаются минуты, а не «приложение».
Hosted Checkout убирает PCI-поверхность. Ledger из сессий не расходится с вебхуками, в отличие от
счётчика. Годовой тариф не должен выдавать годовой пакет минут: лимит остаётся помесячным.

**Отвергнуто:** `laravel/cashier` (совместимость с Laravel 13 и собственный слой подписок);
встроенная форма Elements (PCI и больше кода); счётчик минут в отдельной таблице (дрейф при
ретраях); календарный месяц (короткий первый период для подписавшихся 30-го); безлимит на 799;
разрешить тестовые ключи в production; отмена подписки после удаления строки.

**Файлы:** `backend/config/billing.php`, `backend/app/Billing/*`, `backend/app/GraphQL/Queries/Billing{Plans,Overview}.php`,
`backend/app/GraphQL/Mutations/Create{CheckoutSession,BillingPortalSession}.php`,
`backend/app/Http/Controllers/StripeWebhookController.php`, `backend/app/Voice/VoiceSessionStarter.php`,
`backend/app/Voice/StartVoiceSession.php`, `backend/app/Privacy/AccountDeletionService.php`,
`backend/app/Support/ProductionSanityCheck.php`, `backend/graphql/types/billing.graphql`,
`frontend/app/pages/pricing.vue`, `frontend/app/components/VoiceLimitGate.vue`,
`frontend/app/composables/useBilling.ts`, `docker-compose.prod.yml`, `infra/terraform/{ecs,secrets}.tf`
**Статус:** active

## 2026-11-16 — Граф кода: graphify, только код, только локально

**Решение:** в репозиторий добавлен скилл `.agents/skills/graphify/SKILL.md` (формат DSH:
папка + `SKILL.md` с frontmatter), граф собирается `graphify extract . --code-only` и живёт в
`graphify-out/`, добавленном в `.gitignore`. Первая разведка по незнакомой области идёт через
`graphify explain` / `affected` / `path` / `query`, а не через grep+read; маршрут записан в
`AGENTS.md` §Граф кода, детали — в самом скилле.

**Почему:** апстрим-скилл написан под Claude Code: его шаги вызывают
`python3 -c "import graphify"` и Agent tool, а на этой машине пакет лежит в приватном venv
`uv tool` и импорт падает. Поэтому скилл переписан под CLI, которым мы реально пользуемся, и
под инструменты DSH. `--code-only` даёт 3.6k узлов за ~9 с и ноль токенов; семантический
проход по 32 документам и 6 картинкам стоил бы LLM-вызовов и пока не нужен. `graphify-out/`
не коммитим: `graph.json` ~4 МБ и протухает на каждом коммите, пересборка дешевле ревью.

**Отвергнуто:** `graphify install --project --platform agents` (копирует апстрим-скилл с
python-импортами и вызовами Claude Agent tool); установка в `~/.agents/skills` или
`~/.dsh/skills` (навык про конкретный репозиторий, плюс запись вне воркспейса требует approval);
коммит `graphify-out/`; `--mcp`-сервер (хостовый конфиг и процесс на каждую сессию ради команд,
которые CLI отдаёт и так).

**Файлы:** `.agents/skills/graphify/SKILL.md`, `.gitignore`, `AGENTS.md` §Граф кода
**Статус:** active

## 2026-11-16 — Настройки разделены на хаб и четыре экрана

**Решение:** `/settings` стал картой настроек: карточка личности (имя, email, CEFR), строки
входа в «アカウント», «プラン», «表示» и «データ», выход и юридические ссылки. Управление ушло на
отдельные маршруты: `/settings/account` (имя/email, язык и уровень только для чтения, пароль),
`/settings/billing` (план, минуты, Stripe Portal; сюда же возвращает Checkout),
`/settings/appearance` (тема двумя превью), `/settings/privacy` (запись согласия на голос и
удаление аккаунта). Общие рамка и строка — `SettingsPage.vue` / `SettingsRow.vue`; вся копия — в
`settings` и `billing` внутри `frontend/app/constants/strings.ts`.

**Почему:** прежняя страница смешивала чтение (имя, email, язык, уровень), две формы, биллинг,
тему, выход и необратимое удаление в один скролл; имя и email печатались дважды — в `dl` и сразу
под ним в полях. Хаб с одним экраном на задачу следует §«One page, one job» в
`docs/design/README.md`, сохраняет нативный для мобильного список и уводит опасное действие с
глазной дистанции остальных настроек.

**Отвергнуто:** вкладки или аккордеон внутри одного URL (нет deep-link, «назад» ведёт не туда,
и это всё равно один длинный роут); отдельная страница на каждую секцию (шесть страниц при пяти
строках — больше навигации, чем настроек); оставить одну страницу и свернуть секции (проблема не
в длине, а в смешении чтения и изменения).

**Файлы:** `frontend/app/pages/settings/{index,account,billing,appearance,privacy}.vue`,
`frontend/app/components/{SettingsPage,SettingsRow}.vue`, `frontend/app/composables/useHashScroll.ts`,
`frontend/app/utils/plan-label.ts`, `frontend/app/composables/{useBilling,usePrivacy}.ts`,
`frontend/app/constants/strings.ts`,
`frontend/app/components/VoiceLimitGate.vue`, `frontend/app/pages/pricing.vue`,
`backend/app/Billing/BillingCheckout.php`,
`backend/app/GraphQL/Mutations/CreateCheckoutSession.php`,
`backend/tests/Feature/Billing/BillingGraphQLTest.php`, `e2e/{settings,pricing}.spec.ts`,
`frontend/README.md`, `e2e/README.md`, `AGENTS.md`, `docs/design/README.md`,
`docs/design/settings-mobile.jpg`
**Статус:** active

## 2026-11-17 — Подтверждение email (stateless HMAC), сброс пароля через SPA и Resend API

**Решение:**
1. Транзакционная почта (подтверждение email и сброс пароля) в продакшене идёт по HTTPS API
   через встроенный драйвер Laravel `resend` (`resend/resend-php`), локально по умолчанию остаётся
   `MAIL_MAILER=log`.
2. Уведомления (`VerifyEmailNotification`, `ResetPasswordNotification`) формулируются на
   вежливом японском языке и строят ссылки относительно `config('app.frontend_url')`
   (`/verify-email` и `/reset-password`), а не `APP_URL`.
3. Подтверждение email использует stateless HMAC-SHA256 токен по `APP_KEY` в
   `App\Support\EmailVerificationToken` (`id|sha1(email)|expires`, срок жизни 60 минут) и
   публичную GraphQL-мутацию `verifyEmail`, возвращающую типизированные ошибки
   `EMAIL_VERIFICATION_INVALID` и `EMAIL_VERIFICATION_EXPIRED`.
4. Повторная отправка (`sendEmailVerificationNotification`) защищена `@guard` и возвращает `true`
   без отправки письма, если адрес уже подтверждён. `Register` и смена email в `UpdateProfile`
   отправляют письмо через `rescue(..., report: true)`, не срывая регистрацию или обновление
   профиля при ошибке почтового провайдера.
5. `forgotPassword` всегда возвращает `true` (без раскрытия существования аккаунта), а
   `resetPassword` сбрасывает пароль и `remember_token` через `Password::broker()->reset(...)`,
   отдавая `PASSWORD_RESET_INVALID` при неверном или истёкшем токене.

**Почему:**
В облачных и контейнерных средах исходящий SMTP часто заблокирован или требует отдельного relay,
тогда как Resend работает по HTTPS и уже прописан в `backend/config/mail.php` и `services.php`.
Поскольку клиент живёт в отдельном Nuxt SPA на `FRONTEND_URL`, письмо должно вести на маршруты
SPA, а stateless HMAC-подпись для `/verify-email` позволяет подтвердить адрес, даже если ссылку
открыли на другом устройстве или в браузере без активной cookie-сессии.

**Отвергнуто:**
Классический GET-маршрут Laravel `verification.verify` с редиректом и требованием активной
сессии (ломает сценарий открытия письма в другом браузере и уводит мимо SPA); SMTP-транспорт
для продакшена (больше сетевых и инфраструктурных зависимостей); жёсткий проброс исключений
почты из `Register` и `UpdateProfile` (временный сбой почты не должен блокировать создание
аккаунта или сохранение профиля при наличии кнопки повторной отправки в `/settings/account`).

**Файлы:** `backend/composer.{json,lock}`, `backend/config/app.php`, `backend/app/Models/User.php`,
`backend/app/Support/EmailVerificationToken.php`,
`backend/app/Notifications/{VerifyEmailNotification,ResetPasswordNotification}.php`,
`backend/app/GraphQL/Mutations/{Register,UpdateProfile,ForgotPassword,ResetPassword,SendEmailVerificationNotification,VerifyEmail}.php`,
`backend/app/GraphQL/Validators/{ForgotPasswordValidator,ResetPasswordValidator,VerifyEmailValidator}.php`,
`backend/graphql/{schema.graphql,types/user.graphql}`,
`backend/tests/Feature/GraphQL/AuthGraphQLTest.php`,
`frontend/app/pages/{login,forgot-password,reset-password,verify-email,settings/account}.vue`,
`frontend/app/{composables/useAuth.ts,constants/strings.ts,graphql/documents.ts,middleware/auth.global.ts,types/graphql.ts,types/view-models.ts,utils/graphql-error.ts}`,
`frontend/README.md`, `backend/.env.example`, `.env.production.example`, `docker-compose.prod.yml`,
`infra/terraform/{variables,ecs,secrets}.tf`, `AGENTS.md`
**Статус:** active
