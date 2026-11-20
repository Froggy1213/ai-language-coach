import type { CombinedError } from '@urql/vue'
import {
  Room,
  RoomEvent,
  type LocalAudioTrack,
  type RemoteAudioTrack,
  type RemoteTrack,
} from 'livekit-client'
import { ref, onUnmounted, shallowRef, type Ref } from 'vue'
import { REQUEST_VOICE_TOKEN_MUTATION } from '~/graphql/documents'
import type { VoiceSession } from '~/types/graphql'
import { useElapsedTimer } from '~/composables/useElapsedTimer'
import { useAgentPresence, type AgentVoiceState } from '~/composables/voice/useAgentPresence'
import { useAudioPlayback } from '~/composables/voice/useAudioPlayback'
import { useAudioTracks } from '~/composables/voice/useAudioTracks'
import { useClientErrorReporter } from '~/composables/voice/useClientErrorReporter'
import { voice as voiceCopy } from '~/constants/strings'
import { graphQLErrorCode, graphQLErrorExtensions } from '~/utils/graphql-error'
import { mapVoiceError } from '~/utils/voice-errors'

export type { AgentVoiceState } from '~/composables/voice/useAgentPresence'

export type VoiceSessionUiStatus = 'idle' | 'requesting' | 'connecting' | 'connected' | 'ended' | 'error'

/**
 * What the learner is practising. Exactly one of the two, mirrored by the XOR
 * the backend enforces on `requestVoiceToken`: a lesson card is grammar practice,
 * a scenario code is a role-play. The union makes "both" and "neither"
 * unrepresentable on the client as well.
 */
export type VoicePracticeSubject =
  | { lessonCardId: string }
  | { scenarioCode: string }

/**
 * Why the backend refused to start a session, when the reason is money rather
 * than a dead fleet.
 *
 * The numbers travel in GraphQL extensions so the paywall can say "you used 140
 * of 150 minutes", which it cannot reconstruct from the message.
 */
export interface VoiceLimit {
  code: 'VOICE_MINUTES_EXHAUSTED' | 'VOICE_SUBSCRIPTION_INACTIVE'
  planCode: string | null
  hasSubscription: boolean | null
  usedSeconds: number | null
  includedSeconds: number | null
  resetsAt: string | null
  status: string | null
}

/**
 * Set and cleared by the room's Reconnecting/Reconnected events. Kept as a
 * constant because the reconnect handler compares against the exact text it
 * wrote — an inline literal is one careless edit away from never clearing.
 */
const RECONNECTING_MESSAGE = voiceCopy.reconnecting

/** Extension values arrive as GraphQL JSON scalars; only numbers are useful. */
function numberFrom(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

export interface UseVoiceSessionOptions {
  audioElement?: Ref<HTMLAudioElement | null | undefined>
}

/**
 * Manages the client-side lifecycle of a spoken practice session (plan §4–5).
 *
 * Coordinates the backend token minting (`requestVoiceToken`), the WebRTC
 * connection via LiveKit, microphone publishing, audio playback attachment,
 * and reactive UI state (including explicit fleet-busy handling). The
 * supporting pieces live in `~/composables/voice/`; this composable owns the
 * connection lifecycle and publishes the reactive surface.
 */
export function useVoiceSession(options: UseVoiceSessionOptions = {}) {
  const { $urql } = useNuxtApp()

  const status = ref<VoiceSessionUiStatus>('idle')
  const error = ref<string | null>(null)
  const sessionId = ref<string | null>(null)
  /**
   * Set when the refusal was a billing one, cleared on every new attempt. The
   * screen watches it to open the paywall; `error` alone would lose the numbers.
   */
  const limit = ref<VoiceLimit | null>(null)
  const agentPresent = ref(false)
  /**
   * The agent's own voice state, mirrored from its `lk.agent.state` attribute.
   * `null` means the agent has not reported one (yet), which asks the consumer
   * to fall back to loudness rather than to guess a state.
   */
  const agentState = ref<AgentVoiceState | null>(null)

  /**
   * Analysis surface. The conversation orb needs the raw audio tracks and a
   * loudness reading, and neither belongs to the connection lifecycle — so they
   * are published here and consumed by `useConversation` rather than being
   * computed inside the transport.
   */
  const localAudioTrack = shallowRef<LocalAudioTrack | null>(null)
  const agentAudioTrack = shallowRef<RemoteAudioTrack | null>(null)
  const agentAudioLevel = ref(0)
  const localAudioLevel = ref(0)

  let room: Room | null = null
  let disposed = false
  let attemptId = 0

  const getRoom = (): Room | null => room

  function isStaleAttempt(id: number): boolean {
    return disposed || id !== attemptId
  }

  async function disconnectRoomInstance(targetRoom: Room): Promise<void> {
    try {
      targetRoom.removeAllListeners()
    } catch {}

    if (room === targetRoom) {
      room = null
    }

    try {
      if (targetRoom.localParticipant) {
        await targetRoom.localParticipant.setMicrophoneEnabled(false)
      }
    } catch {}

    try {
      await targetRoom.disconnect()
    } catch {}
  }

  const {
    elapsed: elapsedSeconds,
    start: startTimerTicker,
    stop: stopTimerTicker,
    reset: resetTimer,
  } = useElapsedTimer()

  const presence = useAgentPresence({
    agentPresent,
    agentState,
    agentAudioLevel,
    localAudioLevel,
    getRoom,
  })

  const tracks = useAudioTracks({
    audioElement: options.audioElement,
    localAudioTrack,
    agentAudioTrack,
    getRoom,
  })

  const playback = useAudioPlayback(getRoom)
  const { reportClientError } = useClientErrorReporter()

  async function teardownCurrentRoom(): Promise<void> {
    stopTimerTicker()
    presence.stopLevelPolling()
    tracks.cleanupTracks()
    playback.removeAudioUnlock()
    agentState.value = null

    const currentLocalTrack = localAudioTrack.value
    localAudioTrack.value = null
    localAudioLevel.value = 0

    const currentRoom = room
    room = null
    agentPresent.value = false

    if (currentRoom) {
      await disconnectRoomInstance(currentRoom)
    }

    try {
      currentLocalTrack?.stop()
    } catch {}
  }

  async function leave(): Promise<void> {
    const leaveAttempt = ++attemptId
    const previousStatus = status.value

    await teardownCurrentRoom()

    if (leaveAttempt !== attemptId) {
      return
    }

    if (status.value !== 'error' && previousStatus !== 'idle') {
      status.value = 'ended'
    }
  }

  /**
   * Leaves any active/pending room and resets reactive session state back to
   * `'idle'` for same-route parameter navigation (`/lesson/1 -> /lesson/2`,
   * `/talk/a -> /talk/b`).
   */
  async function reset(): Promise<void> {
    const resetAttempt = ++attemptId
    resetTimer()
    error.value = null
    limit.value = null
    sessionId.value = null
    status.value = 'idle'

    await teardownCurrentRoom()

    if (!disposed && resetAttempt === attemptId) {
      status.value = 'idle'
    }
  }

  async function start(subject: VoicePracticeSubject): Promise<void> {
    if (disposed || status.value === 'requesting' || status.value === 'connecting' || status.value === 'connected') {
      return
    }

    const currentAttempt = ++attemptId
    error.value = null
    limit.value = null
    sessionId.value = null
    agentPresent.value = false
    status.value = 'requesting'

    if (room) {
      await teardownCurrentRoom()
      if (isStaleAttempt(currentAttempt)) {
        return
      }
    }

    // Exactly one variable: the backend treats the pair as XOR and uses the
    // presence of a key, not its value, to choose the practice subject.
    const variables = 'lessonCardId' in subject
      ? { lessonCardId: subject.lessonCardId }
      : { scenarioCode: subject.scenarioCode }

    const { data, error: failure } = await $urql
      .mutation<{ requestVoiceToken: VoiceSession }>(REQUEST_VOICE_TOKEN_MUTATION, variables)
      .toPromise()

    if (isStaleAttempt(currentAttempt)) {
      return
    }

    if (failure) {
      const code = graphQLErrorCode(failure)

      if (code === 'VOICE_MINUTES_EXHAUSTED' || code === 'VOICE_SUBSCRIPTION_INACTIVE') {
        const extensions = graphQLErrorExtensions(failure)

        limit.value = {
          code,
          planCode: typeof extensions?.planCode === 'string' ? extensions.planCode : null,
          hasSubscription: typeof extensions?.hasSubscription === 'boolean' ? extensions.hasSubscription : null,
          usedSeconds: numberFrom(extensions?.usedSeconds),
          includedSeconds: numberFrom(extensions?.includedSeconds),
          resetsAt: typeof extensions?.resetsAt === 'string' ? extensions.resetsAt : null,
          status: typeof extensions?.status === 'string' ? extensions.status : null,
        }
      }

      error.value = mapVoiceError(failure as CombinedError)
      status.value = 'error'
      return
    }

    const session = data?.requestVoiceToken
    if (!session || !session.livekitToken) {
      error.value = voiceCopy.missingToken
      status.value = 'error'
      return
    }

    sessionId.value = session.id
    status.value = 'connecting'

    try {
      const newRoom = new Room({
        adaptiveStream: true,
        dynacast: true,
      })
      room = newRoom

      newRoom.on(RoomEvent.Connected, () => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        status.value = 'connected'
        presence.refreshAgent(newRoom)
        resetTimer()
        startTimerTicker()
        presence.startLevelPolling()
      })

      newRoom.on(RoomEvent.Disconnected, () => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        stopTimerTicker()
        presence.stopLevelPolling()
        tracks.cleanupTracks()
        playback.removeAudioUnlock()

        const currentLocalTrack = localAudioTrack.value
        localAudioTrack.value = null
        localAudioLevel.value = 0
        try {
          currentLocalTrack?.stop()
        } catch {}

        try {
          newRoom.removeAllListeners()
        } catch {}
        room = null

        agentPresent.value = false
        agentState.value = null
        if (status.value !== 'error') {
          status.value = 'ended'
        }
      })

      newRoom.on(RoomEvent.ParticipantConnected, () => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        presence.refreshAgent(newRoom)
      })

      newRoom.on(RoomEvent.ParticipantAttributesChanged, (_changed, participant) => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        // Only the agent publishes `lk.agent.state`. The agent is the room's
        // remote participant (the learner is the local one), so comparing sids
        // against `remoteAgent()` also ignores the learner's own attribute
        // writes and any co-present peer.
        if (participant.sid === presence.remoteAgent()?.sid) {
          presence.readAgentState()
        }
      })

      newRoom.on(RoomEvent.ParticipantDisconnected, () => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        presence.refreshAgent(newRoom)
      })

      // `Track.attach()` already calls `element.play()`, but a browser that
      // rejects it leaves the room silent. The SDK reports that here; retry once
      // directly and arm the gesture listener, because strict browsers only
      // honour playback started inside a user interaction.
      newRoom.on(RoomEvent.AudioPlaybackStatusChanged, () => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        if (newRoom.canPlaybackAudio) {
          playback.removeAudioUnlock()
          return
        }

        void newRoom.startAudio().catch(() => {})
        playback.armAudioUnlock()
      })

      newRoom.on(RoomEvent.TrackSubscribed, (track: RemoteTrack) => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        tracks.attachRemoteTrack(track)
      })

      newRoom.on(RoomEvent.TrackUnsubscribed, (track: RemoteTrack) => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        tracks.detachRemoteTrack(track)
      })

      newRoom.on(RoomEvent.Reconnecting, () => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        error.value = RECONNECTING_MESSAGE
      })

      newRoom.on(RoomEvent.Reconnected, () => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        if (error.value === RECONNECTING_MESSAGE) {
          error.value = null
        }

        tracks.syncLocalTrackFrom(newRoom)

        // A reconnect re-syncs participants and tracks but does not re-emit every
        // attribute, so the mirrored state is re-read rather than trusted.
        presence.readAgentState()
      })

      newRoom.on(RoomEvent.MediaDevicesError, (err: Error) => {
        if (room !== newRoom || isStaleAttempt(currentAttempt)) {
          return
        }
        error.value = voiceCopy.mediaDevicesError(err?.message)
        void reportClientError(session.id, 'media_devices', err)
      })

      // Acquire the microphone before `newRoom.connect()` starts ICE gathering.
      // Until a tab has been granted `getUserMedia`, browsers (notably Firefox
      // with `media.peerconnection.ice.obfuscate_host_addresses = true`) mask
      // host ICE candidates behind `.local` mDNS hostnames that a bridged Docker
      // LiveKit container cannot resolve, causing `connect()` to time out with
      // `could not establish pc connection`. Creating the track first also keeps
      // the permission prompt close to the learner's click gesture.
      const acquisition = await tracks.acquireMicrophone(newRoom)

      if (isStaleAttempt(currentAttempt)) {
        try {
          acquisition.track?.stop()
        } catch {}
        await disconnectRoomInstance(newRoom)
        return
      }

      if (acquisition.failure) {
        error.value = acquisition.failure.message
        void reportClientError(session.id, 'microphone', acquisition.failure.cause)
      }

      try {
        await newRoom.connect(session.livekitUrl, session.livekitToken)
      } catch (connErr: any) {
        try {
          acquisition.track?.stop()
        } catch {}
        if (isStaleAttempt(currentAttempt)) {
          await disconnectRoomInstance(newRoom)
          return
        }
        throw connErr
      }

      if (isStaleAttempt(currentAttempt)) {
        try {
          acquisition.track?.stop()
        } catch {}
        await disconnectRoomInstance(newRoom)
        return
      }

      if (acquisition.track) {
        const publishFailure = await tracks.publishMicrophone(newRoom, acquisition.track)

        if (isStaleAttempt(currentAttempt)) {
          try {
            acquisition.track.stop()
          } catch {}
          if (localAudioTrack.value === acquisition.track) {
            localAudioTrack.value = null
          }
          await disconnectRoomInstance(newRoom)
          return
        }

        if (publishFailure) {
          error.value = publishFailure.message
          void reportClientError(session.id, 'microphone', publishFailure.cause)
        }
      }
    } catch (connErr: any) {
      if (isStaleAttempt(currentAttempt)) {
        return
      }
      error.value = voiceCopy.connectFailed(connErr?.message)
      status.value = 'error'
      void reportClientError(session.id, 'webrtc_connect', connErr)
      await leave()
    }
  }

  if (import.meta.client) {
    const handleBeforeUnload = (): void => {
      if (room) {
        try {
          room.removeAllListeners()
        } catch {}
        try {
          room.disconnect()
        } catch {}
        room = null
      }
    }

    window.addEventListener('beforeunload', handleBeforeUnload)

    onUnmounted(() => {
      disposed = true
      window.removeEventListener('beforeunload', handleBeforeUnload)
      playback.removeAudioUnlock()
      void leave()
    })
  }

  return {
    status,
    error,
    limit,
    sessionId,
    agentPresent,
    agentState,
    elapsedSeconds,
    localAudioTrack,
    agentAudioTrack,
    agentAudioLevel,
    localAudioLevel,
    start,
    leave,
    reset,
  }
}
