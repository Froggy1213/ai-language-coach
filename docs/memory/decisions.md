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

## 2026-11-18 — Голос Cartesia заменён на «Jessica — Clear Communicator»

**Решение:** дефолт `CARTESIA_VOICES['en']` в `agent/speech.py` — `25d7abcb-4d6d-4aca-adce-8a1c85620c8b`.
**Почему:** прежний id `248be419-…` удалён из каталога Cartesia, и синтез отвечал `Voice not
found`, то есть агент молчал при исправных STT и LLM. Id прочитан из каталога аккаунта
(`GET /voices`) и проверен реальным синтезом; Jessica выбрана за чёткую дикцию, важную для
учебного контента.
**Отвергнуто:** Deepgram Aura `aura-2-thalia-en` (рабочий fallback и единственный ключ уже есть,
но другой тембр и ещё один вендор TTS в пайплайне); Naledi/Katie/Julia/Skylar — синтез проверен,
но тон выбран как менее нейтральный для учебных фраз; оставить старый id и полагаться на
`CARTESIA_VOICE` в `.env` (дефолт в коде сломан для любой чистой установки).
**Следствие:** голоса Cartesia живут в каталоге и удаляются — при «тишине агента» первым делом
сверять id с `GET /voices` (см. `gotchas.md`, 2026-11-18).
**Файлы:** `agent/speech.py`
**Статус:** active

## 2026-11-18 — Основной TTS — Deepgram Aura, Cartesia остаётся fallback

**Решение:** `create_tts` (`agent/speech.py`) выбирает Deepgram Aura первым; Cartesia — только
если ключа Deepgram нет.
**Почему:** продукт учит только английскому (`config/languages.php`: `supported => ['en']`), а
уникальное преимущество Cartesia — один голос на 44 языка — не используется. Deepgram уже
STT-вендор: один аккаунт, один ключ, один каталог. Aura дешевле ($30/1M символов против
$38–65 у Cartesia) и по замеру не медленнее: ttfb 0.20–0.32 с у `aura-2-thalia-en` против
0.35 с у `sonic-3` в том же пайплайне. Каталог Cartesia уже удалил голос из-под агента — это
стоило тишины в эфире.
**Отвергнуто:** оставить Cartesia основным (второй вендор и уже случившийся сбой каталога);
ElevenLabs (в 2–5 раз дороже, мультиязычность сейчас не нужна).
**Следствие:** `CARTESIA_VOICES` в коде остаётся, но не участвует, пока задан
`DEEPGRAM_API_KEY`; `CARTESIA_VOICE` больше не влияет на локальный стек. Смена провайдера
потребовала правки телеметрии — см. `gotchas.md`, 2026-11-18.
**Файлы:** `agent/speech.py`, `agent/test_dialogue_config.py`, `README.md`, `AGENTS.md`,
`agent/README.md`, `infra/README.md`
**Статус:** active

## 2026-11-18 — Бесплатный план: 30 минут в месяц с окном от даты регистрации

**Решение:** `billing.free_seconds` = 1800; `VoiceAllowanceResolver::freeAllowance` считает
`usedSeconds` в месячном окне с анкором на `users.created_at`, а `resetsAt` — конец окна.
**Почему:** free-план был пожизненным trial; продукт решил давать 30 минут каждый месяц.
Анкор от регистрации, а не календарный месяц, не даёт короткого первого периода — это та же
логика, что у подписок (`usageWindow`).
**Отвергнуто:** календарный месяц (первый период зависит от даты регистрации); скользящие
30 дней (не совпадает с «месяцем» в UI и с окном подписок).
**Следствие:** `resetsAt` теперь не `null` и для free; `usageLifetime` удалён, `/settings/billing`
показывает «今月の利用»; японская копия про 15 минут заменена на 30 в месяц.
**Файлы:** `backend/config/billing.php`, `backend/.env.example`,
`backend/app/Billing/{VoiceAllowanceResolver,VoiceAllowance}.php`,
`backend/graphql/types/billing.graphql`, `backend/tests/Feature/Billing/*`,
`frontend/app/constants/strings.ts`, `frontend/app/pages/settings/billing.vue`,
`frontend/app/graphql/documents.ts`, `.env.production.example`, `docker-compose.prod.yml`
**Статус:** active

## 2026-11-20 — Терминальные подписки откатываются на free-лимит, активные строки приоритетнее новых неактивных

**Решение:** `VoiceAllowanceResolver::for` исключает статусы `canceled` и `incomplete_expired` и сортирует `active`/`trialing` раньше остальных статусов при выборе текущей подписки; если активных/неоплаченных строк нет, ученик возвращается на free-план (30 минут в месяц). Обе границы `monthlyWindow` считаются от исходного `$anchor` через `addMonthsNoOverflow`, чтобы анкоры 29–31 чисел не сдвигались и `resetsAt` всегда оставался в будущем. Вебхук-синхронизатор игнорирует откат терминальных подписок и даунгрейд `active` в `incomplete`, а события для уже удалённого пользователя подтверждает HTTP 200 без записи.
**Почему:** иначе отменённый подписчик навсегда застревал со статусом `VOICE_SUBSCRIPTION_INACTIVE` без доступа к free-лимиту, новая брошенная попытка чекаута перекрывала старую активную подписку, а короткие месяцы смещали `resetsAt` в прошлое.
**Отвергнуто:** удаление строк подписок при отмене (теряется аудит и привязка к Stripe-истории); пересчёт следующего окна от предыдущего `$start` (теряет 29–31 день после февраля).
**Следствие:** отмена в портале Stripe корректно возвращает пользователя на 30 free-минут после окончания периода; портал без `stripe_customer_id` и истории подписок отдаёт типизированную `BILLING_NO_SUBSCRIPTION`; повторная отмена удалённой в Stripe подписки не блокирует `deleteAccount`.
**Файлы:** `backend/app/Billing/{VoiceAllowanceResolver,VoiceMinutesExhausted,SubscriptionSynchronizer,StripeSdkGateway,BillingCheckout,BillingPortalUnavailable}.php`, `backend/app/Voice/StartVoiceSession.php`, `backend/app/GraphQL/Mutations/CreateBillingPortalSession.php`, `frontend/app/composables/{useVoiceSession,useBilling}.ts`, `frontend/app/components/VoiceLimitGate.vue`, `frontend/app/pages/{pricing,settings/billing}.vue`, `frontend/app/constants/strings.ts`
**Статус:** active

## 2026-11-20 — Именованные per-mutation rate limiter'ы для GraphQL вместо общего @throttle по IP

**Решение:** в `AppServiceProvider::registerRateLimiters` заведены 16 изолированных именованных лимитеров (`gql-*`); для публичных auth/email мутаций ключ — IP, для аутентифицированных — ID пользователя (`sanctum` / default guard, fallback на IP). В `backend/graphql/schema.graphql` все мутации переведены на `@throttle(name: "gql-*")`, добавлен лимит `gql-review-submit` (60/мин) на `submitReviewResult`, а внутренние эндпоинты агента в `backend/routes/api.php` подняты до `600,1` (`/fail`) и `1200,1` (`/turns`).
**Почему:** Lighthouse `@throttle(maxAttempts, decayMinutes)` без `name`/`prefix` пишет всё в один bucket по IP, поэтому частые мутации одного пользователя или одного NAT сжигали лимит других пользователей и несвязанных критичных операций (например, `deleteAccount`). Низкий лимит `60,1` на `/turns` угрожал троттлингом потоковых реплик от воркеров агента.
**Отвергнуто:** задавать только `prefix` в SDL (требует дублирования конфигурации вместо центральной карты лимитов в провайдере и не переключает ключ с IP на `user_id`).
**Следствие:** лимиты мутаций независимы между операциями и пользователями (для аутентифицированных маршрутов).
**Файлы:** `backend/app/Providers/AppServiceProvider.php`, `backend/graphql/schema.graphql`, `backend/routes/api.php`, `backend/tests/Feature/Privacy/DeleteAccountThrottleTest.php`
**Статус:** active

## 2026-11-20 — Потолок SM-2 интервала в 365 дней и миграция review_items.next_review_at на DATETIME

**Решение:** в `Sm2::calculate` введён верхний потолок `Sm2::MAX_INTERVAL_DAYS = 365`, а колонка `review_items.next_review_at` переведена новой миграцией с `TIMESTAMP` на `DATETIME`.
**Почему:** при серии идеальных ответов (`quality = 5`) экспоненциальный рост SM-2 за ~12 повторений выводил `next_review_at` за границу MySQL `TIMESTAMP` (`2038-01-19`) и ломал `submitReviewResult` SQL-ошибкой переполнения даты.
**Отвергнуто:** оставить только `DATETIME` без ограничения интервала (бесконечный рост интервала до десятков лет бессмысленен для языкового повторения); только кап без `DATETIME` (остаётся риск 2038 для долгоживущих данных).
**Следствие:** максимальный интервал повторения ограничен 1 годом, переполнение 2038 исключено.
**Файлы:** `backend/app/Review/Sm2.php`, `backend/database/migrations/2026_11_20_000001_change_review_items_next_review_at_to_datetime.php`, `backend/tests/Unit/Review/Sm2Test.php`, `backend/tests/Feature/Review/SubmitReviewResultTest.php`
**Статус:** active

## 2026-11-20 — Стриппинг Markdown перед TTS, разделители <scenario_data> и grace period при обрыве WebRTC

**Решение:** в `agent/prompts.py` добавлен инвариант запрета Markdown для TTS, а также потоковый фильтр `strip_markdown_stream` / `strip_markdown_for_tts` на уровне `tts_node` и стартового приветствия (`agent/session.py`). Пользовательские поля сценария оборачиваются в `<scenario_data>...</scenario_data>` с очисткой попыток закрыть тег. При `participant_disconnected` агент ждёт `PARTICIPANT_DISCONNECT_GRACE_SECONDS = 6.0` (отменяя таймер при реконнекте), а на любом сбое инициализации или рантайма вызывает `ctx.shutdown()`. `TurnMetricsTracker.drain` немедленно сбрасывает ожидающие ходы и дожидается фоновых HTTP-задач; `agent/backend.py` переиспользует общий `aiohttp.ClientSession` и делает до 2 ретраев на 5xx/сетевых сбоях в `report_session_turn`.
**Почему:** это закрывает открытую задачу из прошлой сессии (Markdown-разметка в ответах LLM перед синтезом речи), защищает промпт кастомных сценариев от инъекций, устраняет потерю последней реплики при завершении звонка и обрывы сессии на коротких мобильных провалах сети.
**Отвергнуто:** полагаться только на системный промпт без фильтрации потока перед TTS (LLM периодически всё равно вставляет `**bold**`); закрывать комнату мгновенно при `participant_disconnected`.
**Следствие:** реплики перед TTS очищены от Markdown, финальные ходы гарантированно доставляются в Laravel перед выходом воркера.
**Файлы:** `agent/{prompts,session,turn_tracker,turn_metrics,backend,test_prompts,test_turn_metrics}.py`
**Статус:** active

## 2026-11-20 — AWS S3 без накопления noncurrent-аудио, Cloud Map для Reverb и CD после зелёного CI с immutable SHA-тегами

**Решение:** на S3-бакете аудиозаписей ассессмента (`infra/terraform/s3.tf`) версионирование переведено в `Suspended` с lifecycle-очисткой `noncurrent_version_expiration = 1` день, `expired_object_delete_marker = true` и `AbortIncompleteMultipartUploads`. В ECS (`infra/terraform/ecs.tf`, `security_groups.tf`, `alb.tf`) добавлены Cloud Map namespace `*.internal` для `reverb` (`REVERB_SCHEME=http`, порт 8081 между задачами), singleton-сервис `scheduler` (`php artisan schedule:work` от `www-data`), IAM-политика `cloudwatch:PutMetricData`, а публичное правило ALB для Reverb сужено до `/app/*`. На LiveKit EC2 (`infra/livekit/user-data.sh`, `security_groups.tf`) открыт `7882/udp` и поднят Caddy TLS-прокси на `:443 -> 127.0.0.1:7880`. В `.github/workflows/cd.yml` деплой гейтится через `workflow_run` успешного `CI` и пушит только immutable теги `sha-<commit>` во все 6 сервисов ECS и `migration`.
**Почему:** включённое версионирование без очистки noncurrent-версий оставляло удалённые записи голоса в S3 навсегда вопреки `PRIVACY.md`; `REVERB_HOST=127.0.0.1` не работает между отдельными Fargate-задачами (`awsvpc`); пуш `:latest` в `IMMUTABLE` ECR ломал повторный деплой; отсутствие TLS на 443 и закрытый `7882/udp` блокировали WebRTC на LiveKit EC2.
**Отвергнуто:** переключать ECR в `MUTABLE` и деплоить `:latest` (ломает детерминированный откат ревизий ECS); публиковать `/apps/*` наружу через ALB.
**Следствие:** удаление аудио в S3 физически удаляет объекты, ECS-таски общаются с Reverb по внутреннему DNS, деплой в AWS идёт только после зелёного CI по точным SHA-образам.
**Файлы:** `infra/terraform/{s3,ecs,security_groups,alb,secrets,cloudwatch,variables,outputs}.tf`, `infra/livekit/{user-data.sh,livekit.yaml.tpl,docker-compose.yml.tpl}`, `infra/README.md`, `.github/workflows/cd.yml`, `scripts/smoke-test.sh`, `docker-compose.yml`, `docker-compose.prod.yml`, `backend/Dockerfile`, `backend/config/lighthouse.php`
**Статус:** active
