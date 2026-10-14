<script setup lang="ts">
import type { CheatSheet } from '~/types/graphql'
import { talk } from '~/constants/strings'

/**
 * Lesson reference, collapsed by default.
 *
 * The point of a cheat sheet during a spoken session is that you *may* glance at
 * it — not that it occupies half the screen while you are trying to talk. So it
 * stays a summary row until asked for, and the formula is the only thing visible
 * while closed, because that is the part a learner reaches for mid-sentence.
 */
const props = defineProps<{
  cheatSheet: CheatSheet
  /** Open on mount. Off for the conversation, on for the roadmap. */
  open?: boolean
}>()

const expanded = ref(props.open ?? false)

const examples = computed(() => props.cheatSheet.examples.filter(Boolean))
const pitfalls = computed(() => props.cheatSheet.pitfalls.filter(Boolean))
</script>

<template>
  <div class="overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)]">
    <button
      type="button"
      class="flex min-h-[52px] w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-[var(--surface-strong)]"
      :aria-expanded="expanded"
      @click="expanded = !expanded"
    >
      <span class="shrink-0 text-[0.8125rem] font-medium tracking-[0.06em] text-[var(--ink-muted)]">
        {{ expanded ? talk.cheatSheetClose : talk.cheatSheetOpen }}
      </span>
      <span class="ml-auto truncate font-mono text-[0.8125rem] text-[var(--accent)]">
        {{ cheatSheet.formula }}
      </span>
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        class="size-4 shrink-0 text-[var(--ink-faint)] transition-transform duration-300"
        :class="expanded ? 'rotate-180' : ''"
        aria-hidden="true"
      >
        <path d="m6 9 6 6 6-6" />
      </svg>
    </button>

    <div v-if="expanded" class="space-y-5 border-t border-[var(--line)] px-4 py-4">
      <div class="space-y-1.5">
        <p class="label">Rule</p>
        <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink)]">{{ cheatSheet.rule }}</p>
      </div>

      <div v-if="examples.length" class="space-y-2">
        <p class="label">Examples</p>
        <ul class="space-y-2">
          <li
            v-for="example in examples"
            :key="example"
            class="border-l border-[var(--line-strong)] pl-3 text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]"
          >
            {{ example }}
          </li>
        </ul>
      </div>

      <div v-if="pitfalls.length" class="space-y-2">
        <p class="label">Watch out</p>
        <ul class="space-y-2">
          <li
            v-for="pitfall in pitfalls"
            :key="pitfall"
            class="text-[0.875rem] leading-[1.8] text-[color-mix(in_oklab,var(--color-amber-bright)_82%,var(--ink))]"
          >
            {{ pitfall }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
