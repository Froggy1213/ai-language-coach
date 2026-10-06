# AI Language Coach — Voice Agent

Python worker built on top of [LiveKit Agents](https://docs.livekit.io/agents/).
Acts as the conversational language tutor in practice voice rooms (`/practice/{cardId}`).

## Features

- **WebRTC voice streaming** via LiveKit Server.
- **STT**: Deepgram Nova-2 streaming speech-to-text.
- **LLM**: DeepSeek-V3 or OpenAI conversational response generation tailored to learner's CEFR level and grammar prompt.
- **TTS**: Cartesia Sonic or Deepgram Aura text-to-speech.
- **Backend lifecycle**:
  - Receives `job.metadata` from Laravel (`session_id`, `grammar_point`, `practice_prompt`, `target_language`, `level`).
  - Reports fatal stream errors to `POST /api/internal/sessions/{id}/fail` with `X-Internal-Secret`.
  - Measures per-turn stage latencies (`stt_final`, `llm_first_token`, `tts_first_chunk`, `total_turnaround` in ms) across LiveKit Agents 1.8 metric events (`metrics_collected`), reports them to `POST /api/internal/sessions/{id}/turns` with `X-Internal-Secret`, and writes structured JSON log lines (`TURN_LATENCY`) to stdout.

## Running with Docker (Recommended)

Included in root `docker-compose.yml`:

```bash
docker compose up -d voice-agent
```

`docker-compose.yml` supplies every setting, including
`LIVEKIT_URL=ws://livekit:7880` — the service name, because inside the container
`127.0.0.1` is the agent itself. `agent/.env` is excluded from the image
(`agent/.dockerignore`) so a local file cannot silently override those values.

The image also exposes the worker's health server on `:8081`; `GET /` answers
`200 OK` once the worker is registered, which is what the compose healthcheck
polls.

## Running Natively

The agent then talks to a LiveKit server on the host, started either by
`docker compose up -d livekit` (which publishes `127.0.0.1:7880`) or by
`./scripts/livekit-dev.sh` — not both, they bind the same port.

```bash
cd agent
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

cp .env.example .env
# Fill in DEEPGRAM_API_KEY, DEEPSEEK_API_KEY, etc.

python agent.py dev
```

## Observability

The voice agent integrates with Sentry for error tracking and crash reporting (plan §1, §7). It mirrors the backend convention: when `SENTRY_DSN` is unconfigured, Sentry is a complete no-op (no network attempts, no startup impact).

### Enabling Sentry

Supply `SENTRY_DSN` in your environment (or via Compose):

```bash
SENTRY_DSN=https://<public_key>@<org>.ingest.sentry.io/<project_id>
SENTRY_ENVIRONMENT=production    # defaults to SENTRY_ENVIRONMENT, then APP_ENV, then 'local'
SENTRY_RELEASE=v1.0.0            # optional release tag (e.g. git commit hash)
SENTRY_TRACES_SAMPLE_RATE=0      # default 0 (errors only)
```

### What is and is not sent

- **What IS sent**:
  - Exception type, value, and stack trace frames.
  - Session routing tags: `voice_session_id`, `room_name`, `grammar_point`, `target_language` (enough context to pinpoint the session without revealing its contents).
  - Timing and latency numbers (`stt_final`, `llm_first_token`, `tts_first_chunk`, `total_turnaround`).
- **What is NOT sent (Privacy by Design)**:
  - Learner speech is personal data. We send the *shape* of a failure, never the learner's words.
  - A strict `before_send` scrubber removes or redacts any event payload key that can carry speech or prompts (`transcript`, `practice_prompt`, `user_utterance`, `correction`, `explanation`, `text_content`, `raw_text_content`, `last_user_transcript`, etc.) with `[REDACTED]`.
  - Over-long free-form strings (> 256 characters) are truncated with `... [TRUNCATED]`.
  - `send_default_pii=False` is enforced.

### Verifying a test event

To verify that events reach Sentry, run a one-line Python test with your DSN:

```bash
python -c "
import os; os.environ['SENTRY_DSN'] = 'https://<key>@<org>.ingest.sentry.io/<project>';
from sentry_integration import init_sentry, capture_exception;
init_sentry();
capture_exception(RuntimeError('Sentry verification test from voice-agent'), tags={'voice_session_id': 'test'});
"
```
Or trigger a simulated failure within tests using `python -m unittest agent/test_sentry.py`.
