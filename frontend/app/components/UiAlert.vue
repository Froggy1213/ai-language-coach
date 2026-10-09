<script setup lang="ts">
/**
 * Generic alert banner for user-facing error messages.
 *
 * Padding is a prop rather than a caller-supplied class: Tailwind emits `p-*`
 * before `px-*`/`py-*`, so a `class="p-3"` on top of the default `px-3 py-2`
 * would silently lose and change the rendered box.
 */
interface Props {
  message?: string | null
  padding?: 'sm' | 'md' | 'lg'
}

const props = withDefaults(defineProps<Props>(), {
  message: null,
  padding: 'sm',
})

const PADDING_CLASSES: Record<NonNullable<Props['padding']>, string> = {
  sm: 'px-3 py-2',
  md: 'p-3',
  lg: 'p-4',
}
</script>

<template>
  <div
    v-if="props.message || $slots.default"
    class="rounded-lg border border-rose-500/40 bg-rose-500/10 text-sm text-rose-200"
    :class="PADDING_CLASSES[props.padding]"
  >
    <slot>{{ props.message }}</slot>
  </div>
</template>
