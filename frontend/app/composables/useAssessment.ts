import {
  ASSESSMENT_READY_SUBSCRIPTION,
  CREATE_ASSESSMENT_UPLOAD_URL_MUTATION,
  SUBMIT_ASSESSMENT_MUTATION,
} from '~/graphql/documents'
import type { Assessment, PresignedUpload } from '~/types/graphql'
import { openLighthouseSubscription } from '~/composables/useLighthouseSubscription'

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

  async function submit(recording: Blob, contentType: string): Promise<Assessment> {
    const presigned = await $urql
      .mutation<{ createAssessmentUploadUrl: PresignedUpload }>(CREATE_ASSESSMENT_UPLOAD_URL_MUTATION, { contentType })
      .toPromise()

    if (presigned.error || !presigned.data) {
      throw presigned.error ?? new Error('Сервер не выдал разрешение на загрузку.')
    }

    const upload = presigned.data.createAssessmentUploadUrl
    const body = new FormData()

    for (const field of upload.fields) {
      body.append(field.name, field.value)
    }

    // The bucket requires the file to be the last part of the form.
    body.append('file', recording, 'recording')

    const uploaded = await fetch(upload.uploadUrl, { method: 'POST', body })

    if (!uploaded.ok) {
      throw new Error(`Загрузка записи не удалась (${uploaded.status}).`)
    }

    const submitted = await $urql
      .mutation<{ submitAssessment: Assessment }>(SUBMIT_ASSESSMENT_MUTATION, { audioUrl: upload.fileUrl })
      .toPromise()

    if (submitted.error || !submitted.data) {
      throw submitted.error ?? new Error('Не удалось отправить запись на разбор.')
    }

    return submitted.data.submitAssessment
  }

  /**
   * Wait for the analysis to finish.
   *
   * Delegates subscription opening, socket ID resolution, and channel handshake
   * confirmation to `openLighthouseSubscription`.
   */
  async function onReady(userId: string, handler: (assessment: Assessment) => void): Promise<() => void> {
    const handle = await openLighthouseSubscription<Assessment>({
      query: ASSESSMENT_READY_SUBSCRIPTION,
      variables: { userId },
      event: 'assessmentReady',
      onEvent: handler,
    })

    return () => {
      handle.stop()
    }
  }

  return { submit, onReady }
}
