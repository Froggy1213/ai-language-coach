<script setup lang="ts">
/**
 * Page heading.
 *
 * One component so every page has the same rhythm: a small tracked eyebrow, a
 * display-serif title, and one optional line of orientation. Pages that are a
 * single task (the conversation) opt out entirely — a title above an orb is
 * chrome competing with the only thing on screen.
 */
withDefaults(
  defineProps<{
    eyebrow?: string
    title: string
    lead?: string
    /** Display-serif title. Off for dense screens where the sans reads tighter. */
    display?: boolean
  }>(),
  {
    eyebrow: '',
    lead: '',
    display: true,
  },
)
</script>

<template>
  <header class="space-y-2">
    <p v-if="eyebrow" class="label">{{ eyebrow }}</p>
    <h1
      class="text-[1.625rem] leading-[1.3] font-semibold tracking-[0.02em] text-[var(--ink)] sm:text-[1.875rem]"
      :class="display ? 'display' : ''"
    >
      {{ title }}
    </h1>
    <p v-if="lead" class="max-w-[46ch] text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">
      {{ lead }}
    </p>
    <slot />
  </header>
</template>
