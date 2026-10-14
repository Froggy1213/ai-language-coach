<script setup lang="ts">
/**
 * Labelled form field.
 *
 * Exists so the input styling lives in exactly one place — three copies of the
 * same border/focus class string across the auth screens is how a design system
 * starts drifting.
 *
 * The input itself is slotted rather than configured through props, because
 * `v-model` on a slotted input keeps working normally and needs no forwarding.
 */
withDefaults(
  defineProps<{
    label: string
    hint?: string
  }>(),
  { hint: '' },
)
</script>

<template>
  <label class="block space-y-1.5">
    <span class="text-[0.875rem] text-[var(--ink-muted)]">{{ label }}</span>

    <slot>
      <input class="field-input">
    </slot>

    <span v-if="hint" class="block text-[0.75rem] text-[var(--ink-faint)]">{{ hint }}</span>
  </label>
</template>
