<script setup lang="ts">
import { talk } from '~/constants/strings'

/**
 * Scenario phrases, open by default.
 *
 * A cheat sheet is reference material a learner *may* consult, so it stays
 * collapsed; a scenario's useful phrases are the support the mode promises, and
 * there are only three to five of them. They start visible for the same reason
 * the orb is the primary control: the learner should not have to find the help
 * mid-sentence. The row still collapses, so the panel never fights the orb for
 * the screen.
 */
const props = defineProps<{
  phrases: readonly string[]
  /** Open on mount. */
  open?: boolean
}>()

const expanded = ref(props.open ?? false)

const items = computed(() => props.phrases.filter(Boolean))
</script>

<template>
  <div
    v-if="items.length"
    class="overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)]"
  >
    <button
      type="button"
      class="flex min-h-[52px] w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-[var(--surface-strong)]"
      :aria-expanded="expanded"
      @click="expanded = !expanded"
    >
      <span class="shrink-0 text-[0.8125rem] font-medium tracking-[0.06em] text-[var(--ink-muted)]">
        {{ talk.usefulPhrases }}
      </span>
      <span class="tnum ml-auto text-[0.8125rem] text-[var(--ink-faint)]">{{ items.length }}</span>
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

    <ul v-if="expanded" class="space-y-2.5 border-t border-[var(--line)] px-4 py-4">
      <li
        v-for="phrase in items"
        :key="phrase"
        class="border-l border-[color-mix(in_oklab,var(--accent)_45%,transparent)] pl-3 text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]"
      >
        {{ phrase }}
      </li>
    </ul>
  </div>
</template>
