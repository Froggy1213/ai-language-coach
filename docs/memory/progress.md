# Progress

Updated: 2026-11-18 · session: voice-tts-recovery — Aura вместо Cartesia и 30 минут free в месяц

## Сейчас

Ветка `main`. Голосовой пайплайн переведён на **Deepgram Aura** как основной TTS: Cartesia
остаётся fallback и больше не участвует, пока задан `DEEPGRAM_API_KEY`. Причина перехода —
Cartesia была выбрана только за один мультиязычный голос, а продукт учит лишь английскому
(`config/languages.php`); Deepgram уже STT-вендор, дешевле и в замере не медленнее (ttfb
0.20–0.32 с против 0.35 с). Прежний голос Cartesia к тому же удалили из каталога, из-за чего
агент молчал (`Voice not found` → `no audio frames were pushed`).

Одновременно free-план стал **30 минут в месяц** вместо пожизненных 15: окно считается от даты
регистрации (`VoiceAllowanceResolver::freeAllowance`), `resetsAt` теперь не `null` и у free.

Заодно починена телеметрия TTS: у Deepgram `TTSMetrics` приходят после синтеза (~3.3 с) и без
`speech_id`, поэтому `tts_first_chunk_ms` выпадал из `TURN_LATENCY`. Вынесены
`TURN_FLUSH_DELAY_SECONDS = 5.0` и `TURN_DRAIN_TIMEOUT_SECONDS = 6.0`, добавлена привязка
метрики без `speech_id` к ожидающему ходу.

## Проверено

- **Сквозной программный прогон** (комната + explicit dispatch + 20 с английской речи из wav):
  Deepgram STT даёт транскрипты, Groq отвечает, TTS — `aura-2-thalia-en` / `ttfb` 0.20–0.32 с,
  `audio_duration` 6.2–6.8 с; `TURN_LATENCY` теперь с `tts_first_chunk_ms` 236.6.
- `cd agent && python3 -m unittest discover -s . -p "test_*.py"` — `Ran 115 tests`, `OK`
  (`skipped=16`): новый тест на приоритет Deepgram и на TTS-метрику без `speech_id`.
- `cd backend && php artisan test --compact` — `608 passed (3016 assertions)`;
  `./vendor/bin/pint --dirty` — passed.
- `cd frontend && npm run codegen` — diff в `app/types/graphql.ts` только комментарий поля
  `resetsAt`; `npm run typecheck` и `npm run build` — passed (1.87 MB / 457 kB gzip).
- Живой стек пересобран (`api`, `web`, `voice-agent`), все healthy; `config:show billing.free_seconds`
  = 1800; резолвер на реальном пользователе: `plan=free included=30min used=16min resets=2026-12-14`.
- `README.md`, `AGENTS.md`, `agent/README.md`, `infra/README.md` (цена TTS и total) обновлены под
  новый стек и лимит.

## Дальше

1. **Живой прогон из браузера** на `/lesson/{cardId}`: программный probe проверяет пайплайн, но не
   UI-транскрипты и не слышимость в колонках.
2. **Markdown в ответах агента**: в транскриптах вида `what **is** in the room` LLM отдаёт
   разметку, которая попадает в TTS-текст. Проверить, читается ли она вслух, и либо запретить
   markdown в промпте, либо чистить перед синтезом.
3. **Проверить Aura-2 на длинных репликах**: метрика приходит после полного синтеза; при ответах
   дольше ~5 с `tts_first_chunk` снова может потеряться (см. `gotchas.md`, 2026-11-18).

## Открытые вопросы

- Оставлять ли Cartesia в образе и ключах как fallback, или убрать совсем (сейчас она инертна).
- Free-план: 30 минут в месяц — это же значение для всех новых и текущих free-учёток? Сейчас
  применилось ко всем без миграции, использованные минуты считаются с даты регистрации.
- Не проверялись: Stripe checkout, Resend, деплой AWS (см. запись 2026-11-17).

## Блокировано

Ничего в коде.

## Прошлая сессия

- 2026-11-18 — голос восстановлен: удалённый голос Cartesia заменён, пайплайн проверен end-to-end.
- 2026-11-17 — подтверждение email, сброс пароля, Resend.
- 2026-11-16 — `/settings` разделён на хаб и четыре экрана; профиль и пароль.
