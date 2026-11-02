import {
  Track,
  type LocalAudioTrack,
  type RemoteAudioTrack,
  type RemoteTrack,
  type Room,
} from 'livekit-client'
import type { Ref, ShallowRef } from 'vue'

/** A microphone failure the caller turns into UI state and telemetry. */
export interface MicrophoneFailure {
  cause: unknown
  /** The learner-facing sentence for this failure. */
  message: string
}

/** What `acquireMicrophone` produced: a track, a failure to report, or neither. */
export interface MicrophoneAcquisition {
  track: LocalAudioTrack | null
  failure: MicrophoneFailure | null
}

export interface UseAudioTracksOptions {
  audioElement?: Ref<HTMLAudioElement | null | undefined>
  /**
   * The published microphone track, cleared when the session stops.
   *
   * Shallow on purpose: LiveKit track objects are not plain data, and a deep
   * `ref()` would proxy them. The caller creates both as `shallowRef`s.
   */
  localAudioTrack: ShallowRef<LocalAudioTrack | null>
  /** The agent's remote audio track, attached to the playback element. */
  agentAudioTrack: ShallowRef<RemoteAudioTrack | null>
  /**
   * The room the session currently owns, read lazily: `cleanupTracks()` runs
   * from `leave()` and from the room's Disconnected event, which can fire after
   * `leave()` has already cleared the room.
   */
  getRoom: () => Room | null
}

/**
 * Owns the session's audio tracks: acquiring and publishing the microphone,
 * attaching the agent's remote audio to the playback element, and detaching all
 * of it again on the way out.
 */
export function useAudioTracks(options: UseAudioTracksOptions) {
  const attachedElements: HTMLMediaElement[] = []
  const attachedTrackSids = new Set<string>()

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
      options.agentAudioTrack.value = track as RemoteAudioTrack
    }
  }

  function detachRemoteTrack(track: RemoteTrack): void {
    track.detach()
    if (track.sid) {
      attachedTrackSids.delete(track.sid)
    }
  }

  function cleanupTracks(): void {
    const room = options.getRoom()

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
    options.agentAudioTrack.value = null
  }

  /**
   * Opens the microphone for a session.
   *
   * The caller must await this *before* `room.connect()`; the ordering note at
   * the call site in `useVoiceSession` explains the Firefox mDNS failure that
   * makes the order load-bearing. A failure is returned rather than thrown
   * because a missing microphone does not abort the connection — the learner
   * can still hear the agent and retry.
   */
  async function acquireMicrophone(room: Room): Promise<MicrophoneAcquisition> {
    try {
      const tracks = await room.localParticipant.createTracks({ audio: true })

      return {
        track: (tracks[0] as LocalAudioTrack | undefined) ?? null,
        failure: null,
      }
    } catch (micErr: any) {
      const isDenied =
        micErr?.name === 'NotAllowedError' ||
        String(micErr?.name).toLowerCase().includes('permission') ||
        String(micErr?.message).toLowerCase().includes('permission')

      return {
        track: null,
        failure: {
          cause: micErr,
          message: isDenied
            ? 'マイクの使用がブラウザで拒否されました。ブラウザの設定でマイクを許可してください。'
            : `マイクを有効にできませんでした：${micErr?.message ?? '利用できません'}。`,
        },
      }
    }
  }

  /**
   * Publishes the pre-acquired microphone track.
   *
   * Returns the failure instead of throwing so the caller can surface the same
   * error and telemetry it uses for an acquisition failure. A track that could
   * not be published is stopped here — keeping it open would leave the
   * microphone indicator on with nothing using it.
   */
  async function publishMicrophone(room: Room, track: LocalAudioTrack): Promise<MicrophoneFailure | null> {
    try {
      await room.localParticipant.publishTrack(track)
      options.localAudioTrack.value = track

      return null
    } catch (micErr: any) {
      track.stop()

      return {
        cause: micErr,
        message: `マイクを有効にできませんでした：${micErr?.message ?? '利用できません'}。`,
      }
    }
  }

  /**
   * Re-points the published microphone at the track the room kept across a
   * reconnect.
   */
  function syncLocalTrackFrom(room: Room): void {
    options.localAudioTrack.value
      = (room.localParticipant.getTrackPublication(Track.Source.Microphone)
        ?.track as LocalAudioTrack | undefined) ?? null
  }

  return {
    attachRemoteTrack,
    detachRemoteTrack,
    cleanupTracks,
    acquireMicrophone,
    publishMicrophone,
    syncLocalTrackFrom,
  }
}
