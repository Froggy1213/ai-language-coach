<script setup lang="ts">
/**
 * Legacy route: the level assessment lived at `/onboarding` before the screens were
 * split one-job-per-page. Kept as a redirect so existing links and bookmarks keep
 * working.
 */
await navigateTo('/level', { replace: true, redirectCode: 301 })
</script>

<template>
  <p class="text-[0.9375rem] text-[var(--ink-muted)]">レベル判定へ移動しています…</p>
</template>
