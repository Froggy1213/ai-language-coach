<script setup lang="ts">
/**
 * Inline notice for errors, warnings and confirmations.
 *
 * Tones are semantic rather than caller-supplied colours, and each one is a wash
 * of its own hue over the surface rather than a saturated block — an error should
 * be unmissable, not loud enough to make the rest of the page unreadable.
 */
withDefaults(
  defineProps<{
    message?: string | null
    tone?: 'error' | 'warn' | 'info' | 'success'
    /** Optional bold first line above the body. */
    title?: string
    /** Action row rendered under the text. */
    actions?: boolean
  }>(),
  {
    message: null,
    tone: 'error',
    title: '',
    actions: false,
  },
)

const TONE_CLASSES: Record<'error' | 'warn' | 'info' | 'success', string> = {
  error: 'border-[color-mix(in_oklab,var(--color-clay-400)_38%,transparent)] bg-[color-mix(in_oklab,var(--color-clay-500)_12%,transparent)] text-[var(--color-clay-400)]',
  warn: 'border-[color-mix(in_oklab,var(--color-amber-bright)_36%,transparent)] bg-[color-mix(in_oklab,var(--color-amber-bright)_12%,transparent)] text-[var(--color-amber-bright)]',
  info: 'border-[var(--line)] bg-[var(--surface)] text-[var(--ink-muted)]',
  success: 'border-[color-mix(in_oklab,var(--color-sage-400)_36%,transparent)] bg-[color-mix(in_oklab,var(--color-sage-500)_12%,transparent)] text-[var(--color-sage-400)]',
}
</script>

<template>
  <div
    v-if="message || $slots.default"
    class="rounded-2xl border px-4 py-3 text-[0.9375rem] leading-relaxed"
    :class="TONE_CLASSES[tone]"
    role="status"
  >
    <p v-if="title" class="mb-1 font-semibold">{{ title }}</p>
    <slot>{{ message }}</slot>
    <div v-if="actions || $slots.actions" class="mt-3 flex flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
