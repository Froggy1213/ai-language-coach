<script setup lang="ts">
import type { VoiceSessionStatus } from '~/types/graphql'

const route = useRoute()
const sessionId = computed(() => String(route.params.id))

const { session, mistakes, loading, error, analyzing, refresh } = useSessionFeedback(sessionId)

const refreshing = ref(false)
const manualDone = ref(false)
const checkedManually = ref(false)

const isAnalyzing = computed(() => analyzing.value && !manualDone.value)

async function handleRefresh(): Promise<void> {
  refreshing.value = true
  try {
    await refresh()
    checkedManually.value = true
  } finally {
    refreshing.value = false
  }
}

interface StatusMeta {
  label: string
  badgeClass: string
}

function getStatusMeta(status: VoiceSessionStatus | undefined): StatusMeta {
  switch (status) {
    case 'pending':
      return {
        label: 'Ожидает начала',
        badgeClass: 'border-slate-500/40 bg-slate-500/10 text-slate-300',
      }
    case 'active':
      return {
        label: 'Активна',
        badgeClass: 'border-sky-500/40 bg-sky-500/10 text-sky-300',
      }
    case 'completed':
      return {
        label: 'Завершена',
        badgeClass: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
      }
    case 'failed':
      return {
        label: 'Сбой сессии',
        badgeClass: 'border-rose-500/40 bg-rose-500/10 text-rose-300',
      }
    case 'abandoned':
      return {
        label: 'Прервана',
        badgeClass: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
      }
    default:
      return {
        label: status ?? 'Неизвестно',
        badgeClass: 'border-slate-700 bg-slate-800 text-slate-400',
      }
  }
}

function formatFailReason(reason: string | null | undefined): string {
  if (!reason) {
    return 'Сессия завершилась непредвиденной ошибкой.'
  }
  const map: Record<string, string> = {
    voice_fleet_busy: 'Воркер голосового агента был занят или не ответил вовремя.',
    voice_start_failed: 'Не удалось инициализировать голосовую комнату на сервере.',
    livekit_server_shutdown: 'Сервер голосовой связи был перезагружен во время звонка.',
    livekit_room_open_failed: 'Не удалось открыть комнату LiveKit.',
    stt_failed: 'Сбой распознавания речи (STT) во время диалога.',
    tts_failed: 'Сбой синтеза речи (TTS) во время диалога.',
    llm_failed: 'Сбой диалоговой языковой модели во время разговора.',
    agent_error: 'Внутренняя ошибка голосового агента.',
  }
  return map[reason] ?? `Причина ошибки: ${reason}`
}

interface MistakeGroup {
  grammarPointId: string
  title: string
  category: string
  mistakes: typeof mistakes.value
}

const groupedMistakes = computed<MistakeGroup[]>(() => {
  const groups = new Map<string, MistakeGroup>()

  for (const m of mistakes.value) {
    const gp = m.grammarPoint
    const key = gp?.id || gp?.title || 'other'
    if (!groups.has(key)) {
      groups.set(key, {
        grammarPointId: gp?.id ?? '',
        title: gp?.title ?? 'Грамматика',
        category: gp?.category ?? 'Общее',
        mistakes: [],
      })
    }
    groups.get(key)!.mistakes.push(m)
  }

  return Array.from(groups.values())
})
</script>

<template>
  <section class="space-y-8">
    <!-- Initial Loading State -->
    <div v-if="loading && !session" class="space-y-3">
      <div class="flex items-center gap-3 text-slate-400">
        <UiSpinner />
        <p>Загружаем данные сессии…</p>
      </div>
    </div>

    <!-- Not Found State for Bad / Foreign ID -->
    <div
      v-else-if="!loading && !session"
      class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-6"
    >
      <header class="space-y-1">
        <h1 class="text-xl font-semibold text-slate-100">Сессия не найдена</h1>
        <p class="text-sm text-slate-400">
          Сессия с указанным идентификатором не найдена или принадлежит другому аккаунту.
        </p>
      </header>
      <div class="flex flex-wrap items-center gap-3 pt-2">
        <NuxtLink
          to="/roadmap"
          class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-sky-400"
        >
          Вернуться к роадмапу
        </NuxtLink>
        <NuxtLink
          to="/review"
          class="rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-slate-500"
        >
          Очередь повторений
        </NuxtLink>
      </div>
    </div>

    <!-- Active Session View -->
    <template v-else-if="session">
      <!-- Breadcrumb / Navigation Bar -->
      <div class="flex flex-wrap items-center justify-between gap-4">
        <NuxtLink
          to="/roadmap"
          class="inline-flex items-center gap-1.5 text-sm text-slate-400 transition hover:text-slate-200"
        >
          <span>←</span>
          <span>К роадмапу</span>
        </NuxtLink>

        <NuxtLink
          to="/review"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-sky-400 transition hover:text-sky-300"
        >
          <span>К повторению</span>
          <span>→</span>
        </NuxtLink>
      </div>

      <!-- Summary Block -->
      <div class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="space-y-1">
            <p v-if="session.lessonCard?.grammarPoint?.category" class="text-xs uppercase tracking-wide text-slate-500">
              Урок {{ session.lessonCard?.orderIndex }} · {{ session.lessonCard?.grammarPoint?.category }}
            </p>
            <h1 class="text-2xl font-semibold text-slate-100">
              {{ session.lessonCard?.grammarPoint?.title ?? 'Разбор голосовой сессии' }}
            </h1>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <span
              class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium"
              :class="getStatusMeta(session.status).badgeClass"
            >
              {{ getStatusMeta(session.status).label }}
            </span>

            <span
              v-if="formatDuration(session.durationSec ?? 0)"
              class="rounded-full border border-slate-800 bg-slate-800/80 px-3 py-1 text-xs text-slate-300"
            >
              Длительность: {{ formatDuration(session.durationSec ?? 0) }}
            </span>
          </div>
        </div>

        <!-- Practice Prompt Preview -->
        <div v-if="session.lessonCard?.practicePrompt" class="rounded-lg border border-slate-800 bg-slate-950/40 p-3">
          <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Тема разговора</p>
          <p class="mt-1 text-sm text-slate-300">{{ session.lessonCard.practicePrompt }}</p>
        </div>

        <!-- Session Failure Reason Alert -->
        <UiAlert
          v-if="session.status === 'failed' || session.failReason"
          padding="md"
        >
          <p class="font-medium text-rose-300">Причина сбоя:</p>
          <p class="mt-0.5 text-rose-100/90">{{ formatFailReason(session.failReason) }}</p>
        </UiAlert>
      </div>

      <!-- Handshake / Request Error Banner -->
      <div
        v-if="error"
        class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-rose-200"
      >
        <p class="leading-relaxed">{{ error }}</p>
        <button
          type="button"
          :disabled="refreshing"
          class="shrink-0 rounded-lg border border-rose-500/60 bg-rose-500/20 px-3 py-1.5 text-xs font-medium text-rose-200 hover:bg-rose-500/30 disabled:opacity-50"
          @click="handleRefresh"
        >
          {{ refreshing ? 'Проверяем…' : 'Повторить попытку' }}
        </button>
      </div>

      <!-- Analyzing State (driven by subscription, with manual check fallback) -->
      <div
        v-if="isAnalyzing"
        class="space-y-4 rounded-xl border border-sky-500/30 bg-sky-500/5 p-6"
      >
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div class="flex items-start sm:items-center gap-3">
            <span class="inline-block size-5 shrink-0 animate-spin rounded-full border-2 border-sky-400/30 border-t-sky-400 mt-0.5 sm:mt-0" />
            <div>
              <h2 class="font-medium text-sky-200">Разбираем ваши ошибки…</h2>
              <p class="text-sm text-sky-300/80">
                Идёт транскрипция и грамматический анализ реплик. Результат отобразится автоматически.
              </p>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              :disabled="refreshing"
              class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-sky-400 disabled:opacity-50"
              @click="handleRefresh"
            >
              {{ refreshing ? 'Проверяем…' : 'Проверить сейчас' }}
            </button>
            <button
              v-if="checkedManually"
              type="button"
              class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 transition hover:border-slate-500"
              @click="manualDone = true"
            >
              Ошибок не было
            </button>
          </div>
        </div>
      </div>

      <!-- Mistakes Section (shown when analysis is not running) -->
      <div v-else class="space-y-6">
        <!-- Honest Empty State when Analysis Found Nothing -->
        <div
          v-if="mistakes.length === 0"
          class="space-y-4 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-6"
        >
          <div class="flex flex-col sm:flex-row items-center gap-4 text-center sm:text-left">
            <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-400 text-xl font-bold">
              ✓
            </div>
            <div class="space-y-1">
              <h2 class="text-lg font-semibold text-emerald-300">Ошибок не обнаружено</h2>
              <p class="text-sm text-slate-300">
                В этой разговорной сессии вы говорили грамматически верно, либо не было распознанных реплик с ошибками. Отличная работа!
              </p>
            </div>
          </div>
        </div>

        <!-- Mistakes List Grouped by Grammar Point -->
        <template v-else>
          <header class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-100">
              Найденные ошибки ({{ mistakes.length }} {{ pluralize(mistakes.length, ['ошибка', 'ошибки', 'ошибок']) }})
            </h2>
          </header>

          <div class="space-y-5">
            <article
              v-for="group in groupedMistakes"
              :key="group.grammarPointId"
              class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-5"
            >
              <!-- Grammar Point Header -->
              <div class="flex items-center justify-between border-b border-slate-800/80 pb-3">
                <div>
                  <p class="text-xs uppercase tracking-wide text-slate-500">{{ group.category }}</p>
                  <h3 class="text-base font-medium text-slate-100">{{ group.title }}</h3>
                </div>
                <span class="rounded-full bg-rose-500/10 px-2.5 py-0.5 text-xs text-rose-300 border border-rose-500/30">
                  {{ group.mistakes.length }} {{ pluralize(group.mistakes.length, ['ошибка', 'ошибки', 'ошибок']) }}
                </span>
              </div>

              <!-- List of Mistakes in this Grammar Point -->
              <div class="space-y-4">
                <div
                  v-for="mistake in group.mistakes"
                  :key="mistake.id"
                  class="space-y-2.5 rounded-lg border border-slate-800/60 bg-slate-950/40 p-4"
                >
                  <!-- What user said -->
                  <div class="space-y-1">
                    <span class="text-xs font-medium text-slate-400">Вы сказали:</span>
                    <p class="text-sm text-rose-200 line-through decoration-rose-500/70">
                      «{{ mistake.userUtterance }}»
                    </p>
                  </div>

                  <!-- Correction -->
                  <div class="space-y-1">
                    <span class="text-xs font-medium text-slate-400">Как правильно:</span>
                    <p class="text-sm font-medium text-emerald-300">
                      {{ mistake.correction }}
                    </p>
                  </div>

                  <!-- Explanation -->
                  <div class="space-y-1 pt-1 border-t border-slate-800/60">
                    <span class="text-xs font-medium text-slate-500">Объяснение:</span>
                    <p class="text-xs leading-relaxed text-slate-300">
                      {{ mistake.explanation }}
                    </p>
                  </div>
                </div>
              </div>
            </article>
          </div>
        </template>
      </div>

      <!-- Action Footer Links Onward -->
      <footer class="flex flex-wrap items-center gap-3 pt-4 border-t border-slate-800">
        <NuxtLink
          to="/roadmap"
          class="rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-slate-500 hover:text-white"
        >
          ← К роадмапу
        </NuxtLink>
        <NuxtLink
          to="/review"
          class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-medium text-slate-950 transition hover:bg-sky-400"
        >
          Перейти к повторению правил →
        </NuxtLink>
      </footer>
    </template>
  </section>
</template>
