import {
  ASSESSMENT_READY_SUBSCRIPTION,
  CREATE_ASSESSMENT_UPLOAD_URL_MUTATION,
  SUBMIT_ASSESSMENT_MUTATION,
} from '~/graphql/documents'
import { level as levelCopy } from '~/constants/strings'
import type { Assessment, PresignedUpload } from '~/types/graphql'
import {
  useLighthouseSubscription,
  type LighthouseSubscriptionHandle,
} from '~/composables/useLighthouseSubscription'

/**
 * The onboarding assessment, from the browser's side (plan §5).
 *
 * Three steps with three different transports: presign and submit are ordinary
 * GraphQL mutations, the upload is a multipart POST straight to the bucket
 * (the file never passes through the API), and the result arrives over a
 * Lighthouse subscription rather than as the mutation's answer.
 */
export function useAssessment() {
  const { $urql } = useNuxtApp()
  const lighthouse = useLighthouseSubscription()

  async function submit(recording: Blob, contentType: string): Promise<Assessment> {
    const presigned = await $urql
      .mutation<{ createAssessmentUploadUrl: PresignedUpload }>(CREATE_ASSESSMENT_UPLOAD_URL_MUTATION, { contentType })
      .toPromise()

    if (presigned.error || !presigned.data) {
      throw presigned.error ?? new Error(levelCopy.uploadAuthFailed)
    }

    const upload = presigned.data.createAssessmentUploadUrl
    const body = new FormData()

    for (const field of upload.fields) {
      body.append(field.name, field.value)
    }

    // The bucket requires the file to be the last part of the form.
    body.append('file', recording, 'recording')

    let uploaded: Response
    try {
      uploaded = await fetch(upload.uploadUrl, { method: 'POST', body })
    } catch {
      throw new Error(levelCopy.uploadNetworkFailed)
    }

    if (!uploaded.ok) {
      throw new Error(levelCopy.uploadStatusFailed(uploaded.status))
    }

    const submitted = await $urql
      .mutation<{ submitAssessment: Assessment }>(SUBMIT_ASSESSMENT_MUTATION, { audioUrl: upload.fileUrl })
      .toPromise()

    if (submitted.error || !submitted.data) {
      throw submitted.error ?? new Error(levelCopy.submitFailed)
    }

    return submitted.data.submitAssessment
  }

  /**
   * Wait for the analysis to finish.
   *
   * Opens the subscription through `useLighthouseSubscription` and watches for
   * Pusher reconnects so a dropped WebSocket re-checks the assessment status
   * and re-registers the subscription against the new socket ID if still pending.
   */
  async function onReady(
    userId: string,
    handler: (assessment: Assessment) => void,
    options?: {
      onReconnect?: () => Promise<boolean> | boolean | void
    },
  ): Promise<() => void> {
    let stopped = false
    let rechecking = false
    let currentHandle: LighthouseSubscriptionHandle | null = null
    let stopReconnectWatcher: (() => void) | null = null

    const teardown = () => {
      if (stopped) {
        return
      }
      stopped = true
      currentHandle?.stop()
      currentHandle = null
      stopReconnectWatcher?.()
      stopReconnectWatcher = null
    }

    const openSubscription = async (): Promise<void> => {
      currentHandle?.stop()
      currentHandle = null

      const handle = await lighthouse.open<Assessment>({
        query: ASSESSMENT_READY_SUBSCRIPTION,
        variables: { userId },
        event: 'assessmentReady',
        onEvent: (assessment) => {
          if (!stopped) {
            handler(assessment)
          }
        },
      })

      if (stopped) {
        handle.stop()
        return
      }

      currentHandle = handle
    }

    await openSubscription()

    stopReconnectWatcher = lighthouse.onReconnect(() => {
      if (stopped || rechecking) {
        return
      }

      rechecking = true
      void (async () => {
        try {
          const settled = await options?.onReconnect?.()
          if (stopped || settled) {
            return
          }

          await openSubscription()
        } catch {
          // Non-fatal: manual check remains available on the screen.
        } finally {
          rechecking = false
        }
      })()
    })

    return teardown
  }

  return {
    submit,
    onReady,
    onReconnect: lighthouse.onReconnect,
  }
}
