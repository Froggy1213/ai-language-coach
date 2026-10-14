<script setup lang="ts">
/**
 * The one button in the product.
 *
 * Variants are semantic (`primary` / `quiet` / `ghost` / `danger`) so a page never
 * picks a colour by hand. Sizes are all at least 44px tall, which is the minimum
 * comfortable touch target on a phone.
 */
withDefaults(
  defineProps<{
    variant?: 'primary' | 'quiet' | 'ghost' | 'danger'
    size?: 'sm' | 'md' | 'lg'
    block?: boolean
    disabled?: boolean
    loading?: boolean
    type?: 'button' | 'submit'
  }>(),
  {
    variant: 'quiet',
    size: 'md',
    block: false,
    disabled: false,
    loading: false,
    type: 'button',
  },
)

const VARIANT_CLASSES: Record<'primary' | 'quiet' | 'ghost' | 'danger', string> = {
  primary:
    'bg-[var(--accent)] text-[var(--accent-ink)] hover:brightness-110 active:brightness-95 shadow-[0_2px_10px_-4px_color-mix(in_oklab,var(--accent)_50%,transparent)]',
  quiet:
    'border border-[var(--line-strong)] text-[var(--ink)] hover:border-[var(--accent)] hover:text-[var(--accent)]',
  ghost: 'text-[var(--ink-muted)] hover:bg-[var(--surface)] hover:text-[var(--ink)]',
  danger:
    'border border-[color-mix(in_oklab,var(--color-clay-400)_45%,transparent)] text-[var(--color-clay-400)] hover:bg-[color-mix(in_oklab,var(--color-clay-500)_14%,transparent)]',
}

const SIZE_CLASSES: Record<'sm' | 'md' | 'lg', string> = {
  sm: 'min-h-9 px-3.5 text-[0.8125rem]',
  md: 'min-h-11 px-4 text-[0.9375rem]',
  lg: 'min-h-12 px-5 text-[1rem]',
}
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    class="inline-flex items-center justify-center gap-2 rounded-full font-medium whitespace-nowrap transition-[color,background-color,border-color,filter,transform] duration-200 select-none active:scale-[0.98] disabled:pointer-events-none disabled:opacity-45"
    :class="[VARIANT_CLASSES[variant], SIZE_CLASSES[size], block ? 'w-full' : '']"
  >
    <UiSpinner v-if="loading" class="size-4" />
    <slot />
  </button>
</template>
