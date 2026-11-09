# Progress

Updated: 2026-11-09 · session: приватность — PII в Sentry, retention транскриптов, явное согласие

## Сейчас

Ветка `main`, рабочее дерево содержит незакоммиченные исправления по privacy-ревью.

- **PII в ошибках**: четыре LLM-анализатора больше не кладут `json_encode($structured)` в message
  исключения; `SentryEventScrubber` чистит message события и значения исключений по JSON-ключам
  (`user_utterance`, `transcript`, `correction`, …) и покрыт тестом.
- **Retention**: `privacy:purge-transcripts` теперь удаляет и `assessments.raw_data.transcript`
  старше 90 дней (`JSON_REMOVE`), сохраняя уровень, pronunciation и summary; dry-run и тест на месте.
- **Согласие**: новая мутация `recordVoiceConsent`; `requestVoiceToken`/`submitAssessment` отказывают
  с `VOICE_CONSENT_REQUIRED` без неё; автоматическая фиксация при старте/загрузке удалена.
  UI: диалог `VoiceConsentGate` на `/lesson/:cardId` и `/talk/:code`, запись согласия с чекбокса на `/level`.
- **Образы**: `ai_language_coach-api` и `-web` пересобраны, контейнеры пересозданы, Lighthouse cache очищен.

## Проверено в этой сессии

- `php artisan test` — 563 passed / 2820 assertions (включая `VoiceConsentTest`, `RetentionPurgeTest`,
  `SentryConfigurationTest`).
- `pint --test` — чисто (272 файла); `lighthouse:validate-schema` — валидна.
- `npm run codegen && npm run typecheck && npm run build` — чисто.
- E2E smoke — 17/17, уже **без** `E2E_CHANNEL=chrome`: Chrome на машине больше нет, используется
  Playwright Chromium. Диалог согласия проверен вручную на пользователе с `voice_consent_at = NULL`.
- Все 10 контейнеров healthy; `/up` 200.

## Дальше

Приоритеты из полного ревью, ещё не сделаны:

1. **P0-деплой**: ECR `IMMUTABLE` vs push `:latest`, `REVERB_HOST=127.0.0.1` в ECS, scheduler в ECS
   (без него retention не работает в AWS), `MYSQL_ROOT_PASSWORD` в prod overlay, проверить RDS TLS.
2. **Abuse**: вынести `draftCustomScenario` в Horizon (сейчас 60 с LLM в PHP-воркере), дневные лимиты
   на assessments/drafts, throttle на `requestVoiceToken`, дедуп assessment по S3 key, throttle вебхука.
3. **Edge**: закрыть `/api/internal/*` на ALB/Caddy, LiveKit-секрет из EC2 user_data в Secrets Manager,
   Redis auth, CSP, запрет `TRUSTED_PROXIES=*` в `ProductionSanityCheck`.
4. **Фронтенд-сессия**: проверять ошибку logout, не трактовать сбой `me` как «гость», глобальный 401.
5. **Зависимости**: `composer update "symfony/*"` (8 advisories), `npm audit fix` для `undici`, Nuxt upgrade.

## Открытые вопросы

- 39 ссылок «README, решение N» в текущих документах висят в пустоту: раздел решений удалён из
  README коммитом `8a05be5`. Полный лог (пп. 1–62) — `git show 8a05be5^:README.md`.
- Датированный статус-блок плана (15.10.2026) расходится с кодом: `/practice/{cardId}` ведёт на
  `/lesson/{cardId}`, а роадмап теперь является 38-узловым DAG `A1–C1`.

## Блокировано

Ничего не заблокировано.

## Прошлая сессия

- 2026-11-09 — 復習 как recall-first карточка по своим ошибкам.
- 2026-11-09 — кастомные сценарии: пересборка образов, миграция `custom_scenarios`, E2E-фикс GET-моков.
- 2026-11-09 — граф знаний: пересборка образов, миграции, seeder, E2E.
