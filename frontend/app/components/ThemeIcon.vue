<script setup lang="ts">
import type { ThemeName } from '~/composables/useTheme'

/**
 * Sun/moon mark for the theme toggle. Drawn as inline SVG rather than an icon
 * font or an emoji so it inherits `currentColor` and stays crisp at small sizes.
 */
defineProps<{ theme: ThemeName }>()
</script>

<template>
  <svg
    v-if="theme === 'dark'"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="size-[18px]"
    aria-hidden="true"
  >
    <circle cx="12" cy="12" r="4.2" />
    <path d="M12 2.6v2.2M12 19.2v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.6 12h2.2M19.2 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6" />
  </svg>
  <svg
    v-else
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="size-[18px]"
    aria-hidden="true"
  >
    <path d="M20.5 14.6A8.6 8.6 0 0 1 9.4 3.5a8.6 8.6 0 1 0 11.1 11.1Z" />
  </svg>
</template>
