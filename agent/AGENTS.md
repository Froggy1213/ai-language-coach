# Voice agent — local laws

Python worker on `livekit-agents`, started with `python agent.py start`. Module map and session
metadata: [`README.md`](README.md); product context: [`../AGENTS.md`](../AGENTS.md).

## Laws

1. **`agent.py` is a facade.** Tests import public names from it, but a test that *patches* a name
   must patch the module that looks it up — `session.create_stt`, not `agent.create_stt`.
2. **Blank setting means absent; a typo means the default plus a warning.** That is `settings.py`'s
   contract. Do not read `os.environ` directly in new code.
3. **DeepSeek is banned from the dialogue role.** `dialogue.py` refuses providers listed in
   `SLOW_DIALOGUE_PROVIDERS` at startup; `ALLOW_SLOW_DIALOGUE_LLM=true` is a deliberate escalation,
   not a config knob. DeepSeek stays on async backend roles (assessment, mistake analysis).
4. **Backend callbacks carry `X-Internal-Secret`** (`backend.py`):
   `POST /api/internal/sessions/{id}/fail` and `.../turns`. A callback failure is logged, never
   raised into the audio path.
5. **`pytest` is CI-only test tooling.** It must not enter the worker's runtime image.

## Gates

`python3 -m unittest discover -s . -p "test_*.py"` — CI installs `livekit-agents` and runs the
whole suite; a host run without it skips the SDK tests. Then `pytest -q test_prompts.py`, which is
written as plain pytest functions and is invisible to unittest discovery.

## Environment

CI pins Python 3.11; the host interpreter may be newer (3.15 was present on 2026-11-04). A local
green run is weaker evidence than a green CI run.
