<script setup lang="ts">
import type { LessonCard } from '~/types/graphql'
import { roadmap } from '~/constants/strings'

/**
 * One stop on the roadmap.
 *
 * The roadmap is a path, so it is drawn as one: a continuous rail with a node per
 * lesson, read top to bottom. Only the current lesson expands into a call to
 * action; completed and locked lessons collapse to a single readable line each,
 * which is what keeps a thirty-lesson path scannable instead of a wall of cards.
 */
defineProps<{
  card: LessonCard
  /** Last card in the list: the rail stops here instead of dangling. */
  last?: boolean
}>()
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
          'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)] shadow-[0_0_0_5px_var(--accent-dim)]': card.status === 'ready',
          'border-[color-mix(in_oklab,var(--accent)_40%,transparent)] bg-[color-mix(in_oklab,var(--accent)_14%,transparent)] text-[var(--accent)]': card.status === 'completed',
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
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <p class="label pt-0.5">{{ card.grammarPoint.category }}</p>
        <span
          v-if="card.status === 'ready'"
          class="rounded-full bg-[var(--accent-dim)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-[0.08em] text-[var(--accent)]"
        >
          {{ roadmap.current }}
        </span>
      </div>

      <h2
        class="mt-1 text-[1.0625rem] leading-snug font-medium"
        :class="card.status === 'locked' ? 'text-[var(--ink-muted)]' : 'text-[var(--ink)]'"
      >
        {{ card.grammarPoint.title }}
      </h2>

      <!-- Only the current lesson opens up. -->
      <div v-if="card.status === 'ready'" class="mt-4 space-y-3">
        <p class="rounded-xl bg-[var(--surface-sunken)] px-3.5 py-3 text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
          {{ card.practicePrompt }}
        </p>

        <CheatSheetPanel :cheat-sheet="card.cheatSheet" />

        <UiButtonLink :to="`/talk/${card.id}`" variant="primary">
          {{ roadmap.startCard }}
        </UiButtonLink>
      </div>

      <p v-else-if="card.status === 'completed'" class="mt-1.5 text-[0.8125rem] text-[var(--ink-faint)]">
        {{ roadmap.completedHint }}
      </p>

      <p v-else class="mt-1.5 text-[0.8125rem] text-[var(--ink-faint)]">
        {{ roadmap.lockedHint }}
      </p>
    </div>
  </li>
</template>
