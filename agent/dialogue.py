"""
Which model answers the learner, and how the request is shaped.

The dialogue model is chosen by measurement (`benchmarks/README.md`), and this
module is the guard that keeps that decision from being quietly undone by
whichever API key happens to be in the environment. It also owns the request
shape: `reasoning_effort` is part of the latency decision, not an afterthought —
the Groq model is a reasoning model, and leaving the parameter out let Groq
apply its own `medium` default, a hidden thinking pause in front of every reply.
"""

from typing import Any, Dict, Optional

if __package__:  # imported as `agent.dialogue`
    from .livekit_compat import HAS_OPENAI, openai
    from .observability import logger
    from .settings import _env_bool, _env_str
else:  # run as a flat script: `python agent.py`, the container layout, the tests
    from livekit_compat import HAS_OPENAI, openai
    from observability import logger
    from settings import _env_bool, _env_str


# The dialogue model is chosen in the benchmark (benchmarks/README.md), and the
# recommendation is "> 800 ms p95 TTFT is async-only". This is the guard that
# keeps that decision from being quietly undone by whichever API key happens to
# be in the environment: auto-detection used to pick DeepSeek — measured at
# 1068 ms p95 on this project's own harness — because it was the only key set.
#
# Ordered by measured first-token latency, fastest first. The model ids are the
# ones each provider currently advertises for low-latency serving; a provider
# that retires one returns HTTP 404 naming its replacement, which is why
# `LLM_MODEL` exists rather than a second code change.
KNOWN_DIALOGUE_PROVIDERS: Dict[str, Dict[str, str]] = {
    "groq": {
        "model": "openai/gpt-oss-120b",
        "base_url": "https://api.groq.com/openai/v1",
        "key_var": "GROQ_API_KEY",
    },
    "cerebras": {
        "model": "gpt-oss-120b",
        "base_url": "https://api.cerebras.ai/v1",
        "key_var": "CEREBRAS_API_KEY",
    },
    "openai": {
        "model": "gpt-4o-mini",
        "base_url": "",
        "key_var": "OPENAI_API_KEY",
    },
    "deepseek": {
        "model": "deepseek-chat",
        "base_url": "https://api.deepseek.com",
        "key_var": "DEEPSEEK_API_KEY",
    },
}

# Providers whose measured first-token latency is above the conversational
# budget. They stay first-class for the asynchronous work (mistake analysis,
# CEFR assessment), where price beats speed — just not for the dialogue.
SLOW_DIALOGUE_PROVIDERS = frozenset({"deepseek"})

#: Set to a true value to run the dialogue on a provider the benchmark
#: disqualified. Named here so the message and the tests cannot drift apart.
ALLOW_SLOW_DIALOGUE_ENV = "ALLOW_SLOW_DIALOGUE_LLM"

#: Overrides the per-provider reasoning effort for the dialogue model. Unset
#: means "the default for this provider" (low on Groq, unset everywhere else);
#: `off` is the explicit value that omits the parameter — see
#: `_reasoning_effort_for` for why it is not an empty string.
LLM_REASONING_EFFORT_ENV = "LLM_REASONING_EFFORT"


def assert_dialogue_provider_is_suitable(provider: str) -> None:
    """
    Refuse to answer a learner with a model the benchmark disqualified.

    Failing at startup is the point: a fleet that boots happily and then pauses
    for a second and a half after every utterance looks healthy in every
    dashboard and is unusable in the room. `ALLOW_SLOW_DIALOGUE_LLM=true` is the
    escape hatch for a deliberate choice (a local model, an experiment), and it
    logs, so the reason is never a mystery later.
    """
    if provider not in SLOW_DIALOGUE_PROVIDERS:
        return

    if _env_bool(ALLOW_SLOW_DIALOGUE_ENV, False):
        logger.warning(
            "LLM_PROVIDER=%s is measured above the conversational latency budget "
            "(benchmarks/README.md) and has been allowed by %s. "
            "Expect pauses of a second or more after each learner utterance.",
            provider,
            ALLOW_SLOW_DIALOGUE_ENV,
        )
        return

    raise ValueError(
        f"LLM_PROVIDER={provider!r} is measured above the conversational latency budget "
        f"(p95 TTFT > 800 ms, see benchmarks/README.md), so it must not run the dialogue: "
        f"keep it for the asynchronous analysis jobs and point LLM_PROVIDER at "
        f"{', '.join(sorted(set(KNOWN_DIALOGUE_PROVIDERS) - SLOW_DIALOGUE_PROVIDERS))}. "
        f"Set {ALLOW_SLOW_DIALOGUE_ENV}=true to override deliberately."
    )


def _reasoning_effort_for(provider: str) -> Optional[str]:
    """
    The reasoning effort to send with the dialogue request, if any.

    `openai/gpt-oss-120b` is a reasoning model, but `livekit-plugins-openai` only
    fills in `reasoning_effort` itself for GPT-5-family model ids
    (`_supports_reasoning_effort` in the plugin), so the Groq path was sending
    nothing and Groq applied its own default — medium — which put a hidden
    thinking pause in front of every reply. `low` is enough for a conversation at
    this level and is what the latency benchmark points at.

    `LLM_REASONING_EFFORT` overrides the per-provider default for any provider,
    and `off` omits the parameter entirely. The sentinel is a word rather than an
    empty string because `_env_str` treats blank as absent: a Compose-forwarded
    `LLM_REASONING_EFFORT:` would return the default instead of disabling it, so
    an empty value cannot express "do not send the parameter". `"none"` is never
    produced here: Groq's GPT-OSS models accept only `low`/`medium`/`high`, and
    the `"none"` in `benchmarks/dialogue_llm_latency.py` is that harness's own
    measurement knob, not a value the production dialogue may send.
    """
    explicit = _env_str(LLM_REASONING_EFFORT_ENV, None)
    if explicit is not None:
        return None if explicit.strip().lower() == "off" else explicit.strip().lower()
    return "low" if provider == "groq" else None


def _dialogue_llm(
    provider: str,
    model_name: Optional[str],
    base_url: Optional[str],
    api_key: str,
) -> Any:
    """
    Build the OpenAI-compatible client for one provider.

    The three paths through `create_llm` (a named provider, an inferred one and
    the slow-provider fallback) differ only in where the defaults come from, so
    the request shape — including whether a `reasoning_effort` is sent at all —
    is assembled once here instead of three times.
    """
    defaults = KNOWN_DIALOGUE_PROVIDERS.get(provider) or {}
    llm_kwargs: Dict[str, Any] = {
        "model": model_name or defaults.get("model"),
        # `base_url` is only passed when it is known: an empty string points the
        # client at nowhere, so the vendor endpoint is better left implicit.
        "base_url": base_url or defaults.get("base_url") or None,
        "api_key": api_key,
    }
    reasoning_effort = _reasoning_effort_for(provider)
    if reasoning_effort is not None:
        llm_kwargs["reasoning_effort"] = reasoning_effort
    return openai.LLM(**llm_kwargs)


def create_llm():
    """
    Constructs the dialogue LLM instance for LiveKit AgentSession.

    The dialogue model is fully environment-driven:
    - LLM_PROVIDER: Candidate provider identifier (e.g. 'deepseek', 'openai', 'groq',
      or any OpenAI-compatible provider). If unset, it is inferred from the keys
      that are present — fastest known provider first, not whichever key happens
      to be set, so adding a Groq key changes the model that runs.
    - LLM_MODEL: Model identifier (e.g. 'deepseek-chat', 'gpt-4o-mini', 'llama-3.3-70b-versatile').
      Defaults per provider.
    - LLM_BASE_URL: OpenAI-compatible endpoint URL (e.g. 'https://api.deepseek.com',
      'https://api.groq.com/openai/v1', or provider default).
    - API keys: Resolved from universal override LLM_API_KEY or provider-specific keys
      (DEEPSEEK_API_KEY, OPENAI_API_KEY, GROQ_API_KEY, or {PROVIDER}_API_KEY).

    Why OpenAI-compatible interface?
    LiveKit Agents' `livekit-plugins-openai` (`openai.LLM`) natively implements the
    streaming LLM protocol used by AgentSession. All candidate providers evaluated
    in the dialogue latency benchmark (DeepSeek, OpenAI, Groq, Together, OpenRouter)
    provide OpenAI-compatible chat completion endpoints. By driving provider, model,
    and base_url from environment variables, applying the benchmark's conclusion
    requires only updating environment configuration (in docker-compose.yml or .env)
    without touching code or rebuilding containers.

    Reasoning effort is part of that decision, not an afterthought. The Groq
    model is a reasoning model, and the plugin's auto-default does not recognise
    its id, so the request used to carry no effort setting at all and Groq fell
    back to medium — latency the benchmark never measured, hidden before the
    first token. `_reasoning_effort_for` now sends `low` on Groq (and nothing on
    the non-reasoning providers), with `LLM_REASONING_EFFORT` as the override and
    `off` as the way to omit the field. `"none"` is deliberately not one of the
    values: Groq's GPT-OSS models only accept `low`/`medium`/`high`.
    """
    if not HAS_OPENAI:
        raise RuntimeError("livekit-plugins-openai is required for LLM integration")

    explicit_provider = (_env_str("LLM_PROVIDER", "") or "").lower()
    model_name = _env_str("LLM_MODEL", None)
    base_url = _env_str("LLM_BASE_URL", None)
    llm_key = _env_str("LLM_API_KEY", None)

    # A provider that was named is a decision; it is checked, not second-guessed.
    if explicit_provider:
        provider = explicit_provider
        # An unknown provider is read as a custom OpenAI-compatible endpoint, so
        # there is nothing to classify and no latency figure to hold it to.
        known = KNOWN_DIALOGUE_PROVIDERS.get(provider)
        key_var = known["key_var"] if known else f"{provider.upper()}_API_KEY"
        api_key = llm_key or _env_str(key_var, None)

        if not api_key:
            raise ValueError(f"{key_var} (or LLM_API_KEY) is required when LLM_PROVIDER is '{provider}'")

        if known:
            assert_dialogue_provider_is_suitable(provider)
        else:
            # A custom endpoint that is not told which model to use cannot be
            # guessed at: the fallback used to be a DeepSeek model id, which a
            # local or third-party endpoint would answer with a 404 at the first
            # turn. The endpoint is also required, or the request would go to the
            # OpenAI default with someone else's key.
            if not model_name:
                raise ValueError(f"LLM_MODEL is required when LLM_PROVIDER is '{provider}', which the worker has no default for")

            if not base_url:
                raise ValueError(f"LLM_BASE_URL is required when LLM_PROVIDER is '{provider}', which the worker has no default endpoint for")

        return _dialogue_llm(provider, model_name, base_url, api_key)

    # Nothing named: infer from the keys present, fastest known provider first.
    # The order matters — auto-detection that prefers "whichever key exists"
    # silently picks the slowest model as soon as it is the only key configured,
    # which is exactly how this deployment ended up on DeepSeek.
    for provider, config in KNOWN_DIALOGUE_PROVIDERS.items():
        if provider in SLOW_DIALOGUE_PROVIDERS:
            continue

        api_key = llm_key or _env_str(config["key_var"], None)
        if not api_key:
            continue

        logger.info(
            "LLM_PROVIDER is unset; using %s from %s. Set LLM_PROVIDER explicitly so the "
            "dialogue model is a decision rather than a side effect of which key is present.",
            provider,
            config["key_var"],
        )

        return _dialogue_llm(provider, model_name, base_url, api_key)

    # Nothing fast is keyed. The only configuration left is a disqualified
    # provider, which is either refused or — with the override set — actually
    # used. Returning a model here rather than falling through matters: the
    # override is the whole point of the escape hatch, and a fall-through would
    # report "nothing is configured" to an operator who had just configured it.
    slow_key = llm_key or _env_str("DEEPSEEK_API_KEY", None)
    if slow_key:
        assert_dialogue_provider_is_suitable("deepseek")
        logger.warning(
            "The dialogue is running on deepseek because %s is the only configured provider.",
            ALLOW_SLOW_DIALOGUE_ENV,
        )

        return _dialogue_llm("deepseek", model_name, base_url, slow_key)

    raise ValueError(
        "No dialogue LLM is configured. Set LLM_PROVIDER and its key "
        f"({KNOWN_DIALOGUE_PROVIDERS['groq']['key_var']} / "
        f"{KNOWN_DIALOGUE_PROVIDERS['openai']['key_var']} / LLM_API_KEY)."
    )


__all__ = [
    "ALLOW_SLOW_DIALOGUE_ENV",
    "KNOWN_DIALOGUE_PROVIDERS",
    "LLM_REASONING_EFFORT_ENV",
    "SLOW_DIALOGUE_PROVIDERS",
    "_reasoning_effort_for",
    "assert_dialogue_provider_is_suitable",
    "create_llm",
]
