<script setup lang="ts">
import type { Mistake } from '~/types/graphql'
import { reflect } from '~/constants/strings'

/**
 * One mistake, as a correction rather than a red mark.
 *
 * The order matters: what was said, then what to say instead, then why. The
 * explanation sits behind a disclosure because the learner usually recognises the
 * fix on sight — the reasoning is there for the times they do not, and hiding it
 * keeps a ten-mistake list scannable.
 */
defineProps<{ mistake: Mistake }>()

const showWhy = ref(false)
</script>

<template>
  <article class="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 sm:p-5">
    <div class="space-y-3">
      <div class="space-y-1">
        <p class="label">{{ reflect.youSaid }}</p>
        <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-faint)] line-through decoration-[color-mix(in_oklab,var(--color-clay-400)_60%,transparent)] decoration-1">
          {{ mistake.userUtterance }}
        </p>
      </div>

      <div class="space-y-1">
        <p class="label">{{ reflect.correction }}</p>
        <p class="text-[1rem] leading-[1.8] font-medium text-[var(--color-sage-400)]">
          {{ mistake.correction }}
        </p>
      </div>
    </div>

    <button
      type="button"
      class="mt-3 inline-flex min-h-9 items-center gap-1.5 text-[0.8125rem] text-[var(--ink-faint)] transition-colors hover:text-[var(--ink)]"
      :aria-expanded="showWhy"
      @click="showWhy = !showWhy"
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        class="size-3.5 transition-transform duration-300"
        :class="showWhy ? 'rotate-90' : ''"
        aria-hidden="true"
      >
        <path d="m9 6 6 6-6 6" />
      </svg>
      {{ reflect.explanation }}
    </button>

    <p
      v-if="showWhy"
      class="mt-2 border-l border-[var(--line-strong)] pl-3 text-[0.875rem] leading-[1.9] text-[var(--ink-muted)]"
    >
      {{ mistake.explanation }}
    </p>
  </article>
</template>
