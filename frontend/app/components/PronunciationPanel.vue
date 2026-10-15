<script setup lang="ts">
import { level as copy } from '~/constants/strings'
import type { PronunciationFeedback } from '~/types/graphql'
import type { PronunciationBand } from '~/types/view-models'

/**
 * 発音の目安 — how clearly the recording came through, on the level screen.
 *
 * This is deliberately not a pronunciation *grade*. What is measured is the
 * speech recogniser's per-word confidence: a microphone across the room, noise
 * and an unfamiliar accent move it exactly as much as an unclear word does. So
 * the panel leads with the words that were hard to recognise rather than with a
 * score, and the caveat sits with the numbers instead of behind a disclosure. The
 * evidence behind that choice is in `benchmarks/research/voice-stack-research-2026-10-14.md`.
 *
 * The band comes from the server as a string; an unknown one is treated as absent
 * rather than rendered raw, so a band added on the backend cannot leak an enum
 * name into the interface.
 */
const props = defineProps<{
  feedback?: PronunciationFeedback | null
}>()

const BAND_LABELS: Record<PronunciationBand, string> = {
  clear: copy.pronunciationBands.clear,
  mostly_clear: copy.pronunciationBands.mostly_clear,
  uneven: copy.pronunciationBands.uneven,
  effortful: copy.pronunciationBands.effortful,
}

const bandLabel = computed<string | null>(() => {
  const band = props.feedback?.band

  return band && band in BAND_LABELS ? BAND_LABELS[band as PronunciationBand] : null
})

/**
 * The share, or null when the server withheld one.
 *
 * A computed rather than a `v-if` on the raw field so the type is narrowed once
 * here instead of at every use: the schema marks the field optional, and a null
 * check in the template does not remove the `undefined`.
 */
const clarityPercent = computed<number | null>(() => props.feedback?.clarityPercent ?? null)

/**
 * Whole percent: a recogniser confidence of 0.4321 becomes 43%.
 *
 * Tolerates an absent figure because the generated type marks the field
 * optional; a missing value reads as 0 rather than as `NaN%`.
 */
function percent(confidence?: number | null): number {
  return confidence == null ? 0 : Math.round(confidence * 100)
}
</script>

<template>
  <section
    v-if="feedback && (bandLabel || feedback.uncertainWords.length > 0)"
    class="space-y-3 rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6"
  >
    <div class="space-y-1">
      <p class="label">{{ copy.pronunciationTitle }}</p>

      <p v-if="bandLabel" class="text-[1.25rem] leading-snug">{{ bandLabel }}</p>
      <!-- The share is only shown when the server produced one: a band built on
           too few words is withheld there rather than shown with a caveat here. -->
      <p v-else-if="feedback.isThinSample" class="text-[0.9375rem] text-[var(--ink-muted)]">
        {{ copy.pronunciationThinSample }}
      </p>

      <p v-if="clarityPercent !== null" class="tnum text-[0.8125rem] text-[var(--ink-faint)]">
        {{ copy.pronunciationScore(clarityPercent) }}
      </p>
    </div>

    <p v-if="feedback.advice" class="text-[0.9375rem] leading-[1.8]">
      {{ feedback.advice }}
    </p>

    <div v-if="feedback.uncertainWords.length > 0" class="space-y-2">
      <p class="text-[0.8125rem] text-[var(--ink-muted)]">{{ copy.pronunciationWordsTitle }}</p>

      <ul class="flex flex-wrap gap-2">
        <li
          v-for="word in feedback.uncertainWords"
          :key="word.word"
          class="flex items-baseline gap-2 rounded-full border border-[var(--line)] px-3 py-1"
        >
          <span class="text-[0.9375rem]">{{ word.word }}</span>
          <span class="tnum text-[0.75rem] text-[var(--ink-faint)]">
            {{ copy.pronunciationConfidence(percent(word.confidence)) }}
          </span>
        </li>
      </ul>
    </div>

    <p class="text-[0.8125rem] leading-[1.7] text-[var(--ink-faint)]">{{ copy.pronunciationCaveat }}</p>
  </section>
</template>
