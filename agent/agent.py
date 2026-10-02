import asyncio
import json
import logging
import os
import sys
from typing import Any, Dict, Optional

import aiohttp
from dotenv import load_dotenv

# Load local environment if available
load_dotenv()

from livekit.agents import (
    AutoSubscribe,
    JobContext,
    WorkerOptions,
    cli,
)

# Plugins
from livekit.plugins import silero, deepgram

try:
    from livekit.plugins import openai
    HAS_OPENAI = True
except ImportError:
    HAS_OPENAI = False

try:
    from livekit.plugins import cartesia
    HAS_CARTESIA = True
except ImportError:
    HAS_CARTESIA = False

# Detect LiveKit Agents version / API (AgentSession v1 vs VoicePipelineAgent v0)
try:
    from livekit.agents import AgentSession, Agent
    USE_AGENT_SESSION = True
except ImportError:
    from livekit.agents.pipeline import VoicePipelineAgent
    USE_AGENT_SESSION = False

logger = logging.getLogger("ai-language-coach-agent")
logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(name)s: %(message)s")


async def report_session_failure(session_id: Optional[int], reason: str) -> None:
    """
    Reports fatal session errors to Laravel backend (POST /api/internal/sessions/{id}/fail).
    Guarded by VOICE_INTERNAL_SECRET.
    Allowed reasons: ['stt_failed', 'tts_failed', 'llm_failed', 'agent_error'].
    """
    if not session_id:
        return

    backend_url = os.getenv("BACKEND_INTERNAL_URL", "http://host.docker.internal:8000")
    secret = os.getenv("VOICE_INTERNAL_SECRET", "")

    if not secret:
        logger.warning("VOICE_INTERNAL_SECRET is unset; cannot report failure to backend")
        return

    valid_reasons = {"stt_failed", "tts_failed", "llm_failed", "agent_error"}
    payload_reason = reason if reason in valid_reasons else "agent_error"

    # The route lives under the `api` prefix (backend/routes/api.php), the same
    # way the LiveKit webhook URL does; BACKEND_INTERNAL_URL is the bare origin.
    endpoint = f"{backend_url.rstrip('/')}/api/internal/sessions/{session_id}/fail"
    headers = {
        "X-Internal-Secret": secret,
        "Content-Type": "application/json",
        "Accept": "application/json",
    }

    try:
        async with aiohttp.ClientSession() as session:
            async with session.post(
                endpoint,
                json={"reason": payload_reason},
                headers=headers,
                timeout=aiohttp.ClientTimeout(total=5),
            ) as response:
                logger.info(
                    "Failure reported to backend for session %s (reason: %s): HTTP %s",
                    session_id,
                    payload_reason,
                    response.status,
                )
    except Exception as exc:
        logger.error("Failed to notify backend about session %s failure: %s", session_id, exc)


def build_system_prompt(metadata: Dict[str, Any]) -> str:
    target_language = metadata.get("target_language", "en")
    level = metadata.get("level", "A2")
    grammar_point = metadata.get("grammar_point", "General conversation")
    practice_prompt = metadata.get(
        "practice_prompt", "Have a friendly conversation to practice language fluency."
    )

    return f"""You are an expert, empathetic AI Language Coach.
You are conducting a 1-on-1 spoken practice session with a language learner.

Session Details:
- Target Language: {target_language}
- Student CEFR Level: {level}
- Target Grammar Point: {grammar_point}
- Practice Goal / Scenario: {practice_prompt}

Guidelines:
1. Speak exclusively or predominantly in the target language ({target_language}), tailored to level {level}.
2. Keep your conversational turns concise (1 to 3 short sentences maximum). You are speaking aloud over voice.
3. Be conversational and interactive: ask open-ended questions so the student does most of the talking.
4. When the student makes an error related to '{grammar_point}', provide a gentle rephrase or brief correction naturally in conversation.
5. Keep the atmosphere supportive, friendly, and motivating.
"""


def create_stt(metadata: Dict[str, Any]):
    target_lang = metadata.get("target_language", "en").lower()
    lang = "en" if target_lang.startswith("en") else target_lang
    return deepgram.STT(model="nova-2", language=lang)


def create_llm():
    if not HAS_OPENAI:
        raise RuntimeError("livekit-plugins-openai is required for LLM integration")

    deepseek_key = os.getenv("DEEPSEEK_API_KEY")
    openai_key = os.getenv("OPENAI_API_KEY")
    base_url = os.getenv("LLM_BASE_URL")
    model_name = os.getenv("LLM_MODEL")

    if deepseek_key and (not openai_key or base_url or "deepseek" in (model_name or "").lower()):
        return openai.LLM(
            model=model_name or "deepseek-chat",
            base_url=base_url or "https://api.deepseek.com",
            api_key=deepseek_key,
        )

    if openai_key:
        return openai.LLM(
            model=model_name or "gpt-4o-mini",
            base_url=base_url,
            api_key=openai_key,
        )

    raise ValueError("Neither DEEPSEEK_API_KEY nor OPENAI_API_KEY is configured")


def create_tts():
    cartesia_key = os.getenv("CARTESIA_API_KEY")
    deepgram_key = os.getenv("DEEPGRAM_API_KEY")
    openai_key = os.getenv("OPENAI_API_KEY")

    if cartesia_key and HAS_CARTESIA:
        return cartesia.TTS(
            # "sonic-3" is the plugin's current default; "sonic-english" was
            # retired upstream and only survived as an alias, so naming it here
            # would pin the voice to a model the API is free to drop.
            model=os.getenv("CARTESIA_MODEL", "sonic-3"),
            voice=os.getenv("CARTESIA_VOICE", "248be419-c632-4f23-adf1-5324ed7dbf1d"),
            api_key=cartesia_key,
        )

    if deepgram_key:
        return deepgram.TTS(model=os.getenv("DEEPGRAM_TTS_MODEL", "aura-asteria-en"))

    if openai_key and HAS_OPENAI:
        return openai.TTS(model=os.getenv("OPENAI_TTS_MODEL", "tts-1"), voice="alloy")

    raise ValueError("No valid TTS provider configured (set CARTESIA_API_KEY, DEEPGRAM_API_KEY, or OPENAI_API_KEY)")


async def entrypoint(ctx: JobContext):
    logger.info("Starting voice agent job for room: %s", ctx.room.name)

    # 1. Parse metadata passed from Laravel's StartVoiceSession (App\\Voice\\StartVoiceSession)
    metadata: Dict[str, Any] = {}
    if ctx.job.metadata:
        try:
            metadata = json.loads(ctx.job.metadata)
            logger.info("Received session metadata: %s", metadata)
        except json.JSONDecodeError as err:
            logger.warning("Failed to decode job metadata JSON: %s", err)

    session_id = metadata.get("session_id")
    grammar_point = metadata.get("grammar_point", "General practice")
    practice_prompt = metadata.get("practice_prompt", "Let's practice speaking.")

    # 2. Connect worker to LiveKit room
    # This announces participant kind=AGENT to the room, which unblocks StartVoiceSession
    try:
        await ctx.connect(auto_subscribe=AutoSubscribe.AUDIO_ONLY)
        logger.info("Agent successfully connected to room %s as AGENT", ctx.room.name)
    except Exception as exc:
        logger.error("Failed to connect to LiveKit room %s: %s", ctx.room.name, exc)
        await report_session_failure(session_id, "agent_error")
        return

    # 3. Instantiate voice pipeline components
    try:
        vad = silero.VAD.load()
        stt = create_stt(metadata)
        llm_instance = create_llm()
        tts = create_tts()
    except Exception as exc:
        logger.error("Failed to initialize pipeline components: %s", exc)
        reason = "stt_failed" if "deepgram" in str(exc).lower() else "llm_failed"
        await report_session_failure(session_id, reason)
        return

    instructions = build_system_prompt(metadata)

    # 4. Run session
    try:
        if USE_AGENT_SESSION:
            logger.info("Using LiveKit AgentSession interface")
            agent_instance = Agent(instructions=instructions)
            session = AgentSession(
                vad=vad,
                stt=stt,
                llm=llm_instance,
                tts=tts,
            )
            await session.start(room=ctx.room, agent=agent_instance)

            # Opening prompt
            greeting = f"Hello! Today we are practicing {grammar_point}. {practice_prompt} Whenever you are ready, let's start!"
            await session.say(greeting, allow_interruptions=True)
        else:
            logger.info("Using LiveKit VoicePipelineAgent interface")
            pipeline_agent = VoicePipelineAgent(
                vad=vad,
                stt=stt,
                llm=llm_instance,
                tts=tts,
            )
            pipeline_agent.start(ctx.room)

            greeting = f"Hello! Today we are practicing {grammar_point}. {practice_prompt} Whenever you are ready, let's start!"
            await pipeline_agent.say(greeting, allow_interruptions=True)

    except Exception as exc:
        logger.error("Runtime error in voice agent session: %s", exc, exc_info=True)
        await report_session_failure(session_id, "agent_error")


if __name__ == "__main__":
    agent_name = os.getenv("VOICE_AGENT_NAME", "ai-language-coach")
    logger.info("Registering LiveKit worker with agent_name='%s'", agent_name)

    cli.run_app(
        WorkerOptions(
            entrypoint_fnc=entrypoint,
            agent_name=agent_name,
        )
    )
