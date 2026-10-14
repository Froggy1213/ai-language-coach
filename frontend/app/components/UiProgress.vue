<script setup lang="ts">
/**
 * Progress toward the end of the roadmap.
 *
 * A hairline rule rather than a chunky bar: the number next to it carries the
 * information, and the rule only has to make it glanceable.
 */
const props = withDefaults(
  defineProps<{
    value: number
    total: number
    label?: string
  }>(),
  { label: '' },
)

const percent = computed(() => {
  if (props.total <= 0) {
    return 0
  }

  return Math.round((Math.min(props.value, props.total) / props.total) * 100)
})
</script>

<template>
  <div class="space-y-2">
    <div v-if="label" class="flex items-baseline justify-between text-[0.8125rem]">
      <span class="text-[var(--ink-muted)]">{{ label }}</span>
      <span class="tnum text-[var(--ink)]">{{ value }}<span class="text-[var(--ink-faint)]"> / {{ total }}</span></span>
    </div>
    <div class="h-px w-full overflow-hidden rounded-full bg-[var(--line)]">
      <div
        class="h-full rounded-full bg-[var(--accent)] transition-[width] duration-700 ease-[cubic-bezier(0.22,1,0.36,1)]"
        :style="{ width: `${percent}%` }"
      />
    </div>
  </div>
</template>
