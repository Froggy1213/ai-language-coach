<script setup lang="ts">
import type { CombinedError } from '@urql/vue'

const { register } = useAuth()

const name = ref('')
const email = ref('')
const password = ref('')
const targetLanguage = ref('en')
const error = ref<string | null>(null)
const pending = ref(false)

async function submit(): Promise<void> {
  pending.value = true
  error.value = null

  try {
    await register(name.value, email.value, password.value, targetLanguage.value)
    await navigateTo('/roadmap')
  } catch (failure) {
    error.value = graphQLErrorMessage(failure as CombinedError) ?? 'Не удалось создать аккаунт.'
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-sm space-y-6">
    <div>
      <h1 class="text-2xl font-semibold">Регистрация</h1>
      <p class="mt-1 text-sm text-slate-400">Уровень определится по записи голоса — это следующий шаг.</p>
    </div>

    <form class="space-y-4" @submit.prevent="submit">
      <label class="block space-y-1">
        <span class="text-sm text-slate-400">Имя</span>
        <input
          v-model="name"
          type="text"
          required
          autocomplete="name"
          class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none transition focus:border-sky-500"
        >
      </label>

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
          minlength="8"
          autocomplete="new-password"
          class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none transition focus:border-sky-500"
        >
        <span class="text-xs text-slate-500">Минимум 8 символов.</span>
      </label>

      <label class="block space-y-1">
        <span class="text-sm text-slate-400">Язык</span>
        <select
          v-model="targetLanguage"
          class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none transition focus:border-sky-500"
        >
          <option value="en">Английский</option>
        </select>
      </label>

      <p v-if="error" class="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">
        {{ error }}
      </p>

      <button
        type="submit"
        class="w-full rounded-lg bg-sky-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-sky-400 disabled:opacity-50"
        :disabled="pending"
      >
        {{ pending ? 'Создаём…' : 'Создать аккаунт' }}
      </button>
    </form>

    <p class="text-sm text-slate-400">
      Уже есть аккаунт?
      <NuxtLink to="/login" class="text-sky-400 hover:underline">Войти</NuxtLink>
    </p>
  </section>
</template>
