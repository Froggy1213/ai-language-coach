import type { CombinedError } from '@urql/vue'
import { Room, RoomEvent, Track, type RemoteTrack } from 'livekit-client'
import { ref, onUnmounted, type Ref } from 'vue'
import { REQUEST_VOICE_TOKEN_MUTATION } from '~/graphql/documents'
import type { VoiceSession } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { useElapsedTimer } from '~/composables/useElapsedTimer'

export type VoiceSessionUiStatus = 'idle' | 'requesting' | 'connecting' | 'connected' | 'ended' | 'error'

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

  const {
    elapsed: elapsedSeconds,
    start: startTimerTicker,
    stop: stopTimerTicker,
    reset: resetTimer,
  } = useElapsedTimer()

  let room: Room | null = null
  let disposed = false
  const attachedElements: HTMLMediaElement[] = []
  const attachedTrackSids = new Set<string>()

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
    }
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
      } catch {}
    }
    attachedElements.length = 0
    attachedTrackSids.clear()
  }

  function mapVoiceError(failure: CombinedError): string {
    return graphQLErrorMessageFor(failure, {
      codes: {
        VOICE_FLEET_BUSY: 'Голосовой агент пока не запущен. Серверная часть и комната готовы к работе, но воркер голосового агента еще не запущен. Попробуйте позже.',
        VOICE_START_FAILED: 'Не удалось начать голосовую сессию на сервере. Попробуйте позже.',
        VOICE_DAILY_LIMIT_REACHED: 'Дневной лимит голосовых сессий исчерпан. Попробуйте снова завтра.',
        LESSON_CARD_NOT_FOUND: 'Этот урок не найден в вашем роадмапе.',
        LESSON_CARD_LOCKED: 'Этот урок пока заблокирован.',
        REVIEW_ITEM_NOT_FOUND: 'Материал для повторения не найден.',
        GRAMMAR_POINT_NOT_FOUND: 'Грамматическое правило не найдено.',
      },
      messages: {
        'This lesson card does not exist.': 'Этот урок не найден в вашем роадмапе.',
        'This lesson card is not ready to practise yet.': 'Этот урок пока недоступен для практики.',
      },
      fallback: 'Не удалось запросить голосовую сессию.',
    })
  }

  async function leave(): Promise<void> {
    stopTimer()
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
      error.value = 'Сервер не вернул токен для подключения к голосовой комнате.'
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
      })

      newRoom.on(RoomEvent.Disconnected, () => {
        stopTimer()
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
        error.value = 'Связь потеряна. Переподключение к голосовой комнате…'
      })

      newRoom.on(RoomEvent.Reconnected, () => {
        if (error.value === 'Связь потеряна. Переподключение к голосовой комнате…') {
          error.value = null
        }
      })

      newRoom.on(RoomEvent.MediaDevicesError, (err: Error) => {
        error.value = `Ошибка аудиоустройства: ${err?.message || 'микрофон недоступен'}.`
      })

      await newRoom.connect(session.livekitUrl, session.livekitToken)

      if (disposed) {
        room = null
        try {
          await newRoom.disconnect()
        } catch {}
        return
      }

      if (status.value === 'connecting') {
        status.value = 'connected'
        agentPresent.value = newRoom.remoteParticipants.size > 0
        startTimer()
      }

      if (disposed) {
        room = null
        try {
          await newRoom.disconnect()
        } catch {}
        return
      }

      try {
        await newRoom.localParticipant.setMicrophoneEnabled(true)
      } catch (micErr: any) {
        const isDenied =
          micErr?.name === 'NotAllowedError' ||
          String(micErr?.name).toLowerCase().includes('permission') ||
          String(micErr?.message).toLowerCase().includes('permission')

        error.value = isDenied
          ? 'Доступ к микрофону отклонен браузером. Пожалуйста, разрешите доступ к микрофону в настройках браузера.'
          : `Не удалось включить микрофон: ${micErr?.message ?? 'микрофон недоступен'}.`
      }
    } catch (connErr: any) {
      error.value = `Не удалось подключиться к голосовой комнате: ${connErr?.message ?? 'ошибка подключения'}.`
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
    start,
    leave,
  }
}
