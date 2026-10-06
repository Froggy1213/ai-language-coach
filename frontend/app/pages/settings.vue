<script setup lang="ts">
import { useQuery } from '@urql/vue'
import { ME_QUERY } from '~/graphql/documents'

interface MeData {
  me: {
    id: string
    name: string
    email: string
    targetLanguage: string
    currentLevel: string
    voiceConsentAt: string | null
  } | null
}

const { data, fetching, error: queryError } = useQuery<MeData>({
  query: ME_QUERY,
  requestPolicy: 'cache-and-network',
})

const currentUser = computed(() => data.value?.me ?? null)

const { deleting, error: deleteError, deleteAccount } = usePrivacy()

const password = ref('')
const confirmation = ref('')

async function handleDelete(): Promise<void> {
  if (!password.value || !confirmation.value) {
    return
  }

  await deleteAccount(password.value, confirmation.value)
}

function formatDate(dateStr: string | null | undefined): string {
  if (!dateStr) {
    return 'Не записано'
  }
  try {
    const date = new Date(dateStr)
    return Number.isNaN(date.getTime()) ? dateStr : date.toLocaleString('ru-RU')
  } catch {
    return dateStr
  }
}

function formatLanguage(lang: string | undefined): string {
  if (!lang) return '—'
  const map: Record<string, string> = {
    en: 'Английский (en)',
    de: 'Немецкий (de)',
    es: 'Испанский (es)',
    fr: 'Французский (fr)',
  }
  return map[lang] ?? lang
}
</script>

<template>
  <section class="mx-auto max-w-xl space-y-8">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight">Настройки аккаунта</h1>
      <p class="mt-1 text-sm text-slate-400">Информация о профиле, согласии на обработку голоса и управление аккаунтом.</p>
    </header>

    <div v-if="fetching && !currentUser" class="text-sm text-slate-400">
      Загрузка настроек…
    </div>

    <div v-else-if="queryError && !currentUser" class="rounded-lg border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-rose-200">
      Не удалось загрузить данные аккаунта.
    </div>

    <div v-else-if="currentUser" class="space-y-6">
      <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-6 space-y-4">
        <h2 class="text-lg font-medium text-slate-200">Данные аккаунта</h2>

        <dl class="divide-y divide-slate-800 text-sm">
          <div class="flex justify-between py-3">
            <dt class="text-slate-400">Имя</dt>
            <dd class="font-medium text-slate-200">{{ currentUser.name }}</dd>
          </div>
          <div class="flex justify-between py-3">
            <dt class="text-slate-400">Email</dt>
            <dd class="font-medium text-slate-200">{{ currentUser.email }}</dd>
          </div>
          <div class="flex justify-between py-3">
            <dt class="text-slate-400">Изучаемый язык</dt>
            <dd class="font-medium text-slate-200">{{ formatLanguage(currentUser.targetLanguage) }}</dd>
          </div>
          <div class="flex justify-between py-3">
            <dt class="text-slate-400">Текущий уровень (CEFR)</dt>
            <dd class="font-medium text-slate-200">{{ currentUser.currentLevel }}</dd>
          </div>
          <div class="flex justify-between py-3">
            <dt class="text-slate-400">Согласие на запись голоса</dt>
            <dd class="font-medium text-slate-200">
              <span v-if="currentUser.voiceConsentAt" class="inline-flex items-center gap-1.5 text-emerald-400">
                <span class="inline-block h-2 w-2 rounded-full bg-emerald-400" />
                {{ formatDate(currentUser.voiceConsentAt) }}
              </span>
              <span v-else class="text-slate-500">
                Не зафиксировано
              </span>
            </dd>
          </div>
        </dl>
      </div>

      <div class="rounded-xl border border-rose-900/40 bg-rose-950/20 p-6 space-y-4">
        <div>
          <h2 class="text-lg font-medium text-rose-300">Удаление аккаунта</h2>
          <p class="mt-1 text-xs text-rose-200/80">
            Действие необратимо. Будут удалены все ваши данные: профиль, роадмап, история диалогов, ошибки и аудиозаписи.
          </p>
        </div>

        <div
          v-if="deleteError"
          class="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200"
        >
          {{ deleteError }}
        </div>

        <form class="space-y-4" @submit.prevent="handleDelete">
          <label class="block space-y-1">
            <span class="text-sm text-slate-400">Текущий пароль</span>
            <input
              v-model="password"
              type="password"
              required
              autocomplete="current-password"
              placeholder="Введите пароль"
              class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-rose-500"
            >
          </label>

          <label class="block space-y-1">
            <span class="text-sm text-slate-400">
              Для подтверждения введите <strong class="text-rose-400">DELETE</strong>
            </span>
            <input
              v-model="confirmation"
              type="text"
              required
              placeholder="DELETE"
              class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-rose-500"
            >
          </label>

          <button
            type="submit"
            :disabled="deleting || !password || confirmation !== 'DELETE'"
            class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ deleting ? 'Удаление аккаунта…' : 'Удалить аккаунт' }}
          </button>
        </form>
      </div>
    </div>
  </section>
</template>
