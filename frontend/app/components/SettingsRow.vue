<script setup lang="ts">
/**
 * One navigation row in a 設定 list.
 *
 * Renders the `<li>` itself so a caller never has to remember that a link inside
 * a `divide-y` list needs a separate wrapper; the chevron is decorative and the
 * whole row is the tap target.
 *
 * `tone="danger"` tints the label clay for the deletion entry. It is a hint, not
 * a confirmation: the guarded flow lives on the destination page.
 */
withDefaults(
  defineProps<{
    to: string
    label: string
    value?: string
    tone?: 'default' | 'danger'
  }>(),
  {
    value: '',
    tone: 'default',
  },
)
</script>

<template>
  <li>
    <NuxtLink
      :to="to"
      class="group flex min-h-14 items-center gap-3 px-4 py-3.5 transition-colors hover:bg-[color-mix(in_oklab,var(--ink)_6%,transparent)]"
    >
      <span
        class="min-w-0 flex-1 text-[0.9375rem]"
        :class="tone === 'danger' ? 'text-[var(--color-clay-400)]' : 'text-[var(--ink)]'"
      >
        {{ label }}
      </span>

      <span v-if="value" class="max-w-[58%] truncate text-right text-[0.8125rem] text-[var(--ink-faint)]">
        {{ value }}
      </span>

      <svg
        aria-hidden="true"
        viewBox="0 0 16 16"
        class="size-4 shrink-0 text-[var(--ink-faint)] transition-transform duration-200 ease-[var(--ease-out-soft)] group-hover:translate-x-0.5"
      >
        <path
          d="m6 3.5 4.5 4.5L6 12.5"
          fill="none"
          stroke="currentColor"
          stroke-width="1.5"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
    </NuxtLink>
  </li>
</template>
