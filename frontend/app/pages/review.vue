<script setup lang="ts">
const {
  dueReviews,
  recurringMistakes,
  loading,
  error,
  submittingId,
  itemErrors,
  submitReview,
  refresh,
} = useReviews()

const refreshing = ref(false)

async function handleRefresh(): Promise<void> {
  refreshing.value = true
  try {
    await refresh()
  } finally {
    refreshing.value = false
  }
}

const grades = [
  {
    quality: 0,
    label: '0 · Не помню',
    desc: 'Полный провал',
    btnClass:
      'border-rose-500/40 bg-rose-500/10 text-rose-300 hover:bg-rose-500/20 active:bg-rose-500/30',
  },
  {
    quality: 1,
    label: '1 · Забыл',
    desc: 'Грубая ошибка',
    btnClass:
      'border-rose-500/30 bg-rose-500/5 text-rose-300/90 hover:bg-rose-500/15 active:bg-rose-500/25',
  },
  {
    quality: 2,
    label: '2 · С трудом',
    desc: 'Едва вспомнил',
    btnClass:
      'border-amber-500/30 bg-amber-500/10 text-amber-300 hover:bg-amber-500/20 active:bg-amber-500/30',
  },
  {
    quality: 3,
    label: '3 · Вспомнил',
    desc: 'Были сомнения',
    btnClass:
      'border-yellow-500/30 bg-yellow-500/10 text-yellow-300 hover:bg-yellow-500/20 active:bg-yellow-500/30',
  },
  {
    quality: 4,
    label: '4 · Хорошо',
    desc: 'Уверенный ответ',
    btnClass:
      'border-sky-500/30 bg-sky-500/10 text-sky-300 hover:bg-sky-500/20 active:bg-sky-500/30',
  },
  {
    quality: 5,
    label: '5 · Легко',
    desc: 'Без раздумий',
    btnClass:
      'border-emerald-500/40 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20 active:bg-emerald-500/30',
  },
]
</script>

<template>
  <section class="space-y-8">
    <!-- Header -->
    <header class="flex flex-wrap items-center justify-between gap-4">
      <div class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-100">Очередь повторений</h1>
        <p class="text-sm text-slate-400">
          Интервальное повторение (SM-2) по ошибкам из разговорных сессий
        </p>
      </div>

      <div class="flex items-center gap-3">
        <button
          type="button"
          :disabled="loading || refreshing"
          class="rounded-lg border border-slate-700 bg-slate-900/60 px-3.5 py-1.5 text-xs font-medium text-slate-300 transition hover:border-slate-500 hover:text-white disabled:opacity-50"
          @click="handleRefresh"
        >
          {{ refreshing ? 'Обновляем…' : 'Обновить' }}
        </button>

        <NuxtLink
          to="/roadmap"
          class="inline-flex items-center gap-1 text-sm text-slate-400 transition hover:text-slate-200"
        >
          ← К роадмапу
        </NuxtLink>
      </div>
    </header>

    <!-- Error Banner -->
    <div
      v-if="error"
      class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-rose-200"
    >
      <p>{{ error }}</p>
      <button
        type="button"
        :disabled="refreshing"
        class="shrink-0 rounded-lg border border-rose-500/60 bg-rose-500/20 px-3 py-1.5 text-xs font-medium text-rose-200 hover:bg-rose-500/30 disabled:opacity-50"
        @click="handleRefresh"
      >
        Повторить попытку
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading && dueReviews.length === 0" class="space-y-3">
      <div class="flex items-center gap-3 text-slate-400">
        <UiSpinner />
        <p>Загружаем очередь повторений…</p>
      </div>
    </div>

    <!-- Empty State: Friendly Message when Nothing is Due -->
    <div
      v-else-if="dueReviews.length === 0"
      class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-6 text-center sm:text-left"
    >
      <div class="flex flex-col sm:flex-row items-center gap-4">
        <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-sky-500/10 text-sky-400 text-2xl">
          ✨
        </div>
        <div class="space-y-1">
          <h2 class="text-lg font-semibold text-slate-100">Все карточки повторены!</h2>
          <p class="text-sm text-slate-400">
            На сегодня нет правил, требующих повторения. Карточки появятся здесь автоматически согласно интервалам SM-2.
          </p>
        </div>
      </div>
      <div class="pt-2">
        <NuxtLink
          to="/roadmap"
          class="inline-block rounded-lg bg-sky-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-sky-400"
        >
          Перейти к роадмапу
        </NuxtLink>
      </div>
    </div>

    <!-- Queue List -->
    <div v-else class="space-y-5">
      <header class="flex items-center justify-between">
        <h2 class="text-base font-medium text-slate-200">
          К повторению: {{ dueReviews.length }}
        </h2>
      </header>

      <div class="space-y-4">
        <article
          v-for="item in dueReviews"
          :key="item.id"
          class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-5 transition"
        >
          <!-- Item Header & Metadata -->
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-wide text-slate-500">
                {{ item.grammarPoint.category }}
              </p>
              <h3 class="text-lg font-medium text-slate-100">
                {{ item.grammarPoint.title }}
              </h3>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
              <span class="rounded-full border border-slate-700 bg-slate-800/80 px-2.5 py-1 text-slate-300">
                Интервал: {{ item.intervalDays }} {{ pluralize(item.intervalDays, ['день', 'дня', 'дней']) }}
              </span>
              <span class="rounded-full border border-slate-700 bg-slate-800/80 px-2.5 py-1 text-slate-300">
                Ease: {{ item.easeFactor.toFixed(2) }}
              </span>
              <span class="rounded-full border border-slate-700 bg-slate-800/80 px-2.5 py-1 text-slate-300">
                {{ item.repetitionNumber }} {{ pluralize(item.repetitionNumber, ['повторение', 'повторения', 'повторений']) }}
              </span>
              <span class="rounded-full border border-sky-500/30 bg-sky-500/10 px-2.5 py-1 text-sky-300">
                {{ formatReviewDate(item.nextReviewAt) }}
              </span>
            </div>
          </div>

          <!-- Quality Grading Instructions -->
          <div class="border-t border-slate-800/80 pt-3 space-y-2">
            <p class="text-xs font-medium text-slate-400">
              Оцените, насколько хорошо вы помните это правило:
            </p>

            <!-- 0..5 Quality Buttons Grid -->
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
              <button
                v-for="grade in grades"
                :key="grade.quality"
                type="button"
                :disabled="submittingId === item.grammarPoint.id"
                class="flex flex-col items-center justify-center rounded-lg border px-3 py-2 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50"
                :class="grade.btnClass"
                @click="submitReview(item.grammarPoint.id, grade.quality)"
              >
                <span class="font-semibold">{{ grade.label }}</span>
                <span class="text-[10px] opacity-80 mt-0.5">{{ grade.desc }}</span>
              </button>
            </div>

            <!-- Per-Item Submission Error -->
            <p
              v-if="itemErrors[item.grammarPoint.id]"
              class="text-xs text-rose-300 pt-1"
            >
              {{ itemErrors[item.grammarPoint.id] }}
            </p>
          </div>
        </article>
      </div>
    </div>

    <!-- Recurring Mistakes Block (Fed by recurringMistakes query, hidden when unavailable) -->
    <section
      v-if="recurringMistakes && recurringMistakes.length > 0"
      class="space-y-4 pt-6 border-t border-slate-800"
    >
      <header class="space-y-1">
        <h2 class="text-xl font-semibold text-slate-100">Повторяющиеся ошибки</h2>
        <p class="text-sm text-slate-400">
          Правила, в которых вы чаще всего ошибаетесь во время диалогов
        </p>
      </header>

      <div class="grid gap-4 sm:grid-cols-2">
        <article
          v-for="rec in recurringMistakes"
          :key="rec.grammarPoint.id"
          class="space-y-3 rounded-xl border border-amber-500/30 bg-slate-900/60 p-5"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-wide text-slate-500">
                {{ rec.grammarPoint.category }}
              </p>
              <h3 class="text-base font-medium text-slate-100">
                {{ rec.grammarPoint.title }}
              </h3>
            </div>
            <span class="shrink-0 rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-0.5 text-xs font-medium text-amber-300">
              Повторяется в {{ rec.sessionCount }} {{ pluralize(rec.sessionCount, ['занятии', 'занятиях', 'занятиях']) }}
            </span>
          </div>

          <div class="flex flex-wrap items-center gap-3 border-t border-slate-800/80 pt-2.5 text-xs text-slate-400">
            <span>
              Всего: <strong class="text-slate-200">{{ rec.mistakeCount }} {{ pluralize(rec.mistakeCount, ['ошибка', 'ошибки', 'ошибок']) }}</strong>
            </span>
            <span v-if="rec.lastMistakeAt">
              · Последняя: {{ formatReviewDate(rec.lastMistakeAt) }}
            </span>
          </div>
        </article>
      </div>
    </section>
  </section>
</template>
