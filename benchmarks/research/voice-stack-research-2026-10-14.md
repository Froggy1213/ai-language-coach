# Real-Time Voice AI Stack — Verified Pricing, Latency & Line-Ups

**Research date: 2026-10-14.** Every claim below was fetched live on that date. Vendor pricing pages and official docs are preferred; the three aggregators used are labelled **[AGGREGATOR]**.

**Method note / limitation.** The `web_fetch` tool was unusable in this environment (it rejected vendor domains with `URL hostname resolves to a non-public IP address`). All pages were retrieved with `curl` — either directly or through the Jina Reader proxy (`https://r.jina.ai/<url>`), which returns rendered text. **Consequence:** several vendors render their price tables in client-side JS, so a few tables came back empty or partial. Where that happened I say so explicitly rather than filling the gap. HTTP status and byte size were recorded for every fetch.

**Headline correction to the briefing's assumptions.** The model landscape has moved a full generation past the ids named in the brief. Llama 4 is **retired from Groq**; `gpt-4.1-mini` is **deprecated with a shutdown date**; the Gemini line is at **Gemini 4 Argon / 3.8 Flash**; OpenAI is on **gpt-6.x**; Moonshot is at **Kimi K3**; and Cartesia's `sonic-2`/`sonic-turbo` are now filed under "older models". Details in each section.

---

## Verification note (added after the research run, from this account's own APIs)

The report below was produced with `curl` against vendor pages, and two of its
findings were re-checked directly against the providers this project holds keys
for. Read those two sections with this in hand:

**Deepgram (report §2) — partly superseded.** The report recommends Deepgram Flux
for its model-native end of turn. Queried live, `GET /v1/models` on this project's
account lists 460 STT entries and **no `flux` model at all**; the available
generations are `nova-3-general` (64 languages, streaming and batch), `nova-2-*`
(still fully present, so the report is right that Nova-2 is not deprecated, and
wrong to imply it is on the way out), and Whisper. So:

- the change actually made here is `nova-2` → `nova-3` for both passes, which the
  plugin already defaulted to and the pinned call was overriding;
- Flux — and with it the "200–600 ms saved by model-native end of turn" claim —
  is not available to deploy today and is not relied on anywhere in this codebase;
- end-of-turn detection is handled instead by the turn detector that
  `AgentSession` already uses: `livekit-local-inference`'s on-device
  `turn-detector-v1-mini`, whose weights ship inside the wheel (34 MB, ~70 ms to
  load, no network). That is verified by inspection of the installed package, not
  by a vendor page.

Voice ids in `agent/agent.py` (`aura-2-*`) were likewise read from the same
`/v1/models` response rather than taken from the report.

**Dialogue LLM (report §1) — the ranking is unmeasured.** The report is explicit
that neither Groq nor Cerebras publishes a TTFT figure, so its ordering rests on
vendor throughput claims. Nothing in this repository depends on that ordering: the
provider order in `agent.py` is a default, `benchmarks/dialogue_llm_latency.py` is
the authority, and the guard that keeps a disqualified provider out of the dialogue
is driven by this project's own measurement (DeepSeek, 1068 ms p95) rather than by
the report's table. Model ids move; re-run the benchmark against the account's
`/models` listing before trusting any list, including this one.

**Not verified by me:** the pronunciation-assessment section (§4) and the audio-LLM
literature (§5). Both are consistent with the design already in place — the CEFR
assessor is given measured evidence and is explicitly told not to treat recogniser
confidence as a pronunciation score — but the citations there were not
independently checked.

---

## 1. DIALOGUE LLM (latency-critical)

### 1.1 What each provider actually sells today

| Provider | Current model id | Vendor-claimed output speed | Input / output per 1M | OpenAI-compatible | Confidence |
|---|---|---|---|---|---|
| **Cerebras** | `gpt-oss-120b` | **~3000 tok/s** | $0.35 / $0.75 | Yes — `api.cerebras.ai/v1/chat/completions` | High (vendor) |
| **Cerebras** | `qwen-3.8-27b` | **~1850 tok/s** | $0.99 / $1.49 | Yes | High (vendor) |
| **Groq** | `openai/gpt-oss-20b` | **1000 tok/s** | $0.075 / $0.30 | Yes — `api.groq.com/openai/v1` | High (vendor) |
| **Groq** | `openai/gpt-oss-120b` | **500 tok/s** | $0.15 / $0.60 | Yes | High (vendor) |
| **Groq** | `qwen/qwen3.8-27b` (preview) | 450 tok/s | $0.80 / $4.00 | Yes | High (vendor) |
| **Google** | `gemini-3.8-flash` | not published | $0.75 / $3.75 → **$1.50 / $7.50 from 2027-01-01** | Yes — dedicated compat docs | High (vendor) |
| **Google** | `gemini-3.5-flash-lite` | not published | $0.30 / $2.50 | Yes | High (vendor) |
| **Google** | `gemini-3.1-flash-lite` | not published | $0.25 text / $0.50 audio in; $1.50 out | Yes | High (vendor) |
| **OpenAI** | `gpt-6-luna` | not published | $0.10 / $0.50 (Standard) | Native | High (vendor) |
| **OpenAI** | `gpt-6.1-sol` | not published | $2.00 / $10.00 | Native | High (vendor) |
| **DeepSeek** | `deepseek-flash` (DeepSeek-V4.1-Flash) | not published | $0.15 / $0.60 off-peak; $0.30 / $1.20 peak (cache miss) | Yes — `api.deepseek.com` | High (vendor) |
| **Together** | `Qwen3.8 Flash` | not published | $0.15 / $0.47 | Yes — `api.together.ai/v1` | High (vendor) |
| **Together** | `gpt-oss-120B` / `GLM-5.3-Flash` | not published | $0.15 / $0.60 and $0.15 / $0.50 | Yes | High (vendor) |
| **Fireworks** | `OpenAI GPT OSS 120B` | not published | $0.15 / $0.60 (Standard); $0.18 / $0.72 (Priority) | Yes (documented) | High (vendor) |
| **Fireworks** | `NVIDIA Nemotron 3.5 Lightning 30B A3B` | not published | **$0.05 / $0.20** | Yes | High (vendor) |
| **Mistral** | `Mistral Small 4` | not published | $0.15 / $0.60 | Not verified | Medium **[AGGREGATOR]** |
| **Mistral** | `Mistral Large 3` | not published | $0.50 / $1.50 | Not verified | Medium |
| **Mistral** | `Mistral Medium 3.5` | not published | $1.50 / $7.50 | Not verified | Medium |

Cerebras and Groq both price at the **$0.15 / $0.60** mark for `gpt-oss-120b` — Groq exactly, Cerebras at $0.35 / $0.75.

### 1.2 The TTFT honesty problem (read this before trusting any number)

**Neither Groq nor Cerebras publishes a headline time-to-first-token figure.** I checked both vendors' own material:

- Groq documents TTFT thoroughly — its console exposes TTFT per request, and its guidance says *"Input token count is the primary driver of TTFT"*, that 8B models show *"minimal TTFT variance across context lengths, optimal for latency-critical applications"*, that 70B+ models show *"exponential TTFT increases at maximum context"*, and that **reasoning models add 10–40% baseline latency**, with MoE routing adding a fixed cost. It gives **no absolute millisecond figure**.
- Cerebras publishes tokens/second only (~3000 and ~1850), not TTFT.

So the ranking below rests on **vendor throughput claims plus architecture reasoning**, not on a published TTFT measurement from either vendor. That is a real gap, and it is the single most important thing to close with your own measurement before committing.

**The aggregator evidence is stale and I do not recommend relying on it.** Eden AI's latency roundup (published 2026-08-10) puts Groq at "120ms TTFT, 800 tps" and Cerebras at "150ms / 2000 tps" — but its comparison table lists **GPT-4o, Llama 3.3 70B, Mistral Large and DeepSeek V4**, and Groq's own deprecation page shows Llama 3.3 70B was **shut down on 2026-08-16** and Llama 4 Maverick on 2026-03-09. A latency table whose model column is already retired is not a safe basis for a build decision. **[AGGREGATOR]**, low confidence.

I also could not extract Artificial Analysis's per-provider TTFT rows: the leaderboard renders 500+ endpoints through JS charts, and the fetched text contained the column headers ("Median First Chunk (s)") but not the row data for Groq or Cerebras.

### 1.3 Retired / deprecated model ids (verified)

**Groq** — deprecation page, shutdown dates already passed for free/developer tiers (enterprise committed-spend contracts exempt):

| Model | Shutdown | Replacement |
|---|---|---|
| `meta-llama/llama-4-maverick-17b-128e-instruct` | 2026-03-09 | `openai/gpt-oss-120b` |
| `moonshotai/kimi-k2-instruct-0905` | 2026-04-15 | `openai/gpt-oss-120b` |
| `qwen/qwen3-32b`, `meta-llama/llama-4-scout-17b-16e-instruct` | 2026-07-17 | `openai/gpt-oss-120b` |
| `llama-3.1-8b-instant`, `llama-3.3-70b-versatile` | 2026-08-16 | `openai/gpt-oss-20b` / `gpt-oss-120b` |
| `qwen/qwen3.6-27b` | 2026-09-14 | `qwen/qwen3.8-27b` |
| `groq/compound`, `groq/compound-mini` | 2026-09-21 | — |

Practical reading: **on Groq, the entire Llama and Kimi line is gone**, and Qwen 3.x moved to Qwen 3.8. `llama-3.1-8b-instant` and `llama-3.3-70b-versatile` still appear on Groq's models page but are marked **Enterprise / Contact Sales** — i.e. unavailable on self-serve.

**OpenAI** — deprecation page, and note the top entry is dated **today, 2026-10-14**:

| Shutdown | Model | Replacement |
|---|---|---|
| **2027-01-14** | `gpt-4o-2024-08-06`, `gpt-4o-2024-11-20` | `gpt-6-luna` |
| **2027-01-14** | `gpt-4o-mini-2024-07-18` | `gpt-6-luna` |
| **2027-01-14** | **`gpt-4.1-2025-04-14`** | `gpt-6-luna` |
| **2027-01-14** | **`gpt-4.1-mini-2025-04-14`** | `gpt-6-luna` |
| 2027-04-01 | `gpt-5.3-codex`, `gpt-5.4-nano`, `gpt-5.1` | `gpt-6-sol` / `gpt-6-luna` |

**→ `gpt-4.1-mini` is explicitly deprecated. Do not build on it.** OpenAI also warns that *"Preview models … may be retired with much shorter notice, such as 2 weeks"* and that it does not recommend them for business-critical production.

**Mistral** — retirements relevant to a voice build: `mistral-medium-2508` (2026-08-31), `mistral-small-2506` (2026-07-31), `ministral-3b-2410` / `ministral-8b-2410` (2025-12-31), `mistral-large-2411` (2026-05-31), plus the whole Magistral 1.x line. Current replacements are Mistral Medium 3.5 / Small 4 / Ministral 3.

### 1.4 Which model matters for a friendly A1–C1 coach that occasionally corrects

The four candidate classes:

1. **`gpt-oss-120b` (Cerebras or Groq)** — the only model that is simultaneously cheap ($0.15–$0.35 / $0.60–$0.75), hosted on genuinely exotic fast silicon, and strong enough for natural conversational English with light correction. It is also OpenAI's own open-weight release, so its instruction-following and JSON/structured-output behaviour is familiar. **This is the default recommendation.**
2. **`gpt-oss-20b` on Groq (1000 tok/s)** — if TTFT dominates everything and you accept a smaller model. Best pure-latency bet on published vendor numbers.
3. **`gemini-3.5-flash-lite` / `gemini-3.1-flash-lite`** — the cost/quality alternative, with a genuine free tier and audio input priced in. Pick this if correction quality matters more than the last 100ms.
4. **`gpt-6-luna`** — OpenAI's cheap tier at $0.10 / $0.50, and note that OpenAI now sells **paid latency tiers**: `gpt-6-luna` Fast mode is 2× price for faster serving, and `gpt-6.1-sol` Ultrafast is 6× ($12 / $60). If you want to *buy* latency rather than hunt for it, this is the only provider on the list that prices it explicitly. Treat as a fallback: OpenAI is the slowest of the four on reputation, and I found no published OpenAI TTFT figure.

**Best measurable choice for a latency-critical voice pipeline:** `gpt-oss-120b` on **Cerebras** if you want maximum output throughput (~3000 tok/s claimed) and on **Groq** if you want the lowest-latency-served variant with the more mature OpenAI-compatible surface (1000 tok/s on the 20B, 500 tok/s on the 120B). **Confidence: medium** — this is the correct choice on vendor-published throughput and price, but no vendor publishes the TTFT number that actually governs perceived responsiveness, so it must be confirmed by an A/B measurement from your own region with your own prompt length. For a short conversational turn (a few hundred input tokens, 30–60 output tokens) the practical TTFT gap between Groq and Cerebras is likely small; the throughput gap matters more for long replies, which a voice coach rarely produces.

**Disable reasoning/thinking mode for this workload.** Groq documents a 10–40% latency penalty for chain-of-thought, and DeepSeek's V4.1-Flash defaults to *thinking mode on*. For a spoken coach, thinking tokens are pure added silence.

---

## 2. SPEECH-TO-TEXT (streaming)

### 2.1 Comparison

| Provider / model | Streaming price | Time-to-final after end of speech | Word confidence + timestamps in streaming | Multilingual in one model | Non-native accuracy | Confidence |
|---|---|---|---|---|---|---|
| **Deepgram Nova-3** (`nova-3`) | **$0.0048/min** mono (**$0.0058** multilingual). Regular: $0.0077 / $0.0092 | "sub-300 ms" (vendor) | Yes — calibrated per-word `confidence` in the `words` array, plus timestamps | Yes — 10 languages code-switching in real time | AA-WER 5.2% (mid-pack) | High |
| **Deepgram Flux** (`flux-general-en`, `flux-general-multi`) | **$0.0065/min** English, **$0.0078** multilingual | Model-native end-of-turn; vendor claims **200–600 ms faster agent response** than STT+VAD | Yes, plus `EndOfTurn` / `EagerEndOfTurn` events | Yes — same 10 languages | "Nova-3 level accuracy" (vendor) | High |
| **Deepgram Nova-2** (`nova-2`) | **Not published** — absent from the current price table | Comparable to Nova-3 (vendor) | Yes | Spanish+English code-switch | AA-WER 5.1% (via Telnyx) | Medium |
| **AssemblyAI Universal-3.6 Pro Realtime** | **$0.45/hr** = $0.0075/min | Not published as ms | Yes (context carryover, keyterm prompting) | 32 languages | AA-WER 3.1% (Universal-3 Pro) | High |
| **AssemblyAI Universal-Streaming** | **$0.15/hr** = **$0.0025/min** | not published | Yes | English only / Multilingual variant covers 6 languages | AA-WER 3.8% | High |
| **OpenAI `gpt-live-transcribe`** | **$0.017/min** | not published | not verified | not verified | — | High (price) |
| **OpenAI `gpt-transcribe`** | **$0.0045/min** | batch-ish | not verified | not verified | AA-WER 3.3% | High (price) |
| **Google `gemini-3.5-transcribe-live`** | **≈$0.009/min** blended, WebSockets | not published | word-level timestamps + diarization + auto language detection (on the batch sibling) | Yes | AA-WER 2.6% (Gemini 3.5 Transcribe) | High |
| **Google Chirp 3 (Cloud STT)** | **$0.016/min** with data logging, $0.024 without | not published | yes | yes | AA-WER 4.3% | High |
| **Speechmatics Melia 1** | $4.00 / 1000 min = **$0.004/min** **[AGGREGATOR: Artificial Analysis]** | not published | yes | **56 languages, switches mid-sentence** | AA-WER 4.9% | Medium |

### 2.2 The three questions you actually asked

**Is Nova-2 scheduled for deprecation? No — and I can state this firmly.** Deepgram's current *Models & Languages Overview* lists `nova-2` as a live, recommended model: *"Recommended for use cases with languages not yet supported by nova-3, and filler word identification."* The page's **"Legacy Models"** section covers **Nova 1, Enhanced and Base** — not Nova-2. **However:** Nova-2 has **disappeared from Deepgram's public pricing table**, which now lists only Flux, Nova-3, Whisper Large and Custom. So Nova-2 is not deprecated but is no longer publicly priced — it is likely grandfathered or enterprise-negotiated. **Confidence: high on "not deprecated"; medium on pricing (I could not find a current published Nova-2 rate; the pricing FAQ question "Are older models (Nova-2, Enhanced, Base) still available?" has an answer that the rendered page did not expose).**

**Does Nova-3 give word-level confidence + timestamps in the streaming response? Yes.** Every word carries a `confidence` float in `[0,1]`, which Deepgram documents as a **calibrated probability** — *"if you took all words the model scored at 0.93, approximately 93% of them would actually be correct."* Deepgram's own guidance is that **a threshold around 0.65 works well as an error detector** with high precision, and it explicitly warns that confidence scales are **not comparable across providers**. Timestamps are per-word (`start`/`end`). This is directly usable for your product: low-confidence words are a strong signal for "the learner probably mispronounced this" — a cheap pronunciation nudge that needs no separate assessment API.

**Multilingual in a single model?** Yes for Nova-3: one unified model does real-time code-switching across **English, Spanish, French, German, Hindi, Russian, Portuguese, Japanese, Italian, Dutch**. Deepgram's engineering note is worth quoting because it explains the latency benefit: cascaded per-language models *"create computational overhead and can introduce latency"*, whereas Nova-3 *"operat[es] as a truly unified multilingual recognition system"* without explicit routing. For your English-first-then-multilingual roadmap, that is the right architecture.

### 2.3 Independent accuracy data (Artificial Analysis)

**These are non-streaming AA-WER v2 figures** — AA-WER combines AA-AgentTalk (50%), VoxPopuli-Cleaned (25%) and Earnings22-Cleaned (25%), datasets chosen to include *"diverse accents, domain-specific language, and challenging channel & acoustic conditions."* Lower is better.

| Model | AA-WER | Speed factor | $/1000 min |
|---|---|---|---|
| Microsoft MAI-Transcribe-2 | **2.0%** | 317.0 | $1.67 |
| ElevenLabs Scribe v2 | 2.2% | 69.0 | $3.67 |
| Grok Voice Transcribe 2.0 | 2.3% | 178.4 | $1.67 |
| Smallest AI Pulse Pro | 2.4% | 285.1 | $4.00 |
| Gemini 3.5 Transcribe | 2.6% | 91.1 | $5.00 |
| **AssemblyAI Universal-3 Pro** | **3.1%** | 108.6 | $3.50 |
| AssemblyAI Universal | 3.8% | 123.4 | $2.50 |
| OpenAI GPT Transcribe | 3.3% | 56.5 | $4.50 |
| OpenAI GPT-4o Transcribe | 4.0% | 36.6 | $6.00 |
| Speechmatics Enhanced | 4.0% | 80.0 | $12.50 |
| Google Chirp 3 | 4.3% | 30.1 | $16.00 |
| Speechmatics Melia | 4.9% | 177.7 | $4.00 |
| **Deepgram Nova-3** | **5.2%** | **616.9** | $4.30 |
| Deepgram Nova-2 (via Telnyx) | 5.1% | 465.1 | $7.40 |

**The honest read: Deepgram Nova-3 is the middle of the accuracy pack, not the leader.** Its published 616.9 speed factor is by far the fastest measured, and its real-time code-switching and calibrated word confidence are genuinely differentiated — but on raw word error rate it is beaten by AssemblyAI, Microsoft, ElevenLabs, Grok, and Google's Gemini Transcribe. Deepgram's own marketing claims a 54.2% streaming WER reduction vs competitors and a median 6.84% streaming WER; those are vendor self-benchmarks and should be discounted accordingly. **Confidence: high** (AA publishes its methodology; the numbers above are from AA, an aggregator, but a benchmark one rather than a price-scraping one).

**⚠️ Naming trap:** AA lists a model called **"Nova 2 Pro"** — that is **Amazon's Nova 2 Pro on Bedrock, not Deepgram Nova-2**. They are unrelated. Deepgram Nova-2 only appears in AA via the Telnyx reseller.

### 2.4 OpenAI's transcription line is mid-migration — do not start on `gpt-4o-transcribe`

Deprecated **2026-08-26**, shutdown **2027-02-26**: `whisper-1`, `gpt-4o-transcribe`, `gpt-4o-mini-transcribe`, `gpt-4o-transcribe-diarize`. Replacements are `gpt-live-transcribe` (streaming, $0.017/min) or `gpt-transcribe` ($0.0045/min). Note the **replacement is ~3× the price** of the model it replaces (`gpt-4o-transcribe` was $0.006/min). The legacy `gpt-realtime` / `gpt-4o-audio` / `gpt-4o-realtime` family is also deprecated, shutdown **2027-01-20**.

**Recommendation for STT:** start on **Deepgram Flux** (`flux-general-en`, $0.0065/min) rather than Nova-3, precisely because it collapses VAD + endpointing into the model and Deepgram claims 200–600 ms of agent-response improvement — that is the single largest latency lever in a turn-based voice coach, worth far more than a 100 ms LLM TTFT difference. keep **Nova-3 multilingual** ($0.0058/min) as the migration target for phase 2, and benchmark **AssemblyAI Universal-3.6 Pro Realtime** ($0.0075/min) as the accuracy challenger if Flux's turn detection disappoints. **Confidence: medium-high.**

---

## 3. TEXT-TO-SPEECH (streaming)

### 3.1 What exists now — Cartesia's line-up specifically

Cartesia's docs are unambiguous: the current model is **`sonic-3.6`**, released as snapshot **`sonic-3.6-2026-08-27`**, with **native support for 44 languages**. The docs state: *"For information on `sonic-3`, `sonic-2`, `sonic-turbo`, and `sonic`, see our page on Older TTS Models."*

**So: `sonic-2` and `sonic-turbo` both exist but are retired-generation. `sonic-3` and `sonic-3.5` are the immediately prior generations, and `sonic-3.6` supersedes them (fully backwards compatible with 3.5).** Artificial Analysis's provider list confirms Sonic 3, Sonic 3.5 and Sonic 3.6 as separately tracked endpoints. Cartesia also offers `sonic-preview` (beta, unstable, explicitly "not intended for production").

Cartesia's stated language count includes Odia and Urdu as the newest additions, plus Hinglish support for Romanised Hindi. **One voice speaks all 44 languages** — this is a single multilingual model, not per-language voices, which is exactly what your roadmap wants.

### 3.2 Comparison

| Provider / model id | TTFB | Price | Per-minute equivalent¹ | One voice, many languages? | Confidence |
|---|---|---|---|---|---|
| **Cartesia `sonic-3.6`** | not published on the public pages I could retrieve | 1 credit = 1 char; **$38 / $45 / $65 per 1M credits** by tier (Scale/Startup/Pro overage); Scale = $299/mo for 8M credits | **≈$0.030/min** | **Yes — 44 languages, one voice** | High on price mechanics; medium on derivation |
| **Deepgram Aura-2** | **sub-200 ms** (vendor) | **$0.030 / 1k chars** PAYG ($0.027 Growth) = **$30 / 1M chars** | **≈$0.024/min** | **No** — voices are per-language (`aura-2-thalia-en`); English, Spanish, Dutch, French, German, Italian, Japanese; 40+ voices | High |
| **Deepgram Flux TTS** | not published | $0.0450 / 1k chars | ≈$0.036/min | not verified | High (price) |
| **ElevenLabs `eleven_flash_v2_5`** | **~75 ms** (vendor, excluding app+network) | ≈**$165 / 1M chars** at Pro/Scale; Flash bills 0.5–1 credit/char → **$82–165** | **≈$0.066–0.132/min** | Yes — 32 languages (multilingual v2 set + hu, no, vi) | High on latency; medium on price (derived from credit plans) |
| **ElevenLabs `eleven_v4_turbo`** | **~100 ms** (vendor) | not separately published | — | Yes — **90+ languages** | High |
| **ElevenLabs `eleven_v3_conversational`** | **~280 ms** (vendor) | not separately published | — | Yes — 70+ languages | High |
| **OpenAI `tts-1` / `tts-1-hd` / `gpt-4o-mini-tts`** | — | **Deprecated** — removed 2027-01-06 | — | — | High |
| **Google `gemini-3.8-flash-lite-tts`** | not published | $6.00 / 1M audio tokens = **$0.0015 per 10 s audio** | **≈$0.009/min** | not verified | High (vendor) |
| **Google `gemini-3.8-flash-tts`** | not published | $9.00 / 1M audio tokens = $0.00225 per 10 s | ≈$0.0135/min | not verified | High (vendor) |
| **Amazon Polly Neural** | — | $16 / 1M chars (Generative $30, Standard $4) | ≈$0.013/min (Neural) | yes, per-language voices | High |

¹ Derived assuming ~800 characters per minute of speech, using Deepgram's own conversion that **1 minute of Aura audio ≈ 800 characters**. Treat per-minute figures as arithmetic, not vendor quotes.

### 3.3 The findings that matter

1. **OpenAI's classic TTS is end-of-life.** `tts-1`, `tts-1-hd`, `gpt-4o-mini-tts-2025-03-20` and `gpt-4o-mini-tts-2025-12-15` were deprecated **2026-10-01** and are removed **2027-01-06**, with the recommended replacement being `gpt-realtime-2.1-mini`. They no longer appear anywhere in OpenAI's current pricing table. **Do not design around `gpt-4o-mini-tts`.** **Confidence: high.**

2. **ElevenLabs is roughly 2–5× the per-character cost of Cartesia and Deepgram, but it is the only vendor publishing a ~75 ms TTFB figure and offering 90+ languages on one voice.** Its credit mechanics: Free $0 (10k credits), Starter $6 (30k), Creator $22 (121k), Pro $99 (600k), Scale $299 (1.8M), Business $990 (6M). One text character = one credit for V2 multilingual; Flash/Turbo bill 0.5–1 credit per character, so Flash is the cheap path. ElevenLabs' own pricing page advertises *"Low-latency TTS as low as 5c/minute."* **Confidence: high on plan structure, medium on my derived $/1M.**

3. **Deepgram Aura-2 is the cheapest published per-character rate — but it is not a multilingual single-voice model.** The model id format is `[modelname]-[voicename]-[language]` (e.g. `aura-2-thalia-en`), meaning voices are bound to a language. Aura-2 covers English, Spanish, Dutch, French, German, Italian and Japanese. For an English-first product that is fine today; for "multi-language soon" it means switching voices per language rather than reusing one. **Confidence: high.**

4. **The turbo line at ElevenLabs is superseded.** `eleven_turbo_v2_5` and `eleven_turbo_v2` are documented as *"First generation low-latency model (outclassed by Flash models)"* with replacement suggestions `eleven_flash_v2_5` / `eleven_flash_v2`. **Confidence: high.**

**Recommendation for TTS:** **Cartesia `sonic-3.6`** is the best fit — one voice across 44 languages, ~$0.030/min, a first-party designed-for-streaming API, and dated snapshots (`sonic-3.6-2026-08-27`) so you can pin behaviour in production. Keep **ElevenLabs `eleven_flash_v2_5`** as the quality/premium voice option (75 ms TTFB, 32 languages) if learners respond better to it, and note that **Google's Gemini 3.8 Flash-Lite TTS is ~3× cheaper again** (~$0.009/min) if cost dominates and you can accept an unverified multilingual story. **Confidence: medium-high.**

---

## 4. PRONUNCIATION ASSESSMENT APIs

### 4.1 Comparison

| API | What it scores | Languages | Pricing | Integration | Laravel-friendly? | Confidence |
|---|---|---|---|---|---|---|
| **Azure AI Speech — Pronunciation Assessment** | Accuracy, Fluency, Completeness, **Prosody**, Miscue, `ErrorType` (Omission / Insertion / Mispronunciation / UnexpectedBreak / MissingBreak / **Monotone**); **phoneme-level** IPA | Many locales; **prosody + content only `en-US`**; syllable level `en-US` only | Baseline = STT Standard **real-time $1/hr**; **Prosody is an "Enhanced add-on" at $0.30/hr per feature**; batch STT $0.18/hr; commitment tiers to $0.50/hr | **Speech SDK** (JS/Node, Python, C#, Java, Swift, ObjC); **streaming + continuous mode**; **not available for Go** | Partial — official path is the SDK, not a clean HTTP endpoint | High |
| **Speechace** | Word + sentence pronunciation, **lexical stress**, phoneme sequences, Fluency score, IELTS/CEFR/PTE/TOEIC rubrics, vocabulary/grammar/coherence (Premium) | English (US, UK), French (FR, CA), Spanish (ES, MX) | **$0.008 per 15-sec audio** (Basic & Pro); **$0.0125** (Premium); plans bundle 5,000–10,000 requests | **HTTP REST**, synchronous | **Yes — the cleanest fit** | High |
| **SpeechSuper** | Sentence / word / **phoneme** scores, mispronunciation, syllable stress, linking; plus fluency, grammar, vocabulary, topic on open speech | **8 languages** | **Not published** (contact sales) | REST API + SDK (SDK English & Mandarin only) | Yes via REST, but pricing unknown | Medium |
| **ELSA** | — | — | — | — | **Not verified** — I found no public developer API/pricing page | Low |
| **Google** | **None.** Google Cloud Speech-to-Text pricing exposes only speech-recognition SKUs ($0.016/min with data logging, $0.024 without; dynamic batch $0.003/min). **No pronunciation-assessment SKU exists.** | — | — | — | n/a | High (absence verified) |
| **Amazon** | **No pronunciation scoring.** Polly is synthesis only (Standard $4 / Neural $16 / Generative $30 per 1M chars) and has no assessment API. Transcribe has no PA; **Transcribe Call Analytics** does expose *"non-talk time, interruptions, loudness, and talk speed"* — prosody-adjacent, but **not phoneme accuracy** | — | Transcribe streaming $0.01/min, batch $0.006/min | REST/WebSocket | Only as a weak fluency proxy | High |

### 4.2 What this means for a Laravel backend

**Realistic, in order:**

1. **Speechace** — the best fit for a PHP backend calling an HTTP API. Published per-request pricing (**$0.008 per 15-second clip**), synchronous REST, word/phoneme/lexical-stress detail, and a free trial. A recorded browser clip → POST → JSON scores. No SDK, no streaming session, no native binding. **Confidence: high.**
2. **SpeechSuper** — architecturally fine (REST, scripted/semi-scripted/unscripted modes, phoneme + syllable stress), but **pricing is not published**, which makes it unplannable without a sales conversation. Get a quote before designing around it. **Confidence: medium.**
3. **Azure AI Speech Pronunciation Assessment** — the most capable API on this list (it is the only one that returns **prosody** and a **Monotone** error tag, and the only one with unlimited-length streaming assessment). But the documented integration is the **Speech SDK**, and the docs are explicit that **Go is unsupported** and that some result detail is *"only available in the JSON string"* even in supported SDKs. From Laravel you would either (a) call the **Azure Speech REST API** and pass pronunciation-assessment config, accepting that this is the less-documented path, or (b) run a small sidecar service in a supported language. Also note the cost shape: **$1/audio-hour baseline + $0.30/audio-hour for prosody**, i.e. **$0.0217 per audio-minute all-in** — roughly 2.7× Speechace's per-clip rate for a 15-second sample. **Confidence: high on capability and price, medium on REST-only integration viability.**
4. **Google and Amazon: nothing to integrate.** Neither offers pronunciation assessment. If a stakeholder asks for "the Google option", the correct answer is that it does not exist and you would be building it yourself from ASR confidence + forced alignment. **Confidence: high.**

**Important design note:** Azure supports **scripted** assessment (you supply `ReferenceText`, and you get Completeness and Miscue/Insertion/Omission) and **unscripted** assessment (no reference text, adds a content score). For a conversational coach, unscripted is what you want — but Microsoft itself recommends, for unscripted mode, *"first call Azure STT to obtain the reference text, and then perform scripted assessment"* if you need accurate text-based scoring. That two-pass pattern is a useful escape hatch.

---

## 5. ARE MULTIMODAL LLMs GOOD ENOUGH TO SCORE PRONUNCIATION?

**Short answer: not for a learner-visible grade. Marginally, and only as a verbaliser, for a low-stakes coaching nudge.**

### 5.1 The evidence

**Study A — zero-shot speech-LLM rubric scoring.** Parikh et al., *Zero-Shot Speech LLMs for Multi-Aspect Evaluation of L2 Speech* (SLaTE 2025 / arXiv:2601.16230). Qwen2-Audio-7B-Instruct, zero-shot, on all **5,000 Speechocean762** utterances (250 Mandarin-speaking learners), scoring accuracy, fluency, prosody and completeness on 0–10 against five expert raters.

| Metric | Accuracy | Fluency | Prosody | Completeness |
|---|---|---|---|---|
| Exact match | 23.3% | 26.1% | 25.4% | **0.7%** |
| Within ±1 | 68.1% | 69.0% | 67.3% | 37.8% |
| Within ±2 | 87.4% | 89.5% | 87.5% | 55.9% |
| **Raw Pearson r** | **0.140** | **0.053** | **0.206** | **−0.021** |

The headline "87% agreement" is a trap, and the authors say so themselves: *"a ±2 margin on a 10-point scale spans 40% of the total range, reducing scoring granularity."* The raw correlations are **r = 0.14 / 0.05 / 0.21 / −0.02** — essentially no linear relationship with human judgement.

The killer finding: **among the 863 utterances where human raters scored accuracy ≤ 6, the model did not assign a single score in that range.** Not one. For fluency and prosody it failed to assign low scores in **95.7%** and **89.3%** of low-scoring cases. The model never assigned a prosody score below 5. That is a systematic ceiling bias: **the model cannot tell a struggling learner that they are struggling.** For a language coach, that is not a rounding error — it is a product failure.

**Study B — is the reasoning even grounded in the audio?** Sun et al., *Prior over Evidence: Stereotype-Driven Diagnosis in LLM-Based L2 Pronunciation Feedback* (arXiv:2606.15325, June 2026). 1,800 L2-Arctic utterances × six L1 backgrounds × **three audio-capable LLMs** × four pronunciation dimensions × five evidence conditions.

- **39.6%** of judged cells contained *internally coherent* reasoning that supported a **wrong** rating, against only **15.8%** where the reasoning supported a correct one. The chain of thought reads plausibly and is frequently wrong.
- **Phoneme-level feedback converges to a fixed inventory of "L2-English difficulty phones" that recurs across all six L1 backgrounds and all evidence conditions.** That is the stereotype result: the model diagnoses the learner's first language, not the learner's actual audio.
- Acoustic evidence helps **only** when the supplied feature directly probes the target dimension — textualised F0 range lifted pitch-variation grounding from 0.18–0.19 to 0.45–0.62, **but the same audio waveform without the textualised F0 values did not reproduce the improvement.** Stress and phoneme correctness, which need target-to-realisation alignment, stayed ungrounded.
- The paper's conclusion is the sentence to quote to stakeholders: **"current general-purpose LLMs are more reliable as verbalisers of externally computed pronunciation evidence than as standalone diagnostic engines."**

**Study C — audio LLMs vs. trained acoustic models.** *TextPA: Read to Hear — A Zero-Shot Pronunciation Assessment Using Textual Descriptions and LLMs* (EMNLP 2025 main.134), Pearson correlation on MultiPA and Speechocean762:

| System | Accuracy r | Fluency r |
|---|---|---|
| GPT-4o-mini-**audio** | 0.674 | 0.648 |
| TextPA (text-only, gpt-4o-mini) | 0.728 | 0.650 |
| Trained acoustic model (MultiPA) | 0.618 | 0.683 |
| Trained acoustic **in-domain** | **0.72** | **0.795** |
| Gemini-2.0-flash-**audio** (Speechocean) | 0.562 | 0.556 |

Audio LLMs land **below purpose-trained models on the same task** (0.562 vs 0.72 accuracy; 0.556 vs 0.795 fluency). The paper also reports the LLM *"struggles with assessing prosody"* even when handed explicit ToBI prosodic annotations, and notes that **"results are from a single run."**

### 5.2 The reproducibility problem — stated precisely

There are four distinct failure modes, and they compound:

1. **Single-run evaluation.** The TextPA authors state outright that results come from a single run with default API settings. There is no published test–retest reliability, no ICC, no confidence interval on scores. Without that, you cannot know whether a learner's score changed because their pronunciation changed or because the model sampled differently.
2. **No calibration and a documented central bias.** Scores cluster at 7–9 and avoid the low end entirely. Two learners at genuinely different levels can receive the same band.
3. **Silent score drift across model versions.** Vendors ship dated snapshots and retire them on schedules you do not control: OpenAI is retiring `gpt-4o`/`gpt-4.1` on 2027-01-14 and its audio/realtime legacy family on 2027-01-20; Cartesia's own docs advise pinning a dated snapshot *"if ensuring consistent behavior is important."* If your pronunciation score is computed by a hosted LLM, **a vendor model update changes your product's scores without any change on your side.** That is fatal for a feature a learner can watch trend over weeks.
4. **Prompt sensitivity.** The zero-shot studies had to hand-write rubric definitions and use regex parsing to extract JSON; the models required *"enhanced prompting, calibration"* before they were usable at all. Small prompt edits move scores.

### 5.3 Verdict, split by stakes

- **(a) Low-stakes friendly nudge → acceptable, with an architecture change.** Do **not** ask the LLM to listen and judge. Compute the evidence yourself — Deepgram Nova-3's **calibrated per-word confidence** (threshold ~0.65, per Deepgram's own guidance), or Speechace/Azure phoneme scores — and use the LLM **only to phrase that evidence warmly and specifically**. This is precisely what Study B recommends, and it is where LLMs are genuinely strong: `{"word": "thought", "phoneme_issue": "/θ/ → /s/"}` → *"Nice — one tiny thing: your 'th' in 'thought' came out a bit like 's'. Try it again?"* **Confidence: high.**
- **(b) Learner-visible pronunciation grade → no.** The evidence does not support it: raw correlations of 0.05–0.21, a total inability to score weak speakers as weak, stereotype-driven phoneme diagnoses, and no reported reproducibility. Use **Azure Pronunciation Assessment** (the only option with prosody + Monotone detection) or **Speechace** (the cheapest clean HTTP option). **Confidence: high.**

One further vendor caveat worth noting: OpenAI itself flags that preview audio models *"may be retired with much shorter notice, such as 2 weeks"* and states it *"do[es]n't recommend using preview models for business-critical production workloads unless you can migrate on short notice."*

---

## Recommendation for a latency-critical, cost-aware English-first voice coach

**Dialogue LLM — `gpt-oss-120b` on Cerebras (primary) with Groq as the failover.** It is the only option that pairs frontier-adjacent conversational quality with vendor-claimed ~3000 tok/s (Cerebras) or 500 tok/s (Groq) at $0.15–$0.35 in / $0.60–$0.75 out, and both expose an OpenAI-compatible endpoint so you can fail over without touching application code. **Disable reasoning/thinking mode** — Groq documents a 10–40% latency tax for chain-of-thought, and a spoken coach has no use for it. **Benchmark TTFT yourself before committing:** neither vendor publishes the number, and the aggregator figures circulating online reference models that were retired months ago. Keep `gemini-3.5-flash-lite` ($0.30/$2.50, free tier) as the cost/quality alternative if correction naturalness proves weak, and treat OpenAI's paid Fast/Ultrafast tiers as the "buy latency" escape hatch rather than the default.

**Speech-to-text — Deepgram Flux for turn-taking, Nova-3 Multilingual for phase 2.** Flux (`flux-general-en`, $0.0065/min) folds VAD and end-of-turn detection into the model and Deepgram claims 200–600 ms faster agent response than STT+VAD — the largest single latency lever in a turn-based coach, and worth more than any LLM TTFT gain. Its `EagerEndOfTurn` event lets you start drafting the reply before the learner has fully stopped. When you add languages, move to **Nova-3 Multilingual** ($0.0058/min), a single model that code-switches live across ten languages. Independently of which you pick, **use Nova-3's calibrated per-word confidence as your free mispronunciation signal** — Deepgram documents a ~0.65 threshold as a high-precision error detector, which gives you targeted correction prompts at zero extra cost. Budget an A/B against AssemblyAI Universal-3.6 Pro Realtime ($0.0075/min, AA-WER 3.1% vs Nova-3's 5.2%) if accent robustness underperforms.

**Text-to-speech — Cartesia `sonic-3.6`, pinned to the dated snapshot.** It speaks 44 languages from a single voice (so "multi-language soon" is a config change, not a re-architecture), costs roughly $0.030 per minute of audio at Scale, and ships a designed-for-streaming API with dated snapshots (`sonic-3.6-2026-08-27`) you can pin so the voice never shifts under your learners. **Do not build on OpenAI's TTS** — `tts-1`, `tts-1-hd` and `gpt-4o-mini-tts` are all deprecated and disappear 2027-01-06. If voice quality turns out to be the differentiator that retains learners, **ElevenLabs `eleven_flash_v2_5`** is the premium upgrade at ~75 ms TTFB and 32 languages, at roughly 2–5× the per-character cost; taste-test it before assuming you need it.

**Pronunciation assessment — Speechace for v1, Azure if you need prosody.** Speechace is the cleanest Laravel integration on the market: synchronous HTTP, **$0.008 per 15-second clip**, and it returns word, phoneme and lexical-stress detail — enough to drive a real correction. Step up to **Azure AI Speech Pronunciation Assessment** only when you need **prosody** and **Monotone** detection, which nothing else offers, and accept that it costs ~$0.0217 per audio-minute (STT $1/hr + prosody add-on $0.30/hr) and that its documented path is the Speech SDK rather than a plain REST call — plan a small sidecar service. **Google and Amazon have no equivalent product**, so do not budget time looking for one.

**Do not use a multimodal LLM to grade pronunciation.** The published evidence is consistent and unflattering: raw human correlation of 0.05–0.21 for a zero-shot speech LLM, **zero** low scores assigned across 863 utterances that human raters marked as poor, 39.6% of judgements producing coherent-sounding reasoning for a wrong rating, and phoneme feedback that collapses to a fixed list of "L2 difficulty phones" regardless of the actual audio. Use it where it is genuinely good — turning *evidence you computed* (word confidence, phoneme scores) into warm, specific, human feedback — and use a dedicated assessment API whenever a learner sees a number.

---

## Source URLs

**LLM providers — pricing and model line-ups**
- Cerebras Inference pricing — https://www.cerebras.ai/pricing
- Cerebras Model Catalog — https://inference-docs.cerebras.ai/models/overview
- Cerebras quickstart (OpenAI compatibility) — https://inference-docs.cerebras.ai/quickstart
- Groq supported models — https://console.groq.com/docs/models
- Groq model deprecation history — https://console.groq.com/docs/deprecations
- Groq "Understanding and Optimizing Latency" — https://console.groq.com/docs/production-readiness/optimizing-latency
- OpenAI API pricing — https://platform.openai.com/docs/pricing
- OpenAI deprecations — https://platform.openai.com/docs/deprecations
- OpenAI models list — https://platform.openai.com/docs/models
- Google Gemini API pricing — https://ai.google.dev/gemini-api/docs/pricing
- Google Gemini OpenAI compatibility — https://ai.google.dev/gemini-api/docs/openai
- DeepSeek models & pricing — https://api-docs.deepseek.com/quick_start/pricing
- Together AI pricing — https://www.together.ai/pricing
- Together AI OpenAI compatibility — https://docs.together.ai/docs/openai-api-compatibility
- Fireworks serverless pricing — https://docs.fireworks.ai/serverless/pricing
- Mistral pricing (consumer page; API rates summarised in body text) — https://mistral.ai/pricing
- Mistral models overview + retirement table — https://docs.mistral.ai/getting-started/models/models_overview/
- **[AGGREGATOR]** Mistral API pricing — https://benchlm.ai/mistral/api-pricing
- **[AGGREGATOR]** LLM API latency benchmarks — https://www.edenai.co/post/llm-api-latency-benchmarks-speed-comparison-across-providers

**Speech-to-text**
- Deepgram pricing — https://deepgram.com/pricing
- Deepgram models & languages overview (Nova-2 status, Legacy Models) — https://developers.deepgram.com/docs/models-languages-overview
- Deepgram word confidence scores — https://developers.deepgram.com/docs/confidence
- Deepgram measuring STT latency (Nova-3 sub-300 ms; Flux 200–600 ms) — https://developers.deepgram.com/docs/measuring-streaming-latency
- Deepgram streaming feature overview — https://developers.deepgram.com/docs/stt-streaming-feature-overview
- Deepgram Nova-3 announcement (WER, multilingual, keyterm prompting) — https://deepgram.com/learn/introducing-nova-3-speech-to-text-api
- AssemblyAI pricing — https://www.assemblyai.com/pricing
- Google Cloud Speech-to-Text pricing — https://cloud.google.com/speech-to-text/pricing
- Speechmatics pricing — https://www.speechmatics.com/pricing
- **[BENCHMARK/AGGREGATOR]** Artificial Analysis Speech-to-Text leaderboard — https://artificialanalysis.ai/speech-to-text
- **[BENCHMARK/AGGREGATOR]** Artificial Analysis streaming STT (methodology) — https://artificialanalysis.ai/speech-to-text/streaming

**Text-to-speech**
- Cartesia pricing (credits, overage rates, per-minute conversion) — https://cartesia.ai/pricing
- Cartesia TTS models — Sonic 3.6, 44 languages, older-models pointer — https://docs.cartesia.ai/build-with-cartesia/tts-models
- Deepgram Text-to-Speech pricing (Aura-1/Aura-2/Flux TTS) — https://deepgram.com/pricing
- Deepgram Aura voices & languages — https://developers.deepgram.com/docs/tts-models
- Deepgram Aura-2 launch (sub-200 ms TTFB) — https://deepgram.com/learn/introducing-aura-2-enterprise-text-to-speech
- ElevenLabs pricing — https://elevenlabs.io/pricing
- ElevenLabs models (Flash v2.5 ~75 ms, v4 Turbo ~100 ms, v3 Conversational ~280 ms) — https://elevenlabs.io/docs/models
- Amazon Polly pricing — https://aws.amazon.com/polly/pricing/
- **[BENCHMARK/AGGREGATOR]** Artificial Analysis TTS providers — https://artificialanalysis.ai/text-to-speech/providers

**Pronunciation assessment**
- Azure AI Speech pronunciation assessment (how-to) — https://learn.microsoft.com/en-us/azure/ai-services/speech-service/how-to-pronunciation-assessment
- Azure pronunciation assessment tool + pricing model — https://learn.microsoft.com/en-us/azure/ai-services/speech-service/pronunciation-assessment-tool
- Azure Speech Services pricing ($1/hr real-time, $0.30/hr prosody add-on) — https://azure.microsoft.com/en-us/pricing/details/cognitive-services/speech-services/
- Speechace API plans and per-request rates — https://www.speechace.com/api-plans/
- SpeechSuper — https://www.speechsuper.com/
- Amazon Transcribe pricing (Call Analytics characteristics) — https://aws.amazon.com/transcribe/pricing/

**Multimodal LLM pronunciation scoring — evidence**
- Parikh, Tejedor-Garcia, Cucchiarini, Strik — *Zero-Shot Speech LLMs for Multi-Aspect Evaluation of L2 Speech* — https://arxiv.org/html/2601.16230
- Same paper, ISCA SLaTE 2025 archive — https://www.isca-archive.org/slate_2025/parikh25_slate.pdf
- Sun et al. — *Prior over Evidence: Stereotype-Driven Diagnosis in LLM-Based L2 Pronunciation Feedback* — https://arxiv.org/abs/2606.15325
- *TextPA: Read to Hear — A Zero-Shot Pronunciation Assessment Using Textual Descriptions and LLMs* (EMNLP 2025) — https://aclanthology.org/2025.emnlp-main.134.pdf

**Tooling note**
- Jina Reader (used to render JS-heavy vendor pages) — https://r.jina.ai
