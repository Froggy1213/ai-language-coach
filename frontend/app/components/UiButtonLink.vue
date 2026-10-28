<script setup lang="ts">
import {
  type ButtonSize,
  type ButtonVariant,
  BUTTON_BASE_CLASS,
  BUTTON_SIZE_CLASSES,
  BUTTON_VARIANT_CLASSES,
} from '~/utils/button-styles'

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
    variant?: ButtonVariant
    size?: ButtonSize
    block?: boolean
  }>(),
  {
    variant: 'quiet',
    size: 'md',
    block: false,
  },
)
</script>

<template>
  <NuxtLink
    :to="to"
    :class="[
      BUTTON_BASE_CLASS,
      BUTTON_VARIANT_CLASSES[variant],
      BUTTON_SIZE_CLASSES[size],
      block ? 'w-full' : '',
    ]"
  >
    <slot />
  </NuxtLink>
</template>
