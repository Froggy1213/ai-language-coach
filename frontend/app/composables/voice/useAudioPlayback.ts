import type { Room } from 'livekit-client'

/**
 * Playback unblocking for strict browsers (Safari/iOS, Chrome without a
 * qualifying interaction).
 *
 * The helpers operate on whatever room is current, read lazily through
 * `getRoom`: the room is created and replaced by `useVoiceSession`, and a
 * captured one would go stale after a reconnect or a new session.
 */
export function useAudioPlayback(getRoom: () => Room | null) {
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
    const room = getRoom()

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

  return {
    armAudioUnlock,
    removeAudioUnlock,
  }
}
