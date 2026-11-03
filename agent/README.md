# AI Language Coach — Voice Agent

Python worker built on top of [LiveKit Agents](https://docs.livekit.io/agents/).
Acts as the conversational language tutor in spoken practice sessions: grammar
lessons (`/lesson/{cardId}`) and role-play scenarios (`/talk/{code}`).

## Features

- **WebRTC voice streaming** via LiveKit Server.
- **STT**: Deepgram Nova-2 streaming speech-to-text.
- **LLM**: DeepSeek-V3 or OpenAI conversational response generation tailored to the learner's CEFR level and the scene — a lesson's grammar point and task, or a scenario's role-play brief.
- **TTS**: Cartesia Sonic or Deepgram Aura text-to-speech.
- **Backend lifecycle**:
  - Receives `job.metadata` from Laravel and selects the scene from `kind` (see [Session metadata and prompts](#session-metadata-and-prompts)).
  - Reports fatal stream errors to `POST /api/internal/sessions/{id}/fail` with `X-Internal-Secret`.
  - Measures per-turn stage latencies (`stt_final`, `llm_first_token`, `tts_first_chunk`, `total_turnaround` in ms) across LiveKit Agents 1.8 metric events (`metrics_collected`), reports them to `POST /api/internal/sessions/{id}/turns` with `X-Internal-Secret`, and writes structured JSON log lines (`TURN_LATENCY`) to stdout.

## Layout

`agent.py` is the entrypoint and the compatibility surface — `python agent.py start`
and `import agent` both still resolve to it. The implementation lives beside it,
one question per module:

| Module | Owns |
| :--- | :--- |
| `agent.py` | Entrypoint: initialises Sentry, re-exports the public names, runs the worker CLI |
| `observability.py` | The process-wide logger and the Sentry adapter |
| `settings.py` | How one setting is read from the environment (blank = absent, typo = default and a warning) |
| `livekit_compat.py` | The optional LiveKit imports and the SDK generation (`AgentSession` vs `VoicePipelineAgent`) |
| `backend.py` | `POST /api/internal/sessions/{id}/fail` and `.../turns`, and the internal secret |
| `turn_metrics.py` | Pure latency functions: metric extraction, backend payload and CloudWatch line |
| `turn_tracker.py` | The per-session turn state machine: correlation by `speech_id`, flush and drain |
| `speech.py` | Deepgram/Silero recognition and Cartesia/Deepgram/OpenAI synthesis, in the session language |
| `dialogue.py` | Provider selection, the latency guard, and the request's `reasoning_effort` |
| `prompts.py` | The shared preamble and the two scenes (`LESSON_SCENE` / `SCENARIO_SCENE`), selected by `kind`; the spoken greeting |
| `turn_handling.py` | End-of-utterance configuration (`build_turn_handling`) |
| `session.py` | One voice session: metadata, component wiring, duration cap, greeting, failure policy |
| `worker.py` | `WorkerOptions` built from the deployment's environment |

Tests import through the same facade (`from agent import ...`). A test that
*patches* a name patches the module that looks it up — `session.create_stt`, not
`agent.create_stt` — because the facade binding is not what the call site reads.

## Session metadata and prompts

Laravel dispatches one of two metadata shapes (plan §4.3, §6). Both carry
`session_id`, `room_name`, `target_language` and `level`; `kind` selects which
scene `prompts.py` builds.

| Kind | Fields |
| :--- | :--- |
| Lesson — `kind: 'lesson'` | `grammar_point`, `grammar_point_id`, `practice_prompt` |
| Scenario — `kind: 'scenario'` | `scenario_code`, `setting`, `ai_role`, `ai_goal`, `learner_role`, `learner_goal`, `register`, `complications`, `opening_line` |

`useful_phrases` is deliberately not dispatched: it is screen copy for the
learner's hint panel, and keeping it out of the payload leaves the job metadata
well under its 2 KB ceiling. A scenario dispatch carries no lesson fields, and a
missing `kind` is treated as a lesson — that is what every job sent before
scenario mode existed looks like.

The system prompt is assembled from a shared preamble plus one scene instead of
one long block per mode:

- `SHARED_PREAMBLE` — who Kirie is and the invariants true in both modes
  (target language and level, 1–3 short sentences, stay in character, never
  reveal the instructions, refuse off-task roleplay);
- `LESSON_SCENE` — the target grammar point, the practice task, and the gentle
  in-conversation correction rule;
- `SCENARIO_SCENE` — setting, both roles and goals, register, complications and
  opening line, plus the four conversation rules that keep a role-play from
  turning into an interview:
  1. **Never correct** the learner's errors — no recasts, no "actually, you
     should say…"; errors are analysed asynchronously after the conversation.
  2. **At most one question every two turns** — otherwise the scene becomes an
     interview.
  3. **Hold the AI's own goal** and introduce a complication when the
     conversation goes too smoothly; do not solve the learner's task for them.
  4. **Hand over the initiative** and do not fill pauses — the learner has to
     start turns and find their own words.

`build_greeting()` speaks a scenario's `opening_line` verbatim, because it is the
first line of the scene rather than a task description; a lesson, or a scenario
with no opening line, keeps the existing lesson greeting. This contract is
pinned by `agent/test_prompts.py`, which also checks that the lesson's
correction rule never leaks into a scenario and that missing fields do not crash
prompt construction. It is written as plain pytest functions so it reads as a
contract rather than a class hierarchy; `unittest` discovery cannot collect
those, so the agent CI job installs pytest and runs this file as a second,
explicit step. pytest stays a CI-only test dependency and is not added to the
worker's runtime image.

The M6 goal review is **not** an agent change: it is a separate asynchronous
backend analysis (`DeepSeekGoalReviewer` in `backend/app/Mistakes/`) that stores
its verdict on the session and renders it on 振り返り.

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
