<script setup lang="ts">
import type { LessonCard, LessonCardStatus } from '~/types/graphql'

const props = defineProps<{ card: LessonCard }>()

const STATUS_LABELS: Record<LessonCardStatus, string> = {
  locked: 'Закрыто',
  ready: 'Сейчас',
  completed: 'Пройдено',
}

const STATUS_CLASSES: Record<LessonCardStatus, string> = {
  locked: 'border-slate-700 text-slate-400',
  ready: 'border-sky-500/60 bg-sky-500/10 text-sky-300',
  completed: 'border-emerald-500/50 bg-emerald-500/10 text-emerald-300',
}

const statusLabel = computed(() => STATUS_LABELS[props.card.status])
const statusClass = computed(() => STATUS_CLASSES[props.card.status])
</script>

<template>
  <article
    class="rounded-xl border bg-slate-900/60 p-5"
    :class="card.status === 'ready' ? 'border-sky-500/50' : 'border-slate-800'"
  >
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="text-xs uppercase tracking-wide text-slate-500">
          Урок {{ card.orderIndex }} · {{ card.grammarPoint.category }}
        </p>
        <h2 class="text-lg font-medium text-slate-100">
          {{ card.grammarPoint.title }}
        </h2>
      </div>
      <span class="rounded-full border px-3 py-1 text-xs" :class="statusClass">{{ statusLabel }}</span>
    </header>

    <p class="mt-4 rounded-lg bg-slate-950/60 px-3 py-2 text-sm text-slate-300">
      <span class="text-slate-500">Задание: </span>{{ card.practicePrompt }}
    </p>

    <div class="mt-4 border-t border-slate-800 pt-4">
      <CheatSheetPanel :cheat-sheet="card.cheatSheet" />
    </div>
  </article>
</template>
