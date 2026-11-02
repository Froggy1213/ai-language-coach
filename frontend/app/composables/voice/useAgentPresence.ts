import type { RemoteParticipant, Room } from 'livekit-client'
import type { Ref } from 'vue'

/** The states the agent reports while a voice session is live. */
export type AgentVoiceState = 'listening' | 'thinking' | 'speaking'

/**
 * The agent participant attribute livekit-agents writes its state under
 * (`ATTRIBUTE_AGENT_STATE` in the agent SDK). The room relays it to every
 * client, so the orb can follow the agent's own state instead of inferring one
 * from loudness. The agent runtime also publishes `idle` and `initializing`;
 * neither maps onto a voice state, so they are treated as "not reported" and
 * leave the loudness fallback in charge.
 */
const AGENT_STATE_ATTRIBUTE = 'lk.agent.state'

export interface UseAgentPresenceOptions {
  /** True while the room has a remote participant (the agent). */
  agentPresent: Ref<boolean>
  /**
   * The agent's own voice state, mirrored from its `lk.agent.state` attribute.
   * `null` means the agent has not reported one (yet), which asks the consumer
   * to fall back to loudness rather than to guess a state.
   */
  agentState: Ref<AgentVoiceState | null>
  /** Room-reported loudness of the agent, sampled on its own fast interval. */
  agentAudioLevel: Ref<number>
  /** Room-reported loudness of the learner's microphone. */
  localAudioLevel: Ref<number>
  /**
   * The room the session currently owns, read lazily: the room is replaced on
   * every `start()`, and these helpers have to follow the live one.
   */
  getRoom: () => Room | null
}

/**
 * Mirrors the agent's presence, reported state and room loudness into the refs
 * `useVoiceSession` publishes.
 */
export function useAgentPresence(options: UseAgentPresenceOptions) {
  const { agentPresent, agentState, agentAudioLevel, localAudioLevel, getRoom } = options

  let levelPoll: ReturnType<typeof setInterval> | null = null

  /**
   * The room's first remote participant, which in a voice session is the agent.
   *
   * Loudness lives on the *participant*, not the track: LiveKit derives it from
   * the audio stream's own level reports, which keeps working even when the
   * browser refuses to give us a Web Audio handle on the media stream. The
   * agent's state attribute is read from the same participant.
   */
  function remoteAgent(): RemoteParticipant | null {
    const room = getRoom()

    if (!room) {
      return null
    }

    for (const participant of room.remoteParticipants.values()) {
      return participant
    }

    return null
  }

  /**
   * Mirrors the agent's reported state into `agentState`.
   *
   * livekit-agents owns this value: it publishes `listening` while it waits for
   * the learner, `thinking` while it plans a reply, and `speaking` while that
   * reply is played out. Reading it is the only reliable way to tell those
   * apart — a loudness threshold cannot distinguish "waiting" from "thinking".
   * The first remote participant is the agent in a voice session, the same
   * assumption the loudness meter below already makes. An absent or
   * unrecognised value clears the ref so the consumer can fall back.
   */
  function readAgentState(): void {
    const reported = remoteAgent()?.attributes[AGENT_STATE_ATTRIBUTE]
    agentState.value = reported === 'listening' || reported === 'thinking' || reported === 'speaking'
      ? reported
      : null
  }

  /**
   * Mirrors the presence and state a room event just changed.
   *
   * The emitting room is passed in instead of read through `getRoom()` so the
   * presence count reflects the room that fired the event, even if `start()` has
   * already replaced the current one. The state itself is still read from the
   * live room, exactly as `remoteAgent()` does.
   */
  function refreshAgent(room: Room): void {
    agentPresent.value = room.remoteParticipants.size > 0
    readAgentState()
  }

  /** Clamps a room-reported level into 0–1, or zero when it is unavailable. */
  function levelOf(value: number | undefined): number {
    return typeof value === 'number' && Number.isFinite(value)
      ? Math.min(1, Math.max(0, value))
      : 0
  }

  function sampleAgentLevel(): void {
    agentAudioLevel.value = levelOf(remoteAgent()?.audioLevel)
    localAudioLevel.value = levelOf(getRoom()?.localParticipant.audioLevel)

    // The attribute event is the push path, but it is not the only way the
    // agent's state can move: when the worker is replaced (crash, redeploy,
    // a resumed job) the room swaps in a new participant whose first published
    // state the old subscription never saw, and the orb then sits on "thinking"
    // for the rest of the call. Re-reading here costs one property access per
    // tick and makes the mirror converge instead of trusting the last event.
    readAgentState()
  }

  /**
   * Room-reported loudness for the agent, sampled on its own fast interval.
   *
   * This is the fallback the orb reads when the remote stream cannot be analysed
   * directly. It is independent of the elapsed-time ticker because a 1 Hz audio
   * envelope would visibly step.
   */
  function startLevelPolling(): void {
    if (levelPoll !== null || !import.meta.client) {
      return
    }

    levelPoll = setInterval(() => {
      sampleAgentLevel()
    }, 50)
  }

  function stopLevelPolling(): void {
    if (levelPoll !== null) {
      clearInterval(levelPoll)
      levelPoll = null
    }

    agentAudioLevel.value = 0
    localAudioLevel.value = 0
  }

  return {
    remoteAgent,
    readAgentState,
    refreshAgent,
    startLevelPolling,
    stopLevelPolling,
  }
}
