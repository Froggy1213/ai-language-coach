<script setup lang="ts">
import { useQuery } from '@urql/vue'
import { DUE_REVIEWS_QUERY } from '~/graphql/documents'
import type { ReviewItem } from '~/types/graphql'

const { user, logout } = useAuth()

const { data: reviewsData } = useQuery<{ dueReviews: ReviewItem[] }>({
  query: DUE_REVIEWS_QUERY,
  pause: computed(() => !user.value),
  requestPolicy: 'cache-and-network',
})

const dueCount = computed(() => reviewsData.value?.dueReviews?.length ?? 0)

const signingOut = ref(false)

async function signOut(): Promise<void> {
  signingOut.value = true

  try {
    await logout()
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-950 text-slate-100">
    <header v-if="user" class="border-b border-slate-800 bg-slate-900/40">
      <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-6 py-4">
        <NuxtLink to="/roadmap" class="font-semibold tracking-tight">AI Language Coach</NuxtLink>

        <div class="flex items-center gap-4 text-sm text-slate-400">
          <nav class="flex items-center gap-4">
            <NuxtLink to="/roadmap" class="transition hover:text-slate-100">Роадмап</NuxtLink>
            <NuxtLink to="/review" class="inline-flex items-center gap-1.5 transition hover:text-slate-100">
              <span>Повторение</span>
              <span
                v-if="dueCount > 0"
                class="rounded-full bg-sky-500/20 px-1.5 py-0.5 text-xs font-medium text-sky-300"
              >
                {{ dueCount }}
              </span>
            </NuxtLink>
            <NuxtLink to="/onboarding" class="transition hover:text-slate-100">Проверить уровень</NuxtLink>
          </nav>
          <span class="hidden sm:inline">{{ user.name }} · {{ user.currentLevel }}</span>
          <button
            type="button"
            class="rounded-lg border border-slate-700 px-3 py-1 text-slate-300 transition hover:border-slate-500 hover:text-slate-100 disabled:opacity-50"
            :disabled="signingOut"
            @click="signOut"
          >
            Выйти
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-4xl px-6 py-10">
      <NuxtPage />
    </main>

    <NuxtRouteAnnouncer />
  </div>
</template>
