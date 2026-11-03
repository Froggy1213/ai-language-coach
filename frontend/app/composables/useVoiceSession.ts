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
 * Set and cleared by the room's Reconnecting/Reconnected events. Kept as a
 * constant because the reconnect handler compares against the exact text it
 * wrote — an inline literal is one careless edit away from never clearing.
 */
const RECONNECTING_MESSAGE = '接続が切れました。会話ルームに再接続しています…'

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

  const getRoom = (): Room | null => room

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

  async function leave(): Promise<void> {
    stopTimerTicker()
    presence.stopLevelPolling()
    tracks.cleanupTracks()
    playback.removeAudioUnlock()
    agentState.value = null

    const currentLocalTrack = localAudioTrack.value
    localAudioTrack.value = null
    localAudioLevel.value = 0

    if (room) {
      const currentRoom = room
      room = null

      try {
        if (currentRoom.localParticipant) {
          await currentRoom.localParticipant.setMicrophoneEnabled(false)
        }
      } catch {}

      try {
        await currentRoom.disconnect()
      } catch {}
    }

    try {
      currentLocalTrack?.stop()
    } catch {}

    agentPresent.value = false
    if (status.value !== 'error') {
      status.value = 'ended'
    }
  }

  async function start(subject: VoicePracticeSubject): Promise<void> {
    if (disposed || status.value === 'requesting' || status.value === 'connecting' || status.value === 'connected') {
      return
    }

    error.value = null
    sessionId.value = null
    agentPresent.value = false
    status.value = 'requesting'

    if (room) {
      await leave()
    }

    // Exactly one variable: the backend treats the pair as XOR and uses the
    // presence of a key, not its value, to choose the practice subject.
    const variables = 'lessonCardId' in subject
      ? { lessonCardId: subject.lessonCardId }
      : { scenarioCode: subject.scenarioCode }

    const { data, error: failure } = await $urql
      .mutation<{ requestVoiceToken: VoiceSession }>(REQUEST_VOICE_TOKEN_MUTATION, variables)
      .toPromise()

    if (disposed) {
      return
    }

    if (failure) {
      error.value = mapVoiceError(failure as CombinedError)
      status.value = 'error'
      return
    }

    const session = data?.requestVoiceToken
    if (!session || !session.livekitToken) {
      error.value = 'サーバーが会話ルームのトークンを返しませんでした。'
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
        status.value = 'connected'
        presence.refreshAgent(newRoom)
        resetTimer()
        startTimerTicker()
        presence.startLevelPolling()
      })

      newRoom.on(RoomEvent.Disconnected, () => {
        stopTimerTicker()
        presence.stopLevelPolling()
        tracks.cleanupTracks()
        playback.removeAudioUnlock()
        agentPresent.value = false
        agentState.value = null
        if (status.value !== 'error') {
          status.value = 'ended'
        }
      })

      newRoom.on(RoomEvent.ParticipantConnected, () => {
        presence.refreshAgent(newRoom)
      })

      newRoom.on(RoomEvent.ParticipantAttributesChanged, (_changed, participant) => {
        // Only the agent publishes `lk.agent.state`. The agent is the room's
        // remote participant (the learner is the local one), so comparing sids
        // against `remoteAgent()` also ignores the learner's own attribute
        // writes and any co-present peer.
        if (participant.sid === presence.remoteAgent()?.sid) {
          presence.readAgentState()
        }
      })

      newRoom.on(RoomEvent.ParticipantDisconnected, () => {
        presence.refreshAgent(newRoom)
      })

      // `Track.attach()` already calls `element.play()`, but a browser that
      // rejects it leaves the room silent. The SDK reports that here; retry once
      // directly and arm the gesture listener, because strict browsers only
      // honour playback started inside a user interaction.
      newRoom.on(RoomEvent.AudioPlaybackStatusChanged, () => {
        if (newRoom.canPlaybackAudio) {
          playback.removeAudioUnlock()
          return
        }

        void newRoom.startAudio().catch(() => {})
        playback.armAudioUnlock()
      })

      newRoom.on(RoomEvent.TrackSubscribed, (track: RemoteTrack) => {
        tracks.attachRemoteTrack(track)
      })

      newRoom.on(RoomEvent.TrackUnsubscribed, (track: RemoteTrack) => {
        tracks.detachRemoteTrack(track)
      })

      newRoom.on(RoomEvent.Reconnecting, () => {
        error.value = RECONNECTING_MESSAGE
      })

      newRoom.on(RoomEvent.Reconnected, () => {
        if (error.value === RECONNECTING_MESSAGE) {
          error.value = null
        }

        tracks.syncLocalTrackFrom(newRoom)

        // A reconnect re-syncs participants and tracks but does not re-emit every
        // attribute, so the mirrored state is re-read rather than trusted.
        presence.readAgentState()
      })

      newRoom.on(RoomEvent.MediaDevicesError, (err: Error) => {
        error.value = `音声デバイスのエラー：${err?.message || 'マイクを利用できません'}。`
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

      if (acquisition.failure) {
        error.value = acquisition.failure.message
        void reportClientError(session.id, 'microphone', acquisition.failure.cause)
      }

      if (disposed) {
        acquisition.track?.stop()
        room = null
        return
      }

      try {
        await newRoom.connect(session.livekitUrl, session.livekitToken)
      } catch (connErr: any) {
        acquisition.track?.stop()
        throw connErr
      }

      if (disposed) {
        acquisition.track?.stop()
        room = null
        try {
          await newRoom.disconnect()
        } catch {}
        return
      }

      if (acquisition.track) {
        const publishFailure = await tracks.publishMicrophone(newRoom, acquisition.track)

        if (publishFailure) {
          error.value = publishFailure.message
          void reportClientError(session.id, 'microphone', publishFailure.cause)
        }
      }
    } catch (connErr: any) {
      error.value = `音声ルームに接続できませんでした：${connErr?.message ?? '接続エラー'}。`
      status.value = 'error'
      void reportClientError(session.id, 'webrtc_connect', connErr)
      await leave()
    }
  }

  if (import.meta.client) {
    const handleBeforeUnload = (): void => {
      if (room) {
        try {
          room.disconnect()
        } catch {}
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
  }
}
