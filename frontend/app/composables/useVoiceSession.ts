import type { CombinedError } from '@urql/vue'
import {
  Room,
  RoomEvent,
  Track,
  type LocalAudioTrack,
  type RemoteAudioTrack,
  type RemoteParticipant,
  type RemoteTrack,
} from 'livekit-client'
import { ref, onUnmounted, shallowRef, type Ref } from 'vue'
import { REQUEST_VOICE_TOKEN_MUTATION } from '~/graphql/documents'
import type { VoiceSession } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { csrfAwareFetch } from '~/utils/csrf-fetch'
import { useBackendUrl } from '~/composables/useBackendUrl'
import { useElapsedTimer } from '~/composables/useElapsedTimer'
import { talk } from '~/constants/strings'

export type VoiceSessionUiStatus = 'idle' | 'requesting' | 'connecting' | 'connected' | 'ended' | 'error'

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
 * and reactive UI state (including explicit fleet-busy handling).
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

  /**
   * The room's first remote participant, which in a voice session is the agent.
   *
   * Loudness lives on the *participant*, not the track: LiveKit derives it from
   * the audio stream's own level reports, which keeps working even when the
   * browser refuses to give us a Web Audio handle on the media stream. The
   * agent's state attribute is read from the same participant.
   */
  function remoteAgent(): RemoteParticipant | null {
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

  const {
    elapsed: elapsedSeconds,
    start: startTimerTicker,
    stop: stopTimerTicker,
    reset: resetTimer,
  } = useElapsedTimer()

  let room: Room | null = null
  let disposed = false
  let levelPoll: ReturnType<typeof setInterval> | null = null
  const attachedElements: HTMLMediaElement[] = []
  const attachedTrackSids = new Set<string>()

  /**
   * Drops the gesture fallback for blocked audio playback. Safe to call at any
   * time: removing a listener that was never added is a no-op.
   */
  function removeAudioUnlock(): void {
    if (import.meta.client) {
      document.removeEventListener('pointerdown', unlockAudio)
    }
  }

  /**
   * Retries playback from a user gesture.
   *
   * Strict browsers (Safari/iOS, Chrome without a qualifying interaction) reject
   * `element.play()` until playback is started from inside a gesture, so this
   * runs on the next `pointerdown` and retires itself once the room reports
   * audio. It stays armed after a failed retry — otherwise one unlucky gesture
   * could leave the room silent for the rest of the session.
   */
  function unlockAudio(): void {
    if (!room) {
      removeAudioUnlock()
      return
    }

    if (room.canPlaybackAudio) {
      removeAudioUnlock()
      return
    }

    void room.startAudio().catch(() => {})
  }

  /**
   * Arms the one-shot gesture fallback for blocked autoplay. Registering the
   * same listener more than once is a no-op per the DOM spec, so repeated
   * playback-status events cannot stack handlers.
   */
  function armAudioUnlock(): void {
    if (import.meta.client) {
      document.addEventListener('pointerdown', unlockAudio)
    }
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

  function startTimer(): void {
    resetTimer()
    startTimerTicker()
  }

  function stopTimer(): void {
    stopTimerTicker()
  }

  function attachRemoteTrack(track: RemoteTrack): void {
    if (track.sid && attachedTrackSids.has(track.sid)) {
      return
    }
    if (track.kind === Track.Kind.Audio) {
      if (track.sid) {
        attachedTrackSids.add(track.sid)
      }
      const el = options.audioElement?.value
        ? track.attach(options.audioElement.value)
        : track.attach()
      attachedElements.push(el)

      // Voice agents always join as the remote participant, so the first remote
      // audio track is the one the orb should listen to.
      agentAudioTrack.value = track as RemoteAudioTrack
    }
  }

  /** Clamps a room-reported level into 0–1, or zero when it is unavailable. */
  function levelOf(value: number | undefined): number {
    return typeof value === 'number' && Number.isFinite(value)
      ? Math.min(1, Math.max(0, value))
      : 0
  }

  function sampleAgentLevel(): void {
    agentAudioLevel.value = levelOf(remoteAgent()?.audioLevel)
    localAudioLevel.value = levelOf(room?.localParticipant.audioLevel)

    // The attribute event is the push path, but it is not the only way the
    // agent's state can move: when the worker is replaced (crash, redeploy,
    // a resumed job) the room swaps in a new participant whose first published
    // state the old subscription never saw, and the orb then sits on "thinking"
    // for the rest of the call. Re-reading here costs one property access per
    // tick and makes the mirror converge instead of trusting the last event.
    readAgentState()
  }

  function cleanupTracks(): void {
    if (room) {
      room.remoteParticipants.forEach((participant) => {
        participant.trackPublications.forEach((pub) => {
          if (pub.track) {
            pub.track.detach()
          }
        })
      })
    }

    for (const el of attachedElements) {
      try {
        el.srcObject = null
        if (el !== options.audioElement?.value) {
          el.remove()
        }
      } catch {}
    }
    attachedElements.length = 0
    attachedTrackSids.clear()
    agentAudioTrack.value = null
  }

  function mapVoiceError(failure: CombinedError): string {
    return graphQLErrorMessageFor(failure, {
      codes: {
        VOICE_FLEET_BUSY: talk.fleetBusy,
        VOICE_START_FAILED: 'サーバーで会話セッションを開始できませんでした。しばらくしてからお試しください。',
        VOICE_DAILY_LIMIT_REACHED: '本日の会話セッションの上限に達しました。明日また試してください。',
        LESSON_CARD_NOT_FOUND: 'このレッスンはロードマップにありません。',
        LESSON_CARD_LOCKED: 'このレッスンはまだ開いていません。',
        REVIEW_ITEM_NOT_FOUND: '復習する項目が見つかりません。',
        GRAMMAR_POINT_NOT_FOUND: '文法項目が見つかりません。',
      },
      messages: {
        'This lesson card does not exist.': 'このレッスンはロードマップにありません。',
        'This lesson card is not ready to practise yet.': 'このレッスンはまだ練習できません。',
      },
      fallback: '会話セッションを開始できませんでした。',
    })
  }

  async function reportClientError(
    currentSessionId: string | null,
    stage: 'webrtc_connect' | 'microphone' | 'media_devices',
    rawMessage: unknown,
  ): Promise<void> {
    if (!import.meta.client || !currentSessionId) {
      return
    }

    const message = typeof rawMessage === 'string'
      ? rawMessage.slice(0, 255)
      : String((rawMessage as Error | undefined)?.message ?? 'unknown error').slice(0, 255)

    try {
      const backendUrl = useBackendUrl()
      await csrfAwareFetch(backendUrl, `${backendUrl}/api/sessions/${encodeURIComponent(currentSessionId)}/client-error`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ stage, message }),
      })
    } catch {
      // Telemetry delivery is best-effort and must never interfere with UI state.
    }
  }

  async function leave(): Promise<void> {
    stopTimer()
    stopLevelPolling()
    cleanupTracks()
    removeAudioUnlock()
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

  async function start(lessonCardId: string): Promise<void> {
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

    const { data, error: failure } = await $urql
      .mutation<{ requestVoiceToken: VoiceSession }>(REQUEST_VOICE_TOKEN_MUTATION, { lessonCardId })
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
        agentPresent.value = newRoom.remoteParticipants.size > 0
        readAgentState()
        startTimer()
        startLevelPolling()
      })

      newRoom.on(RoomEvent.Disconnected, () => {
        stopTimer()
        stopLevelPolling()
        cleanupTracks()
        removeAudioUnlock()
        agentPresent.value = false
        agentState.value = null
        if (status.value !== 'error') {
          status.value = 'ended'
        }
      })

      newRoom.on(RoomEvent.ParticipantConnected, () => {
        agentPresent.value = newRoom.remoteParticipants.size > 0
        readAgentState()
      })

      newRoom.on(RoomEvent.ParticipantAttributesChanged, (_changed, participant) => {
        // Only the agent publishes `lk.agent.state`. The agent is the room's
        // remote participant (the learner is the local one), so comparing sids
        // against `remoteAgent()` also ignores the learner's own attribute
        // writes and any co-present peer.
        if (participant.sid === remoteAgent()?.sid) {
          readAgentState()
        }
      })

      newRoom.on(RoomEvent.ParticipantDisconnected, () => {
        agentPresent.value = newRoom.remoteParticipants.size > 0
        readAgentState()
      })

      // `Track.attach()` already calls `element.play()`, but a browser that
      // rejects it leaves the room silent. The SDK reports that here; retry once
      // directly and arm the gesture listener, because strict browsers only
      // honour playback started inside a user interaction.
      newRoom.on(RoomEvent.AudioPlaybackStatusChanged, () => {
        if (newRoom.canPlaybackAudio) {
          removeAudioUnlock()
          return
        }

        void newRoom.startAudio().catch(() => {})
        armAudioUnlock()
      })

      newRoom.on(RoomEvent.TrackSubscribed, (track: RemoteTrack) => {
        attachRemoteTrack(track)
      })

      newRoom.on(RoomEvent.TrackUnsubscribed, (track: RemoteTrack) => {
        track.detach()
        if (track.sid) {
          attachedTrackSids.delete(track.sid)
        }
      })

      newRoom.on(RoomEvent.Reconnecting, () => {
        error.value = RECONNECTING_MESSAGE
      })

      newRoom.on(RoomEvent.Reconnected, () => {
        if (error.value === RECONNECTING_MESSAGE) {
          error.value = null
        }

        localAudioTrack.value
          = (newRoom.localParticipant.getTrackPublication(Track.Source.Microphone)
            ?.track as LocalAudioTrack | undefined) ?? null

        // A reconnect re-syncs participants and tracks but does not re-emit every
        // attribute, so the mirrored state is re-read rather than trusted.
        readAgentState()
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
      let preacquiredMicTrack: LocalAudioTrack | null = null
      try {
        const tracks = await newRoom.localParticipant.createTracks({ audio: true })
        preacquiredMicTrack = (tracks[0] as LocalAudioTrack | undefined) ?? null
      } catch (micErr: any) {
        const isDenied =
          micErr?.name === 'NotAllowedError' ||
          String(micErr?.name).toLowerCase().includes('permission') ||
          String(micErr?.message).toLowerCase().includes('permission')

        error.value = isDenied
          ? 'マイクの使用がブラウザで拒否されました。ブラウザの設定でマイクを許可してください。'
          : `マイクを有効にできませんでした：${micErr?.message ?? '利用できません'}。`

        void reportClientError(session.id, 'microphone', micErr)
      }

      if (disposed) {
        preacquiredMicTrack?.stop()
        room = null
        return
      }

      try {
        await newRoom.connect(session.livekitUrl, session.livekitToken)
      } catch (connErr: any) {
        preacquiredMicTrack?.stop()
        throw connErr
      }

      if (disposed) {
        preacquiredMicTrack?.stop()
        room = null
        try {
          await newRoom.disconnect()
        } catch {}
        return
      }

      if (preacquiredMicTrack) {
        try {
          await newRoom.localParticipant.publishTrack(preacquiredMicTrack)
          localAudioTrack.value = preacquiredMicTrack
        } catch (micErr: any) {
          preacquiredMicTrack.stop()
          error.value = `マイクを有効にできませんでした：${micErr?.message ?? '利用できません'}。`
          void reportClientError(session.id, 'microphone', micErr)
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
      removeAudioUnlock()
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
