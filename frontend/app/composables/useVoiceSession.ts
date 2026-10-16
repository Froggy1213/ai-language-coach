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
import { useElapsedTimer } from '~/composables/useElapsedTimer'
import { talk } from '~/constants/strings'

export type VoiceSessionUiStatus = 'idle' | 'requesting' | 'connecting' | 'connected' | 'ended' | 'error'

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
   * Loudness lives on the *participant*, not the track: LiveKit derives it from
   * the audio stream's own level reports, which keeps working even when the
   * browser refuses to give us a Web Audio handle on the media stream.
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

  async function leave(): Promise<void> {
    stopTimer()
    stopLevelPolling()
    cleanupTracks()

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

    localAudioTrack.value = null
    localAudioLevel.value = 0
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
        startTimer()
        startLevelPolling()
      })

      newRoom.on(RoomEvent.Disconnected, () => {
        stopTimer()
        stopLevelPolling()
        cleanupTracks()
        agentPresent.value = false
        if (status.value !== 'error') {
          status.value = 'ended'
        }
      })

      newRoom.on(RoomEvent.ParticipantConnected, () => {
        agentPresent.value = newRoom.remoteParticipants.size > 0
      })

      newRoom.on(RoomEvent.ParticipantDisconnected, () => {
        agentPresent.value = newRoom.remoteParticipants.size > 0
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
      })

      newRoom.on(RoomEvent.MediaDevicesError, (err: Error) => {
        error.value = `音声デバイスのエラー：${err?.message || 'マイクを利用できません'}。`
      })

      await newRoom.connect(session.livekitUrl, session.livekitToken)

      if (disposed) {
        room = null
        try {
          await newRoom.disconnect()
        } catch {}
        return
      }

      try {
        await newRoom.localParticipant.setMicrophoneEnabled(true)
        localAudioTrack.value
          = (newRoom.localParticipant.getTrackPublication(Track.Source.Microphone)
            ?.track as LocalAudioTrack | undefined) ?? null
      } catch (micErr: any) {
        const isDenied =
          micErr?.name === 'NotAllowedError' ||
          String(micErr?.name).toLowerCase().includes('permission') ||
          String(micErr?.message).toLowerCase().includes('permission')

        error.value = isDenied
          ? 'マイクの使用がブラウザで拒否されました。ブラウザの設定でマイクを許可してください。'
          : `マイクを有効にできませんでした：${micErr?.message ?? '利用できません'}。`
      }
    } catch (connErr: any) {
      error.value = `音声ルームに接続できませんでした：${connErr?.message ?? '接続エラー'}。`
      status.value = 'error'
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
      void leave()
    })
  }

  return {
    status,
    error,
    sessionId,
    agentPresent,
    elapsedSeconds,
    localAudioTrack,
    agentAudioTrack,
    agentAudioLevel,
    localAudioLevel,
    start,
    leave,
  }
}
