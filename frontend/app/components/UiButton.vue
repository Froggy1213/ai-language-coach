<script setup lang="ts">
import {
  type ButtonSize,
  type ButtonVariant,
  BUTTON_BASE_CLASS,
  BUTTON_SIZE_CLASSES,
  BUTTON_VARIANT_CLASSES,
} from '~/utils/button-styles'

/**
 * The one button in the product.
 *
 * Variants are semantic (`primary` / `quiet` / `ghost` / `danger`) so a page never
 * picks a colour by hand. Sizes are all at least 44px tall, which is the minimum
 * comfortable touch target on a phone.
 */
withDefaults(
  defineProps<{
    variant?: ButtonVariant
    size?: ButtonSize
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
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :class="[
      BUTTON_BASE_CLASS,
      'disabled:pointer-events-none disabled:opacity-45',
      BUTTON_VARIANT_CLASSES[variant],
      BUTTON_SIZE_CLASSES[size],
      block ? 'w-full' : '',
    ]"
  >
    <UiSpinner v-if="loading" class="size-4" />
    <slot />
  </button>
</template>
