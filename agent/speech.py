"""
Speech in and speech out: the recogniser and the voice for one session.

Both are language-specific in a way that is easy to lose. A voice is not a
language-neutral asset — the Cartesia ids are single-language voices and the
Deepgram Aura model name ends in its language — and the recogniser has to be
told the one language this session is allowed to be in, or every session is
transcribed as `en-US`. So each factory takes the job metadata and resolves both
the provider and the language from it.
"""

from typing import Any, Dict, Optional

if __package__:  # imported as `agent.speech`
    from .livekit_compat import HAS_CARTESIA, HAS_OPENAI, HAS_PLUGINS, cartesia, deepgram, openai
    from .prompts import _language_code
    from .settings import _env_per_language, _env_str
else:  # run as a flat script: `python agent.py`, the container layout, the tests
    from livekit_compat import HAS_CARTESIA, HAS_OPENAI, HAS_PLUGINS, cartesia, deepgram, openai
    from prompts import _language_code
    from settings import _env_per_language, _env_str


def create_stt(metadata: Dict[str, Any]):
    """
    Streaming recogniser for the language this session is practising.

    Two things are deliberately explicit rather than defaulted:

    - The model. `livekit-plugins-deepgram` 1.8.5 already defaults to `nova-3`,
      but this call named `nova-2` and so pinned the previous generation for
      every session — and the learners here are non-native speakers, which is
      exactly the case nova-3 was retrained for. Naming the model makes it a
      deployment setting instead of a constant in a function.
    - The language. It arrives with the job metadata, so the recogniser is told
      the one language this session is allowed to be in. Left to its own default
      every session is transcribed as `en-US`.
    """
    language = _language_code(str(metadata.get("target_language") or "en"))
    model = _env_str("DEEPGRAM_STT_MODEL", "nova-3")

    return deepgram.STT(
        model=model,
        language=language,
        # The turn detector reads punctuation and hesitations, and `filler_words`
        # is what makes the recogniser keep "um" instead of quietly deleting it.
        punctuate=True,
        filler_words=True,
    )


# A voice is not language-neutral: each of these ids is a single-language voice,
# so a session in another language needs another voice or the agent reads German
# aloud in an American accent. Cartesia's sonic-3 voices are multilingual, which is
# why one id covers every language there; Deepgram's Aura voices are not, so that
# map carries an entry per language. The environment (`CARTESIA_VOICE_DE`,
# `DEEPGRAM_TTS_MODEL_DE`, `OPENAI_TTS_VOICE_DE`) overrides either per language, and
# the Deepgram ids below were read off the account's own `/v1/models` listing
# rather than guessed.
CARTESIA_VOICES: Dict[str, str] = {
    "en": "248be419-c632-4f23-adf1-5324ed7dbf1d",
}
DEEPGRAM_AURA_VOICES: Dict[str, str] = {
    "en": "aura-2-thalia-en",
    "de": "aura-2-aurelia-de",
    "fr": "aura-2-agathe-fr",
    "es": "aura-2-agustina-es",
    "it": "aura-2-cinzia-it",
    "nl": "aura-2-beatrix-nl",
    "ja": "aura-2-ama-ja",
}
OPENAI_VOICES: Dict[str, str] = {
    "en": "alloy",
}


def create_tts(metadata: Optional[Dict[str, Any]] = None):
    """
    Speech synthesis in the language the learner is practising.

    The previous signature took no language at all and the Deepgram fallback
    named `aura-asteria-en`, so the moment a second language was added to
    `config/languages.php` the agent would have answered in English-accented
    nonsense. The language now comes from the same job metadata the rest of the
    pipeline reads.
    """
    metadata = metadata or {}
    language = _language_code(str(metadata.get("target_language") or "en"))

    cartesia_key = _env_str("CARTESIA_API_KEY", None)
    deepgram_key = _env_str("DEEPGRAM_API_KEY", None)
    openai_key = _env_str("OPENAI_API_KEY", None)

    if cartesia_key and HAS_CARTESIA:
        return cartesia.TTS(
            # "sonic-3" is the plugin's current default; "sonic-english" was
            # retired upstream and only survived as an alias, so naming it here
            # would pin the voice to a model the API is free to drop.
            model=_env_str("CARTESIA_MODEL", "sonic-3"),
            voice=_env_per_language(
                "CARTESIA_VOICE", language, CARTESIA_VOICES.get(language, CARTESIA_VOICES["en"])
            ),
            api_key=cartesia_key,
        )

    if deepgram_key and HAS_PLUGINS and deepgram is not None:
        return deepgram.TTS(
            model=_env_per_language(
                "DEEPGRAM_TTS_MODEL", language, DEEPGRAM_AURA_VOICES.get(language, DEEPGRAM_AURA_VOICES["en"])
            )
        )

    if openai_key and HAS_OPENAI:
        return openai.TTS(
            model=_env_str("OPENAI_TTS_MODEL", "gpt-4o-mini-tts"),
            voice=_env_per_language(
                "OPENAI_TTS_VOICE", language, OPENAI_VOICES.get(language, OPENAI_VOICES["en"])
            ),
        )

    raise ValueError("No valid TTS provider configured (set CARTESIA_API_KEY, DEEPGRAM_API_KEY, or OPENAI_API_KEY)")


__all__ = [
    "CARTESIA_VOICES",
    "DEEPGRAM_AURA_VOICES",
    "OPENAI_VOICES",
    "create_stt",
    "create_tts",
]
