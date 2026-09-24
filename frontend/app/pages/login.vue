<script setup lang="ts">
import type { CombinedError } from '@urql/vue'

const { login } = useAuth()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const pending = ref(false)

async function submit(): Promise<void> {
  pending.value = true
  error.value = null

  try {
    await login(email.value, password.value)
    await navigateTo('/roadmap')
  } catch (failure) {
    error.value = graphQLErrorMessage(failure as CombinedError) ?? 'Не удалось войти.'
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-sm space-y-6">
    <div>
      <h1 class="text-2xl font-semibold">Вход</h1>
      <p class="mt-1 text-sm text-slate-400">Продолжите работу со своим роадмапом.</p>
    </div>

    <form class="space-y-4" @submit.prevent="submit">
      <label class="block space-y-1">
        <span class="text-sm text-slate-400">Email</span>
        <input
          v-model="email"
          type="email"
          required
          autocomplete="email"
          class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none transition focus:border-sky-500"
        >
      </label>

      <label class="block space-y-1">
        <span class="text-sm text-slate-400">Пароль</span>
        <input
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none transition focus:border-sky-500"
        >
      </label>

      <p v-if="error" class="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">
        {{ error }}
      </p>

      <button
        type="submit"
        class="w-full rounded-lg bg-sky-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-sky-400 disabled:opacity-50"
        :disabled="pending"
      >
        {{ pending ? 'Входим…' : 'Войти' }}
      </button>
    </form>

    <p class="text-sm text-slate-400">
      Нет аккаунта?
      <NuxtLink to="/register" class="text-sky-400 hover:underline">Зарегистрироваться</NuxtLink>
    </p>
  </section>
</template>
