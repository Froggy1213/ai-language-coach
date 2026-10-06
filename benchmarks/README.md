# Dialogue LLM First-Token Latency Benchmark

Harness for measuring real-world first-token latency (TTFT) and conversational turnaround across candidate LLMs for the real-time voice agent (`agent/agent.py`).

## Context & Plan Requirements

According to **`ai-language-coach-plan.md`**:
- **§5 (Voice Pipeline Contract)**:
  > *"Диалоговая LLM: обязательный бенчмарк first-token latency до интеграции. DeepSeek-V3 подтверждён только для async-разбора и CEFR-оценки — там цена важнее скорости."*
- **§6 (Roadmap — November Week 1)**:
  > *"Бенчмарк first-token latency диалоговой LLM — Модель для реплик выбрана по данным."*
- **§7 (Pre-AWS Checklist)**:
  > *"Диалоговая LLM выбрана по бенчмарку; DeepSeek-V3 — только async-роли."*

---

## Why TTFT (Time-to-First-Token) Decides the Dialogue Model

In a spoken voice session, the agent and learner engage in continuous real-time dialogue over WebRTC. The turn-taking pipeline runs sequentially:

$$\text{Total Turnaround} = \text{STT Final} + \text{LLM TTFT} + \text{TTS First Chunk}$$

1. **Streaming Audio Pipelining**:
   LiveKit Agents (`livekit-agents` 1.8) does **not** wait for the LLM to complete its entire response. It streams LLM completion tokens immediately into the TTS synthesizer (Cartesia Sonic / Deepgram Aura). Audio synthesis begins as soon as the first sentence boundary is parsed.
2. **Total Latency is Irrelevant for Responsiveness**:
   Whether a model generates 150 tokens in 2 seconds or 5 seconds does not affect the learner's initial perceived pause, provided tokens arrive faster than the speaking rate (~15–20 tokens/sec).
3. **Price is Secondary in Real-Time Voice**:
   DeepSeek-V3 offers industry-leading token pricing ($0.14/M input, $0.28/M output), which makes it ideal for async background tasks (`AnalyzeVoiceSessionMistakes`, `AnalyzeAssessment`). However, in conversational voice, a saving of a fraction of a cent per turn cannot compensate for a 2–3 second delay that breaks conversational flow.
4. **TTFT is the Critical Bottleneck**:
   The learner is waiting in silence from the moment they stop speaking until the voice agent begins speaking. TTFT is the variable component that determines whether this silence is acceptable or jarring.

---

## Conversational Turn Budget & Decision Rule

Psycholinguistic studies of human conversation (e.g., Stivers et al., 2009) establish that normal turn gaps between human speakers are **200–300 ms**. In voice AI systems, user perception degrades on the following curve:
- **< 800 ms**: Highly responsive, natural conversational flow.
- **800–1200 ms**: Acceptable, comfortable pause.
- **1200–1500 ms**: Noticeable hesitation; borderline conversational fatigue.
- **> 1500 ms**: Awkward silence; learner assumes the system did not hear them and often starts repeating themselves, causing false interruptions.

### Target Budget Breakdown (P95)

| Stage | Expected Latency (P95) | Notes |
|---|---|---|
| **STT Final (`stt_final`)** | **350–500 ms** | Includes VAD silence window (300–500 ms) + Deepgram Nova-2 streaming finalization (150–200 ms) |
| **TTS First Chunk (`tts_first_chunk`)** | **150–250 ms** | Cartesia Sonic TTFB (~100–150 ms) + WebRTC audio packetization |
| **LLM TTFT (`llm_first_token`)** | **≤ 600 ms** | **Budget allowance to keep Total Turnaround ≤ 1200 ms** |
| **Total Turnaround** | **≤ 1200 ms** | Human-acceptable conversational limit |

### The Decision Rule

A candidate dialogue model is evaluated against its **P95 TTFT**:
- **PASS (Recommended)**: $\text{P95 TTFT} \le 600\text{ ms}$. Keeps total turnaround within the 1.2s conversational envelope.
- **BORDERLINE**: $600\text{ ms} < \text{P95 TTFT} \le 800\text{ ms}$. Usable if no faster candidate is available, but noticeable pauses will occur on the tail.
- **DISQUALIFIED (Async-Only)**: $\text{P95 TTFT} > 800\text{ ms}$. Pushes total turn latency beyond 1.5s; model must remain restricted to async analysis roles.

---

## Honest Status: Closing the Plan §7 Item

> [!IMPORTANT]
> The checklist item in **`ai-language-coach-plan.md` §7** (*"Диалоговая LLM выбрана по бенчмарку; DeepSeek-V3 — только async-роли"*) **cannot be officially marked closed until at least two candidate providers with valid API keys have been benchmarked head-to-head under identical conditions**.
>
> In the current environment, only `DEEPSEEK_API_KEY` is populated. DeepSeek is measured now as the baseline. To fully close the item, an API key for a second candidate (OpenAI `gpt-4o-mini` or Groq `llama-3.3-70b-versatile`) must be added to `.env`, and the benchmark rerun.

---

## How to Run the Benchmark

The harness is completely self-contained in `benchmarks/dialogue_llm_latency.py` (Python stdlib; no extra packages required).

### Option 1: Running on Host (Recommended)

From the project root:

```bash
# Default candidates and 10 repetitions
python3 benchmarks/dialogue_llm_latency.py

# Custom candidates and 12 repetitions
python3 benchmarks/dialogue_llm_latency.py -n 12 --models "deepseek:deepseek-chat,openai:gpt-4o-mini,groq:llama-3.3-70b-versatile"
```

### Option 2: Running Inside the Docker Voice-Agent Container

If you prefer to run inside the existing container network:

```bash
# Method A: Pipe script into running container
docker exec -i coach_voice_agent python - < benchmarks/dialogue_llm_latency.py

# Method B: Run with docker compose
docker compose exec voice-agent python -c "
import urllib.request
# Or copy benchmarks/ to /app/benchmarks and run
"
```

### CLI Arguments

| Argument | Env Variable | Default | Description |
|---|---|---|---|
| `--models`, `-m` | `BENCHMARK_MODELS` | Default candidate list | Comma-separated list (`provider:model` or `provider:model:base_url`) |
| `--repetitions`, `-n` | `BENCHMARK_REPETITIONS` | `10` | Number of measured iterations per candidate (minimum 10 recommended) |
| `--timeout`, `-t` | `BENCHMARK_TIMEOUT` | `20.0` | HTTP request timeout in seconds |
| `--output-dir`, `-o` | — | `benchmarks/results` | Destination directory for timestamped JSON artifact |
| `--no-warmup` | — | `False` | Skip initial warm-up run (warm-up is enabled by default) |
| `--json-only` | — | `False` | Output only raw JSON to stdout |

---

## How to Add a Provider

The harness supports any OpenAI-compatible provider.

1. **Add the provider's API key to `.env`**:
   - OpenAI: `OPENAI_API_KEY=sk-...`
   - Groq: `GROQ_API_KEY=gsk-...`
   - Custom provider: `MYPROVIDER_API_KEY=...` or universal `LLM_API_KEY=...`
2. **Pass the candidate string to the benchmark**:
   ```bash
   python3 benchmarks/dialogue_llm_latency.py -m "groq:llama-3.3-70b-versatile,openai:gpt-4o-mini"
   ```
   Or set the environment variable:
   ```bash
   export BENCHMARK_MODELS="groq:llama-3.3-70b-versatile,openai:gpt-4o-mini"
   ```

Candidate syntax:
- `deepseek:deepseek-chat` (maps to `https://api.deepseek.com`, key: `DEEPSEEK_API_KEY`)
- `openai:gpt-4o-mini` (maps to `https://api.openai.com/v1`, key: `OPENAI_API_KEY`)
- `groq:llama-3.3-70b-versatile` (maps to `https://api.groq.com/openai/v1`, key: `GROQ_API_KEY`)
- `custom:my-model:https://my-llm.com/v1` (custom base URL, key: `LLM_API_KEY`)

---

## How to Read the Results

Each run prints a summary table and writes a sanitized JSON artifact to `benchmarks/results/<UTC-TIMESTAMP>.json`.

### Metrics Explained
- **Warm-Up Run**: Executed before measured runs to prime DNS resolution, TCP handshake, TLS session cache, and provider connection pools. **Excluded from statistics** to reflect steady-state conversation latency.
- **TTFT P50 (Median)**: Typical first-token latency under normal conditions.
- **TTFT P90 / P95**: Tail latency. In conversational voice, tail latency is critical because a user experiences a 95th-percentile pause once every ~20 turns (approx. once per 5-minute call).
- **Total P50**: Median elapsed time to complete generating the full turn response.
- **Status / Verdict**:
  - `PASS (<=600ms)`: Model qualifies for real-time dialogue role.
  - `BORDERLINE (<=800ms)`: Model usable with noticeable hesitation on tail.
  - `FAIL (>800ms budget)`: Disqualified for voice turns; restrict to async roles.
  - `SKIPPED (no key)`: Provider skipped because API key was unset in environment.

---

## Applying the Winning Model to the Voice Agent

The voice agent in `agent/agent.py` is fully environment-driven:

```bash
# In docker-compose.yml or .env:
LLM_PROVIDER=groq
LLM_MODEL=llama-3.3-70b-versatile
LLM_BASE_URL=https://api.groq.com/openai/v1
GROQ_API_KEY=gsk-...
```

Zero code changes or container image rebuilds are needed to switch the dialogue model.
