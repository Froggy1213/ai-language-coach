<script setup lang="ts">
// The guard sends guests to /login; a signed-in user landing on the root has
// exactly one place to be.
await navigateTo('/roadmap', { replace: true })
</script>

<template>
  <p class="text-slate-400">Открываем роадмап…</p>
</template>
