import { useBackendUrl } from '~/composables/useBackendUrl'
import { csrfAwareFetch } from '~/utils/csrf-fetch'

/** The failure stages the backend accepts for a voice session. */
export type VoiceClientErrorStage = 'webrtc_connect' | 'microphone' | 'media_devices'

/**
 * Best-effort telemetry for browser-side voice failures.
 *
 * The backend cannot see a denied microphone or a failed ICE connection, so the
 * client reports the stage and a short message against the session. Delivery is
 * deliberately fire-and-forget: a failed report must never change UI state or
 * block the connection path that is already handling the same failure.
 */
export function useClientErrorReporter() {
  async function reportClientError(
    currentSessionId: string | null,
    stage: VoiceClientErrorStage,
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

  return {
    reportClientError,
  }
}
