<script setup lang="ts">
import type { CheatSheet } from '~/types/graphql'

defineProps<{ cheatSheet: CheatSheet }>()
</script>

<template>
  <div class="space-y-4 text-sm">
    <div>
      <p class="text-xs uppercase tracking-wide text-slate-500">Правило</p>
      <p class="text-slate-100">{{ cheatSheet.rule }}</p>
    </div>

    <div>
      <p class="text-xs uppercase tracking-wide text-slate-500">Формула</p>
      <p class="font-mono text-sky-300">{{ cheatSheet.formula }}</p>
    </div>

    <div>
      <p class="text-xs uppercase tracking-wide text-slate-500">Примеры</p>
      <ul class="list-disc space-y-1 pl-5 text-slate-200">
        <li v-for="example in cheatSheet.examples" :key="example">{{ example }}</li>
      </ul>
    </div>

    <div>
      <p class="text-xs uppercase tracking-wide text-slate-500">Частые ошибки</p>
      <ul class="list-disc space-y-1 pl-5 text-amber-200/90">
        <li v-for="pitfall in cheatSheet.pitfalls" :key="pitfall">{{ pitfall }}</li>
      </ul>
    </div>
  </div>
</template>
