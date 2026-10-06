<script setup lang="ts">
import { useQuery } from '@urql/vue'
import { ROADMAP_QUERY } from '~/graphql/documents'
import type { Roadmap } from '~/types/graphql'
import { graphQLErrorMessage } from '~/utils/graphql-error'

const route = useRoute()
const cardId = computed(() => String(route.params.cardId))

const { data, fetching, error: queryError } = useQuery<{ roadmap: Roadmap | null }>({
  query: ROADMAP_QUERY,
  requestPolicy: 'cache-and-network',
})

const roadmap = computed(() => data.value?.roadmap ?? null)
const card = computed(() => roadmap.value?.lessonCards.find((c) => c.id === cardId.value) ?? null)

const audioElement = ref<HTMLAudioElement | null>(null)
const { status, error, agentPresent, elapsedSeconds, start, leave } = useVoiceSession({
  audioElement,
})

watch(cardId, () => {
  void leave()
})

const elapsedLabel = computed(() => {
  const minutes = Math.floor(elapsedSeconds.value / 60)
  const seconds = elapsedSeconds.value % 60
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
})

const isStartingOrConnected = computed(
  () => status.value === 'requesting' || status.value === 'connecting' || status.value === 'connected',
)

function startSession(): void {
  if (card.value && !isStartingOrConnected.value) {
    void start(card.value.id)
  }
}
</script>

<template>
  <section class="space-y-8">
    <p v-if="fetching && !roadmap" class="text-slate-400">Загружаем данные урока…</p>

    <p
      v-else-if="queryError && !roadmap"
      class="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200"
    >
      {{ graphQLErrorMessage(queryError) ?? 'Не удалось загрузить данные роадмапа.' }}
    </p>

    <div v-else-if="!card" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
      <h1 class="text-xl font-semibold text-slate-100">Урок не найден</h1>
      <p class="text-sm text-slate-400">
        Этот урок отсутствует в вашем роадмапе или принадлежит другому аккаунту.
      </p>
      <NuxtLink
        to="/roadmap"
        class="inline-block rounded-lg bg-sky-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-sky-400"
      >
        Вернуться к роадмапу
      </NuxtLink>
    </div>

    <div v-else-if="card.status !== 'ready'" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
      <h1 class="text-xl font-semibold text-slate-100">Урок недоступен для практики</h1>
      <p class="text-sm text-slate-400">
        <span v-if="card.status === 'locked'">
          Этот урок пока заблокирован. Выполняйте уроки роадмапа по порядку, чтобы открыть его.
        </span>
        <span v-else-if="card.status === 'completed'">
          Этот урок уже успешно пройден.
        </span>
        <span v-else>
          Статус урока: {{ card.status }}. Практика доступна только для активных уроков.
        </span>
      </p>
      <NuxtLink
        to="/roadmap"
        class="inline-block rounded-lg border border-slate-600 px-4 py-2 font-medium text-slate-100 transition hover:border-slate-400"
      >
        Вернуться к роадмапу
      </NuxtLink>
    </div>

    <template v-else>
      <div class="flex items-center justify-between">
        <NuxtLink
          to="/roadmap"
          class="inline-flex items-center gap-1 text-sm text-slate-400 transition hover:text-slate-200"
        >
          ← К роадмапу
        </NuxtLink>
        <span class="rounded-full border border-sky-500/60 bg-sky-500/10 px-3 py-1 text-xs text-sky-300">
          Сейчас
        </span>
      </div>

      <header class="space-y-1">
        <p class="text-xs uppercase tracking-wide text-slate-500">
          Урок {{ card.orderIndex }} · {{ card.grammarPoint.category }}
        </p>
        <h1 class="text-2xl font-semibold text-slate-100">
          {{ card.grammarPoint.title }}
        </h1>
      </header>

      <!-- Error state -->
      <div v-if="status === 'error'" class="space-y-4 rounded-xl border border-rose-500/40 bg-rose-500/10 p-5">
        <div class="space-y-1">
          <h2 class="font-medium text-rose-200">Не удалось провести практику</h2>
          <p class="text-sm leading-relaxed text-rose-100/90 whitespace-pre-line">
            {{ error }}
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <button
            type="button"
            :disabled="isStartingOrConnected"
            class="rounded-lg bg-rose-500 px-4 py-2 font-medium text-white transition hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-50"
            @click="startSession"
          >
            Попробовать снова
          </button>
          <NuxtLink
            to="/roadmap"
            class="rounded-lg border border-slate-700 px-4 py-2 font-medium text-slate-200 transition hover:border-slate-500"
          >
            Вернуться к роадмапу
          </NuxtLink>
        </div>
      </div>

      <!-- Connected state -->
      <div v-else-if="status === 'connected'" class="space-y-4 rounded-xl border border-sky-500/50 bg-slate-900/80 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-2">
            <span class="inline-block size-2.5 animate-pulse rounded-full bg-rose-400" />
            <span class="font-medium text-slate-100">Идёт сессия</span>
            <span class="font-mono text-sm text-slate-300">· {{ elapsedLabel }}</span>
          </div>
          <div>
            <span
              v-if="agentPresent"
              class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/40 bg-emerald-500/10 px-2.5 py-0.5 text-xs text-emerald-300"
            >
              <span class="size-1.5 rounded-full bg-emerald-400" />
              Агент на связи
            </span>
            <span
              v-else
              class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/40 bg-amber-500/10 px-2.5 py-0.5 text-xs text-amber-300"
            >
              <span class="size-1.5 animate-pulse rounded-full bg-amber-400" />
              Ожидание агента в комнате…
            </span>
          </div>
        </div>

        <p v-if="error" class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-200">
          {{ error }}
        </p>

        <p class="text-sm text-slate-300">
          Говорите в микрофон на изучаемом языке. Агент будет отвечать голосом и направлять диалог.
        </p>

        <button
          type="button"
          class="rounded-lg border border-rose-500/50 bg-rose-500/10 px-4 py-2 font-medium text-rose-300 transition hover:bg-rose-500/20"
          @click="leave"
        >
          Завершить
        </button>
      </div>

      <!-- Requesting / Connecting state -->
      <div
        v-else-if="status === 'requesting' || status === 'connecting'"
        class="space-y-4 rounded-xl border border-sky-500/30 bg-slate-900/60 p-5"
      >
        <div class="flex items-center gap-3">
          <span class="inline-block size-4 animate-spin rounded-full border-2 border-slate-600 border-t-sky-400" />
          <p class="font-medium text-slate-200">
            {{ status === 'requesting' ? 'Запрашиваем голосовую сессию…' : 'Подключаемся к комнате…' }}
          </p>
        </div>
        <p class="text-sm text-slate-400">
          {{
            status === 'requesting'
              ? 'Сервер готовит комнату и связывается с голосовым агентом.'
              : 'Устанавливаем аудиосоединение LiveKit и запрашиваем микрофон…'
          }}
        </p>
        <button
          type="button"
          disabled
          class="cursor-not-allowed rounded-lg bg-sky-500 px-5 py-2.5 font-medium text-slate-950 opacity-50"
        >
          {{ status === 'requesting' ? 'Запрашиваем…' : 'Подключение…' }}
        </button>
      </div>

      <!-- Ended state -->
      <div v-else-if="status === 'ended'" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
        <h2 class="font-medium text-slate-200">Практика завершена</h2>
        <p class="text-sm text-slate-400">
          Сессия завершена (длительность: {{ elapsedLabel }}).
        </p>
        <div class="flex flex-wrap items-center gap-3">
          <button
            type="button"
            :disabled="isStartingOrConnected"
            class="rounded-lg bg-sky-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
            @click="startSession"
          >
            Начать снова
          </button>
          <NuxtLink
            to="/roadmap"
            class="rounded-lg border border-slate-600 px-4 py-2 font-medium text-slate-100 transition hover:border-slate-400"
          >
            Вернуться к роадмапу
          </NuxtLink>
        </div>
      </div>

      <!-- Idle state -->
      <div v-else class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
        <div class="space-y-1">
          <h2 class="text-xs uppercase tracking-wide text-slate-500">Задание для разговора</h2>
          <p class="text-base text-slate-200">{{ card.practicePrompt }}</p>
        </div>
        <button
          type="button"
          :disabled="isStartingOrConnected"
          class="rounded-lg bg-sky-500 px-5 py-2.5 font-medium text-slate-950 transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
          @click="startSession"
        >
          Начать практику
        </button>
      </div>

      <!-- Task prompt reminder when not in idle -->
      <div v-if="status !== 'idle'" class="rounded-xl border border-slate-800 bg-slate-900/60 p-5 space-y-2">
        <p class="text-xs uppercase tracking-wide text-slate-500">Задание для разговора</p>
        <p class="text-sm text-slate-300">{{ card.practicePrompt }}</p>
      </div>

      <!-- Cheat sheet panel -->
      <div class="space-y-3 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
        <h2 class="font-medium text-slate-100">Шпаргалка к уроку</h2>
        <div class="border-t border-slate-800 pt-3">
          <CheatSheetPanel :cheat-sheet="card.cheatSheet" />
        </div>
      </div>

      <!-- Audio playback element -->
      <audio ref="audioElement" class="hidden" autoplay playsinline />
    </template>
  </section>
</template>
