<script setup lang="ts">
import type { CombinedError } from '@urql/vue'
import { useQuery } from '@urql/vue'
import { GENERATE_ROADMAP_MUTATION, ROADMAP_QUERY } from '~/graphql/documents'
import type { Roadmap } from '~/types/graphql'
import { graphQLErrorMessage } from '~/utils/graphql-error'

const { $urql } = useNuxtApp()

const { data, fetching, error, executeQuery } = useQuery<{ roadmap: Roadmap | null }>({
  query: ROADMAP_QUERY,
  requestPolicy: 'network-only',
})

const roadmap = computed(() => data.value?.roadmap ?? null)
const generating = ref(false)
const generateError = ref<string | null>(null)

async function generate(): Promise<void> {
  generating.value = true
  generateError.value = null

  const { error: failure } = await $urql.mutation(GENERATE_ROADMAP_MUTATION, {}).toPromise()

  if (failure) {
    generateError.value = graphQLErrorMessage(failure as CombinedError) ?? 'Не удалось собрать роадмап.'
  } else {
    // The mutation is idempotent and only reports the title; the card list is
    // the query's job.
    await executeQuery({ requestPolicy: 'network-only' })
  }

  generating.value = false
}

const readyCard = computed(() => roadmap.value?.lessonCards.find((card) => card.status === 'ready') ?? null)
</script>

<template>
  <section class="space-y-8">
    <p v-if="fetching && !roadmap" class="text-slate-400">Загружаем роадмап…</p>

    <p
      v-else-if="error && !roadmap"
      class="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200"
    >
      {{ graphQLErrorMessage(error) ?? 'Не удалось загрузить роадмап.' }}
    </p>

    <template v-else-if="roadmap">
      <header class="space-y-2">
        <h1 class="text-2xl font-semibold">{{ roadmap.title }}</h1>
        <p class="text-sm text-slate-400">
          {{ roadmap.lessonCards.length }} уроков · сейчас
          {{ readyCard ? readyCard.grammarPoint.title : 'всё пройдено' }}
        </p>
      </header>

      <div class="space-y-5">
        <LessonCardItem v-for="card in roadmap.lessonCards" :key="card.id" :card="card" />
      </div>
    </template>

    <template v-else>
      <header class="space-y-2">
        <h1 class="text-2xl font-semibold">Роадмапа пока нет</h1>
        <p class="text-sm text-slate-400">
          Он собирается из вашего уровня: все темы до текущего CEFR-бэнда по порядку. Уровень определяет
          ассессмент по записи голоса, но можно собрать роадмап и сразу — по текущей оценке.
        </p>
      </header>

      <p
        v-if="generateError"
        class="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200"
      >
        {{ generateError }}
      </p>

      <div class="flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="rounded-lg bg-sky-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-sky-400 disabled:opacity-50"
          :disabled="generating"
          @click="generate"
        >
          {{ generating ? 'Собираем…' : 'Собрать роадмап' }}
        </button>
        <NuxtLink
          to="/onboarding"
          class="rounded-lg border border-slate-600 px-4 py-2 font-medium text-slate-100 transition hover:border-slate-400"
        >
          Сначала проверить уровень
        </NuxtLink>
      </div>
    </template>
  </section>
</template>
