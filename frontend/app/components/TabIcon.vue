<script setup lang="ts">
/**
 * Tab bar glyphs.
 *
 * Hand-drawn on a 24px grid at a 1.5 stroke so they stay legible at 22px and read
 * as one family. No icon font, no runtime dependency, and `currentColor` means the
 * active/inactive states need no extra styling.
 */
defineProps<{
  name: 'today' | 'roadmap' | 'talk' | 'review' | 'settings'
  filled?: boolean
}>()
</script>

<template>
  <svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.5"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="size-[22px]"
    aria-hidden="true"
  >
    <template v-if="name === 'today'">
      <path d="M12 3.4c2.6 2.2 4 4.6 4 7a4 4 0 0 1-8 0c0-2.4 1.4-4.8 4-7Z" :fill="filled ? 'currentColor' : 'none'" />
      <path d="M4.8 15.4h14.4M6.6 19.2h10.8" />
    </template>

    <template v-else-if="name === 'roadmap'">
      <path d="M6 3.6v16.8" />
      <circle cx="6" cy="8.2" r="2.4" />
      <path d="M6 8.2h6.2a3 3 0 0 1 0 6H8.6a3 3 0 0 0 0 6H18" />
      <circle cx="6" cy="20.4" r="0.1" />
    </template>

    <template v-else-if="name === 'talk'">
      <path
        d="M12 3.8l1.28 3.66a5 5 0 0 0 3 3L20 11.7l-3.72 1.28a5 5 0 0 0-3 3L12 19.6l-1.28-3.66a5 5 0 0 0-3-3L4 11.7l3.72-1.24a5 5 0 0 0 3-3Z"
        :fill="filled ? 'currentColor' : 'none'"
      />
    </template>

    <template v-else-if="name === 'review'">
      <path d="M20 12a8 8 0 1 1-2.4-5.7" />
      <path d="M20 4.4V9h-4.6" />
      <path d="M12 8.4V12l2.6 1.6" />
    </template>

    <template v-else>
      <circle cx="12" cy="12" r="2.9" />
      <path
        d="M12 2.8v2.3M12 18.9v2.3M4.6 4.6l1.7 1.7M17.7 17.7l1.7 1.7M2.8 12h2.3M18.9 12h2.3M4.6 19.4l1.7-1.7M17.7 6.3l1.7-1.7"
      />
    </template>
  </svg>
</template>
