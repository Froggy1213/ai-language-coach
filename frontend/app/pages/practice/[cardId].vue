<script setup lang="ts">
/**
 * Legacy route: `/practice/:cardId` was where a lesson was spoken before the
 * conversation screen existed. It is kept as a redirect so existing links and
 * bookmarks keep working.
 */
const route = useRoute()
const cardId = String(route.params.cardId)

await navigateTo(`/talk/${cardId}`, { replace: true, redirectCode: 301 })
</script>

<template>
  <p class="text-[0.9375rem] text-[var(--ink-muted)]">会話画面へ移動しています…</p>
</template>
