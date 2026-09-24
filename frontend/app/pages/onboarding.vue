<script setup lang="ts">
const { user, refresh } = useAuth()
const { submit, onReady } = useAssessment()

type Stage = 'consent' | 'ready' | 'recording' | 'uploading' | 'waiting' | 'done'

const stage = ref<Stage>('consent')
const consented = ref(false)
const error = ref<string | null>(null)
const elapsed = ref(0)
const level = ref<string | null>(null)

let recorder: MediaRecorder | null = null
let stream: MediaStream | null = null
let chunks: Blob[] = []
let ticker: ReturnType<typeof setInterval> | null = null
let stopListening: (() => void) | null = null

const elapsedLabel = computed(() => {
  const minutes = Math.floor(elapsed.value / 60)
  const seconds = elapsed.value % 60

  return `${minutes}:${String(seconds).padStart(2, '0')}`
})

function startRecording(): void {
  void beginRecording()
}

async function beginRecording(): Promise<void> {
  error.value = null

  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch {
    error.value = 'Нет доступа к микрофону. Разрешите запись в браузере и попробуйте снова.'
    return
  }

  // Chrome and Firefox record webm/opus, Safari records mp4; the API accepts
  // both, and the codec parameter is stripped before it is signed.
  const mimeType = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']
    .find((type) => MediaRecorder.isTypeSupported(type)) ?? ''

  recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined)
  chunks = []

  recorder.addEventListener('dataavailable', (event) => {
    if (event.data.size > 0) {
      chunks.push(event.data)
    }
  })
  recorder.addEventListener('stop', () => {
    void finishRecording()
  })

  recorder.start()
  elapsed.value = 0
  ticker = setInterval(() => {
    elapsed.value += 1
  }, 1000)
  stage.value = 'recording'
}

function stopRecording(): void {
  recorder?.stop()
}

async function finishRecording(): Promise<void> {
  stopTicker()
  stream?.getTracks().forEach((track) => track.stop())
  stream = null

  const contentType = (recorder?.mimeType || 'audio/webm').split(';')[0] ?? 'audio/webm'
  const recording = new Blob(chunks, { type: contentType })

  recorder = null
  chunks = []

  if (recording.size === 0) {
    error.value = 'Запись получилась пустой — попробуйте ещё раз.'
    stage.value = 'ready'

    return
  }

  await upload(recording, contentType)
}

async function upload(recording: Blob, contentType: string): Promise<void> {
  stage.value = 'uploading'
  error.value = null

  try {
    const currentUser = user.value

    if (!currentUser) {
      throw new Error('Сессия истекла — войдите заново.')
    }

    // Subscribe first: the analysis can finish before the mutation's response
    // is even rendered.
    stopListening = await onReady(currentUser.id, (assessment) => {
      level.value = assessment.cefrLevel
      stage.value = 'done'
      stopListening?.()
      stopListening = null
      void refresh()
    })

    await submit(recording, contentType)
    stage.value = 'waiting'
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось отправить запись на разбор.'
    stage.value = 'ready'
  }
}

async function checkNow(): Promise<void> {
  const before = user.value?.currentLevel
  await refresh()

  if (user.value && user.value.currentLevel !== before) {
    level.value = user.value.currentLevel
    stage.value = 'done'
  }
}

function stopTicker(): void {
  if (ticker) {
    clearInterval(ticker)
    ticker = null
  }
}

onBeforeUnmount(() => {
  stopTicker()
  stopListening?.()
  stream?.getTracks().forEach((track) => track.stop())
})
</script>

<template>
  <section class="space-y-8">
    <header class="space-y-2">
      <h1 class="text-2xl font-semibold">Проверка уровня</h1>
      <p class="text-sm text-slate-400">
        Запишите короткий рассказ о себе — минута-полторы. Мы расшифруем запись, оценим её по шкале CEFR
        и пересоберём роадмап под ваш уровень.
      </p>
    </header>

    <p v-if="error" class="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">
      {{ error }}
    </p>

    <div v-if="stage === 'consent'" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
      <h2 class="font-medium">Прежде чем записывать</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-300">
        <li>Запись уходит напрямую в защищённое хранилище и удаляется сразу после расшифровки.</li>
        <li>В хранилище остаётся только текст расшифровки и оценка — они нужны для роадмапа.</li>
        <li>Запись можно прервать в любой момент, ничего не отправляя.</li>
      </ul>
      <label class="flex items-start gap-3 text-sm text-slate-300">
        <input v-model="consented" type="checkbox" class="mt-1 size-4 accent-sky-500">
        <span>Согласен на запись и обработку голоса для оценки уровня.</span>
      </label>
      <button
        type="button"
        class="rounded-lg bg-sky-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-sky-400 disabled:opacity-50"
        :disabled="!consented"
        @click="stage = 'ready'"
      >
        Продолжить
      </button>
    </div>

    <div v-else-if="stage === 'ready'" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
      <h2 class="font-medium">Расскажите о себе</h2>
      <p class="text-sm text-slate-300">
        Например: чем занимаетесь, что делали в прошлые выходные, какие планы на год. Говорите свободно —
        оценка строится на вашей речи, а не на идеальности ответа.
      </p>
      <button
        type="button"
        class="rounded-lg bg-sky-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-sky-400"
        @click="startRecording"
      >
        Начать запись
      </button>
    </div>

    <div v-else-if="stage === 'recording'" class="space-y-4 rounded-xl border border-sky-500/40 bg-slate-900/60 p-5">
      <h2 class="flex items-center gap-3 font-medium">
        <span class="inline-block size-2 animate-pulse rounded-full bg-rose-400" />
        Идёт запись · {{ elapsedLabel }}
      </h2>
      <p class="text-sm text-slate-300">Говорите не меньше минуты: на короткой записи оценка менее надёжна.</p>
      <button
        type="button"
        class="rounded-lg border border-slate-600 px-4 py-2 font-medium text-slate-100 transition hover:border-slate-400"
        @click="stopRecording"
      >
        Остановить и отправить
      </button>
    </div>

    <div v-else-if="stage === 'uploading'" class="rounded-xl border border-slate-800 bg-slate-900/60 p-5">
      <p class="text-slate-300">Загружаем запись…</p>
    </div>

    <div v-else-if="stage === 'waiting'" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900/60 p-5">
      <p class="text-slate-300">Расшифровываем и оцениваем. Обычно это занимает меньше минуты.</p>
      <div class="flex flex-wrap items-center gap-3">
        <span class="inline-block size-4 animate-spin rounded-full border-2 border-slate-600 border-t-sky-400" />
        <button
          type="button"
          class="rounded-lg border border-slate-600 px-3 py-1 text-sm text-slate-200 transition hover:border-slate-400"
          @click="checkNow"
        >
          Проверить сейчас
        </button>
      </div>
      <p class="text-xs text-slate-500">
        Если результат не приходит, проверьте, запущен ли <code>php artisan reverb:start</code> и заполнен ли
        <code>NUXT_PUBLIC_REVERB_APP_KEY</code>.
      </p>
    </div>

    <div v-else class="space-y-4 rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-5">
      <h2 class="font-medium text-emerald-200">
        Готово — ваш уровень {{ level ?? user?.currentLevel }}
      </h2>
      <p class="text-sm text-emerald-100/80">Роадмап уже пересобран под новую оценку.</p>
      <NuxtLink
        to="/roadmap"
        class="inline-block rounded-lg bg-sky-500 px-4 py-2 font-medium text-slate-950 transition hover:bg-sky-400"
      >
        Открыть роадмап
      </NuxtLink>
    </div>
  </section>
</template>
