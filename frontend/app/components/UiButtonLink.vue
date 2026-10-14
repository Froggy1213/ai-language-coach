<script setup lang="ts">
/**
 * `UiButton` rendered as a link.
 *
 * NuxtLink is an anchor, not a button, so it cannot reuse `UiButton` directly —
 * and wrapping an anchor inside a button produces invalid nested-interactive HTML
 * that browsers silently repair in surprising ways. This mirrors `UiButton`'s
 * variant and size classes instead.
 */
withDefaults(
  defineProps<{
    to: string
    variant?: 'primary' | 'quiet' | 'ghost'
    size?: 'sm' | 'md' | 'lg'
    block?: boolean
  }>(),
  {
    variant: 'quiet',
    size: 'md',
    block: false,
  },
)

const VARIANT_CLASSES: Record<'primary' | 'quiet' | 'ghost', string> = {
  primary:
    'bg-[var(--accent)] text-[var(--accent-ink)] hover:brightness-110 active:brightness-95 shadow-[0_2px_10px_-4px_color-mix(in_oklab,var(--accent)_50%,transparent)]',
  quiet:
    'border border-[var(--line-strong)] text-[var(--ink)] hover:border-[var(--accent)] hover:text-[var(--accent)]',
  ghost: 'text-[var(--ink-muted)] hover:bg-[var(--surface)] hover:text-[var(--ink)]',
}

const SIZE_CLASSES: Record<'sm' | 'md' | 'lg', string> = {
  sm: 'min-h-9 px-3.5 text-[0.8125rem]',
  md: 'min-h-11 px-4 text-[0.9375rem]',
  lg: 'min-h-12 px-5 text-[1rem]',
}
</script>

<template>
  <NuxtLink
    :to="to"
    class="inline-flex items-center justify-center gap-2 rounded-full font-medium whitespace-nowrap transition-[color,background-color,border-color,filter,transform] duration-200 select-none active:scale-[0.98]"
    :class="[VARIANT_CLASSES[variant], SIZE_CLASSES[size], block ? 'w-full' : '']"
  >
    <slot />
  </NuxtLink>
</template>
