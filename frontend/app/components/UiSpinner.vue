<script setup lang="ts">
import { common } from '~/constants/strings'

/**
 * Inline loading indicator.
 *
 * A thin arc rather than a full ring: at 16px a dashed ring reads as a smudge
 * against a dark canvas.
 */
</script>

<template>
  <span
    class="inline-block size-4 shrink-0 animate-spin rounded-full border-[1.5px] border-[var(--line-strong)] border-t-[var(--accent)]"
    role="status"
    :aria-label="common.loading"
  />
</template>
