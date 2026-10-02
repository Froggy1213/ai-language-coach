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
