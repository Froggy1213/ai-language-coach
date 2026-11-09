<script setup lang="ts">
import { computed } from 'vue'
import { roadmap } from '~/constants/strings'
import type { RoadmapGraphCard } from '~/utils/roadmap-graph'

/**
 * One stop on the roadmap list view.
 *
 * Only the primary recommended lesson expands into a full call to action with
 * its practice prompt and cheat sheet; other unlocked (`ready`), `completed`,
 * and `locked` lessons stay compact so a 38-node graph remains scannable.
 */
const props = defineProps<{
  card: RoadmapGraphCard
  /** True for the primary recommended card that expands inline. */
  recommended?: boolean
  /** Last card in the list: the rail stops here instead of dangling. */
  last?: boolean
}>()

const isRecommended = computed(() =>
  props.recommended !== undefined ? props.recommended : props.card.status === 'ready',
)

const prerequisiteLabel = computed(() => {
  const prereqs = props.card.grammarPoint.prerequisites
  if (!prereqs || prereqs.length === 0) {
    return ''
  }
  return prereqs.map((p) => p.title).join(' / ')
})
</script>

<template>
  <li class="relative grid grid-cols-[2.5rem_1fr] gap-x-3 sm:grid-cols-[3rem_1fr]">
    <!-- Rail + node. The rail is its own column so it lines up across rows
         regardless of how tall each lesson's content is. -->
    <div class="relative flex justify-center" aria-hidden="true">
      <span
        v-if="!last"
        class="absolute top-9 bottom-0 w-px"
        :class="card.status === 'completed'
          ? 'bg-[color-mix(in_oklab,var(--accent)_45%,transparent)]'
          : 'bg-[var(--line)]'"
      />
      <span
        class="relative mt-1.5 grid size-8 place-items-center rounded-full border text-[0.75rem] font-semibold transition-colors"
        :class="{
          'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)] shadow-[0_0_0_5px_var(--accent-dim)]': isRecommended,
          'border-[var(--accent)] bg-[color-mix(in_oklab,var(--accent)_18%,transparent)] text-[var(--accent)]': card.status === 'ready' && !isRecommended,
          'border-[color-mix(in_oklab,var(--accent)_40%,transparent)] bg-[color-mix(in_oklab,var(--accent)_14%,transparent)] text-[var(--accent)]': card.status === 'completed' && !isRecommended,
          'border-[var(--line)] bg-[var(--surface)] text-[var(--ink-faint)]': card.status === 'locked',
        }"
      >
        <svg
          v-if="card.status === 'completed'"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2.4"
          stroke-linecap="round"
          stroke-linejoin="round"
          class="size-3.5"
        >
          <path d="m5 12.5 4.5 4.5L19 7.5" />
        </svg>
        <span v-else class="tnum">{{ card.orderIndex }}</span>
      </span>
    </div>

    <div class="min-w-0 pb-7">
      <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
        <p v-if="card.grammarPoint.level" class="label pt-0.5">
          {{ card.grammarPoint.level }} · {{ card.grammarPoint.category }}
        </p>
        <p v-else class="label pt-0.5">
          {{ card.grammarPoint.category }}
        </p>

        <span
          v-if="isRecommended"
          class="rounded-full bg-[var(--accent-dim)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.08em] text-[var(--accent)]"
        >
          {{ roadmap.current }}
        </span>
        <span
          v-else-if="card.status === 'ready'"
          class="rounded-full bg-[var(--accent-dim)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.08em] text-[var(--accent)]"
        >
          {{ roadmap.available }}
        </span>

        <span
          v-if="card.needsPractice"
          class="rounded-full bg-[color-mix(in_oklab,var(--color-amber-bright)_18%,transparent)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.08em] text-[var(--color-amber-bright)]"
        >
          {{ roadmap.needsPractice }}
        </span>
      </div>

      <h2
        class="mt-1 text-[1.0625rem] leading-snug font-medium"
        :class="card.status === 'locked' ? 'text-[var(--ink-muted)]' : 'text-[var(--ink)]'"
      >
        <NuxtLink
          v-if="card.status !== 'locked'"
          :to="`/lesson/${card.id}`"
          class="transition-colors hover:text-[var(--accent)]"
        >
          {{ card.grammarPoint.title }}
        </NuxtLink>
        <template v-else>
          {{ card.grammarPoint.title }}
        </template>
      </h2>

      <p
        v-if="card.needsPractice"
        class="mt-1 text-[0.8125rem] text-[var(--color-amber-bright)]"
      >
        {{ roadmap.needsPracticeHint }}
      </p>

      <!-- The primary recommended lesson opens up with prompt + cheat sheet. -->
      <div v-if="isRecommended && card.status !== 'locked'" class="mt-4 space-y-3">
        <p class="rounded-xl bg-[var(--surface-sunken)] px-3.5 py-3 text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
          {{ card.practicePrompt }}
        </p>

        <CheatSheetPanel v-if="card.cheatSheet" :cheat-sheet="card.cheatSheet" />

        <UiButtonLink :to="`/lesson/${card.id}`" variant="primary">
          {{ card.status === 'completed' ? roadmap.revisitCard : roadmap.startCard }}
        </UiButtonLink>
      </div>

      <!-- Other unlocked (ready) lessons stay compact with a quick start link. -->
      <div v-else-if="card.status === 'ready'" class="mt-2.5 flex flex-wrap items-center gap-3">
        <UiButtonLink :to="`/lesson/${card.id}`" variant="quiet" size="md">
          {{ roadmap.startCard }}
        </UiButtonLink>
      </div>

      <!-- Completed lessons show completion status and a subtle revisit link. -->
      <div
        v-else-if="card.status === 'completed'"
        class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[0.8125rem]"
      >
        <span class="text-[var(--ink-faint)]">{{ roadmap.completedHint }}</span>
        <NuxtLink
          :to="`/lesson/${card.id}`"
          class="font-medium text-[var(--ink-muted)] transition-colors hover:text-[var(--accent)]"
        >
          {{ roadmap.revisitCard }}
        </NuxtLink>
      </div>

      <!-- Locked lessons explain which prerequisite grammar points unlock them. -->
      <p v-else class="mt-1.5 text-[0.8125rem] text-[var(--ink-faint)]">
        <template v-if="prerequisiteLabel">
          {{ roadmap.lockedNeeds }}{{ prerequisiteLabel }}
        </template>
        <template v-else>
          {{ roadmap.lockedHint }}
        </template>
      </p>
    </div>
  </li>
</template>
