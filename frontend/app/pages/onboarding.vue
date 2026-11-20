<script setup lang="ts">
import { redirects } from '~/constants/strings'

/**
 * Legacy route: the level assessment lived at `/onboarding` before the screens were
 * split one-job-per-page. Kept as a redirect so existing links and bookmarks keep
 * working.
 */
await navigateTo('/level', { replace: true, redirectCode: 301 })
</script>

<template>
  <p class="text-[0.9375rem] text-[var(--ink-muted)]">{{ redirects.toLevel }}</p>
</template>
