<script setup lang="ts">
import { level as copy } from '~/constants/strings'
import { formatClock } from '~/utils/format'
import type { Assessment } from '~/types/graphql'
import type { LatestAssessment } from '~/types/view-models'

/**
 * レベル判定 — the CEFR placement recording.
 *
 * A **Configure** surface: a short guided sequence, one step visible at a time,
 * with the recording step given the whole screen because that is where the
 * learner's attention has to be. Consent is a real gate rather than a checkbox
 * buried under the button.
 */
const { user, refresh } = useAuth()
const { submit, onReady } = useAssessment()

type Stage = 'consent' | 'ready' | 'recording' | 'uploading' | 'waiting' | 'done'

const stage = ref<Stage>('consent')
const consented = ref(false)
const error = ref<string | null>(null)
const waitingFailed = ref(false)
const { elapsed, start: startTimer, stop: stopTimer, reset: resetTimer } = useElapsedTimer()
const assessedLevel = ref<string | null>(null)
const starting = ref(false)
const stopping = ref(false)

/**
 * The clarity block for the recording that was just analysed.
 *
 * Taken from the subscription payload rather than from `refresh()`: the push
 * arrives with the result already in hand, and `me` is fetched for the new level
 * only. After a reload the same block is read back from `latestAssessment`, so
 * the section survives a page change instead of vanishing with the socket.
 */
const pronunciation = ref<LatestAssessment['pronunciation']>(null)
const storedPronunciation = computed(() => user.value?.latestAssessment?.pronunciation ?? null)

let aborted = false
let recorder: MediaRecorder | null = null
let stream: MediaStream | null = null
let chunks: Blob[] = []
let stopListening: (() => void) | null = null

const elapsedLabel = computed(() => formatClock(elapsed.value))

function startRecording(): void {
  if (starting.value || stage.value !== 'ready') {
    return
  }

  void beginRecording()
}

function onRecorderStop(): void {
  void finishRecording()
}

async function beginRecording(): Promise<void> {
  if (starting.value || stage.value !== 'ready') {
    return
  }

  starting.value = true
  stopping.value = false
  error.value = null

  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch {
    starting.value = false
    error.value = copy.micDenied
    return
  }

  if (aborted) {
    stream.getTracks().forEach((track) => track.stop())
    stream = null
    starting.value = false
    return
  }

  // Chrome and Firefox record webm/opus, Safari records mp4; the API accepts
  // both, and the codec parameter is stripped before it is signed.
  try {
    const mimeType = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']
      .find((type) => MediaRecorder.isTypeSupported(type)) ?? ''

    recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined)
  } catch {
    stream.getTracks().forEach((track) => track.stop())
    stream = null
    starting.value = false
    error.value = copy.recorderFailed
    return
  }

  chunks = []

  recorder.addEventListener('dataavailable', (event) => {
    if (event.data.size > 0) {
      chunks.push(event.data)
    }
  })
  recorder.addEventListener('stop', onRecorderStop)

  recorder.start()
  resetTimer()
  startTimer()
  stage.value = 'recording'
  starting.value = false
}

function stopRecording(): void {
  if (stopping.value) {
    return
  }

  if (recorder && recorder.state === 'recording') {
    stopping.value = true
    recorder.stop()
  }
}

async function finishRecording(): Promise<void> {
  if (aborted) {
    return
  }

  stopTimer()
  stream?.getTracks().forEach((track) => track.stop())
  stream = null

  const contentType = (recorder?.mimeType || 'audio/webm').split(';')[0] ?? 'audio/webm'
  const recording = new Blob(chunks, { type: contentType })

  recorder = null
  chunks = []
  stopping.value = false

  if (recording.size === 0) {
    error.value = copy.emptyRecording
    stage.value = 'ready'

    return
  }

  await upload(recording, contentType)
}

async function upload(recording: Blob, contentType: string): Promise<void> {
  if (aborted) {
    return
  }

  stage.value = 'uploading'
  error.value = null
  waitingFailed.value = false
  // A re-recording replaces the previous result: leaving the old words on screen
  // would attribute the last attempt's evidence to this one.
  pronunciation.value = null

  try {
    const currentUser = user.value

    if (!currentUser) {
      throw new Error(copy.sessionExpired)
    }

    // Subscribe first: the analysis can finish before the mutation's response is
    // even rendered.
    stopListening = await onReady(currentUser.id, (assessment: Assessment) => {
      if (aborted) {
        return
      }

      if (assessment.status === 'failed') {
        waitingFailed.value = true
        stopListening?.()
        stopListening = null
        return
      }

      assessedLevel.value = assessment.cefrLevel ?? null
      // `assessmentReady` selects the same block `me.latestAssessment` does, so
      // the two shapes are the same one; only its optionality differs.
      pronunciation.value = assessment.pronunciation ?? null
      stage.value = 'done'
      stopListening?.()
      stopListening = null
      void refresh()
    })

    if (aborted) {
      stopListening?.()
      stopListening = null
      return
    }

    await submit(recording, contentType)

    if (aborted) {
      return
    }

    if (stage.value === 'uploading') {
      stage.value = 'waiting'
    }
  } catch (failure) {
    if (aborted) {
      return
    }

    error.value = failure instanceof Error ? failure.message : copy.sendFailed
    stage.value = 'ready'
  }
}

/** Manual fallback: the socket may be down, but the level may already be saved. */
async function checkNow(): Promise<void> {
  const before = user.value?.currentLevel
  await refresh()

  if (user.value && user.value.currentLevel !== before) {
    assessedLevel.value = user.value.currentLevel
    stage.value = 'done'
  }
}

onBeforeUnmount(() => {
  aborted = true
  stopTimer()
  stopListening?.()
  stopListening = null

  if (recorder) {
    recorder.removeEventListener('stop', onRecorderStop)

    if (recorder.state !== 'inactive') {
      try {
        recorder.stop()
      } catch {
        // Already stopped.
      }
    }

    recorder = null
  }

  if (stream) {
    stream.getTracks().forEach((track) => track.stop())
    stream = null
  }

  chunks = []
})

const steps = ['録音の同意', '録音', '判定'] as const

const activeStep = computed(() => {
  if (stage.value === 'consent') {
    return 0
  }

  return stage.value === 'ready' || stage.value === 'recording' ? 1 : 2
})
</script>

<template>
  <section class="mx-auto max-w-xl space-y-8">
    <PageHeader :title="copy.title" :lead="copy.subtitle" />

    <!-- Progress as three marks, not a percentage: the learner only needs to know
         how many decisions are left. -->
    <ol class="flex items-center gap-2 text-[0.75rem]">
      <template v-for="(step, index) in steps" :key="step">
        <li
          class="flex items-center gap-2 whitespace-nowrap transition-colors"
          :class="index <= activeStep ? 'text-[var(--ink-muted)]' : 'text-[var(--ink-faint)]'"
        >
          <span
            class="size-1.5 rounded-full transition-colors"
            :class="index <= activeStep ? 'bg-[var(--accent)]' : 'bg-[var(--line-strong)]'"
          />
          {{ step }}
        </li>
        <li
          v-if="index < steps.length - 1"
          class="h-px w-4 shrink-0"
          :class="index < activeStep
            ? 'bg-[color-mix(in_oklab,var(--accent)_40%,transparent)]'
            : 'bg-[var(--line)]'"
          aria-hidden="true"
        />
      </template>
    </ol>

    <UiNotice v-if="error" :message="error" />

    <!-- 1. Consent. A real gate, written as three plain statements. -->
    <div v-if="stage === 'consent'" class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6">
      <h2 class="text-[1rem] font-medium">{{ copy.consentTitle }}</h2>

      <ul class="space-y-2.5">
        <li
          v-for="point in copy.consentPoints"
          :key="point"
          class="flex gap-3 text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]"
        >
          <span class="mt-2.5 size-1 shrink-0 rounded-full bg-[var(--ink-faint)]" aria-hidden="true" />
          {{ point }}
        </li>
      </ul>

      <label class="flex cursor-pointer items-start gap-3 text-[0.9375rem] leading-[1.7]">
        <input
          v-model="consented"
          type="checkbox"
          class="mt-1 size-4 shrink-0 accent-[var(--accent)]"
        >
        <span>{{ copy.consentLabel }}</span>
      </label>

      <UiButton variant="primary" size="lg" :disabled="!consented" @click="stage = 'ready'">
        {{ copy.consentCta }}
      </UiButton>
    </div>

    <!-- 2a. Ready to record. -->
    <div v-else-if="stage === 'ready'" class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6">
      <h2 class="text-[1rem] font-medium">{{ copy.readyTitle }}</h2>
      <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ copy.readyBody }}</p>
      <UiButton variant="primary" size="lg" :loading="starting" @click="startRecording">
        {{ copy.start }}
      </UiButton>
    </div>

    <!-- 2b. Recording. The timer is the only thing on screen that moves. -->
    <div v-else-if="stage === 'recording'" class="space-y-5 rounded-3xl border border-[var(--accent-dim)] bg-[var(--surface)] p-6">
      <div class="flex items-center gap-3">
        <span class="size-2 animate-pulse rounded-full bg-[var(--color-clay-400)]" />
        <span class="text-[1rem] font-medium">{{ copy.recording }}</span>
        <span class="tnum ml-auto font-mono text-[1.125rem] text-[var(--ink-muted)]">{{ elapsedLabel }}</span>
      </div>
      <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ copy.recordingBody }}</p>
      <UiButton variant="quiet" size="lg" :loading="stopping" @click="stopRecording">
        {{ copy.stop }}
      </UiButton>
    </div>

    <div v-else-if="stage === 'uploading'" class="flex items-center gap-3 text-[0.9375rem] text-[var(--ink-muted)]">
      <UiSpinner />
      {{ copy.uploading }}
    </div>

    <!-- 3. Waiting for the analysis. -->
    <div v-else-if="stage === 'waiting'" class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6">
      <template v-if="!waitingFailed">
        <div class="flex items-center gap-3">
          <UiSpinner />
          <p class="text-[0.9375rem]">{{ copy.waiting }}</p>
        </div>
      </template>

      <UiNotice v-else tone="warn" :message="copy.waitingFailed" />

      <div class="flex flex-wrap items-center gap-3">
        <UiButton variant="quiet" @click="checkNow">{{ copy.checkNow }}</UiButton>
        <UiButton
          v-if="waitingFailed"
          variant="ghost"
          @click="stage = 'ready'; waitingFailed = false"
        >
          {{ copy.recordAgain }}
        </UiButton>
      </div>
    </div>

    <!-- 4. Done. -->
    <div v-else class="space-y-5 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6">
      <div class="space-y-1">
        <p class="label">{{ copy.doneTitle }}</p>
        <p class="tnum display text-[2.5rem] leading-none">{{ assessedLevel ?? user?.currentLevel ?? '—' }}</p>
        <p class="text-[0.8125rem] text-[var(--ink-faint)]">{{ copy.doneLevel }}</p>
      </div>

      <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">{{ copy.doneBody }}</p>

      <UiButtonLink to="/roadmap" variant="primary" size="lg">{{ copy.openRoadmap }}</UiButtonLink>
    </div>

    <!-- The clarity evidence for this recording. Rendered outside the result card
         so it stays visible after a reload, when it comes from `me` rather than
         from the subscription that closed the recording step. -->
    <PronunciationPanel :feedback="pronunciation ?? storedPronunciation" />
  </section>
</template>
