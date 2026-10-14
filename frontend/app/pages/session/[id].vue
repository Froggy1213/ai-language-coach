<script setup lang="ts">
/**
 * Legacy route: `/session/:id` was the post-conversation breakdown before it moved
 * to `/reflect/:id`. Kept as a redirect so existing links keep working.
 */
const route = useRoute()
const sessionId = String(route.params.id)

await navigateTo(`/reflect/${sessionId}`, { replace: true, redirectCode: 301 })
</script>

<template>
  <p class="text-[0.9375rem] text-[var(--ink-muted)]">振り返りへ移動しています…</p>
</template>
