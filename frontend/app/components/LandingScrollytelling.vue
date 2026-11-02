<script setup lang="ts">
import type { OrbState } from '~/composables/useConversation'
import { app, landing } from '~/constants/strings'

/**
 * Cinematic scrollytelling landing page for guests.
 *
 * Combines native CSS Scroll-Driven Animations (`view-timeline`, `timeline-scope`,
 * `animation-range`) with a synchronized 60fps scroll director that drives the
 * Canvas 2D `ConversationOrb` and frame-accurate interactive UI choreography
 * (typewriter transcript, timecode scrubber, and auto-play cinema mode).
 */
const { theme, toggle: toggleTheme } = useTheme()

const heroWrapper = ref<HTMLElement | null>(null)
const trackedContainer = ref<HTMLElement | null>(null)

const {
  trackProgress,
  activeChapterIndex,
  scrollVelocityBoost,
  autoplaying,
  scrollToChapter,
  toggleAutoplay,
} = useLandingScrollDirector({ trackedContainer, heroWrapper })

const {
  transcriptWords,
  visibleWordCount,
  simulatedSessionClock,
  cinemaTimecode,
  stageOrbState,
  stageOrbEnergy,
  currentChapter,
} = useLandingCinema({ trackProgress, activeChapterIndex, scrollVelocityBoost })

/** Interactive Orb Playground state (below the cinema track). */
const playgroundState = ref<OrbState>('speaking')
const playgroundEnergy = ref(0.72)
</script>

<template>
  <div class="scrolly-root relative">
    <!-- Top scroll-driven progress ribbon -->
    <div
      class="pointer-events-none fixed top-0 right-0 left-0 z-50 h-0.5 origin-left bg-[var(--accent)]"
      :style="{ transform: `scaleX(${trackProgress})` }"
      aria-hidden="true"
    />

    <!-- Floating Cinema Navigation -->
    <header class="sticky top-0 z-40 border-b border-[var(--line)] bg-[color-mix(in_oklab,var(--canvas)_78%,transparent)] backdrop-blur-xl">
      <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-5 sm:px-6">
        <a href="#top" class="group flex items-center gap-2.5">
          <span class="size-2 rounded-full bg-[var(--accent)] shadow-[0_0_12px_var(--accent)] transition group-hover:scale-125" />
          <span class="display text-[1.125rem] tracking-tight">{{ app.name }}</span>
          <span class="hidden rounded-full border border-[var(--line)] bg-[var(--surface)] px-2 py-0.5 font-mono text-[0.625rem] tracking-[0.14em] text-[var(--ink-faint)] sm:inline-block">
            VOICE AI
          </span>
        </a>

        <nav class="hidden items-center gap-6 text-[0.875rem] text-[var(--ink-muted)] md:flex" aria-label="ランディングナビゲーション">
          <a href="#story" class="transition-colors hover:text-[var(--ink)]">{{ landing.nav.story }}</a>
          <a href="#method" class="transition-colors hover:text-[var(--ink)]">{{ landing.nav.method }}</a>
          <a href="#interactive" class="transition-colors hover:text-[var(--ink)]">{{ landing.nav.interactive }}</a>
          <a href="#privacy" class="transition-colors hover:text-[var(--ink)]">{{ landing.nav.privacy }}</a>
        </nav>

        <div class="flex items-center gap-2.5 sm:gap-3">
          <button
            type="button"
            class="grid size-9 place-items-center rounded-full text-[var(--ink-faint)] transition hover:bg-[var(--surface)] hover:text-[var(--ink)]"
            :aria-label="theme === 'dark' ? 'ライトテーマに切り替える' : 'ダークテーマに切り替える'"
            @click="toggleTheme"
          >
            <ThemeIcon :theme="theme" />
          </button>

          <UiButtonLink to="/login" variant="ghost" size="sm">
            {{ landing.nav.login }}
          </UiButtonLink>

          <UiButtonLink to="/register" variant="primary" size="sm">
            {{ landing.nav.register }}
          </UiButtonLink>
        </div>
      </div>
    </header>

    <!-- ACT 0: Parallax Opening Hero Stage -->
    <section
      id="top"
      ref="heroWrapper"
      class="parallax-wrapper relative flex min-h-[92dvh] flex-col justify-between overflow-clip px-5 pt-12 pb-14 sm:px-6 md:pt-20"
    >
      <!-- Ambient background depth rings (Parallax Layer 1) -->
      <div
        class="parallax-layer layer-1 pointer-events-none absolute inset-0 flex items-center justify-center"
        aria-hidden="true"
      >
        <div class="size-[min(72vw,62dvh,32rem)] rounded-full border border-[var(--line)] opacity-45" />
        <div class="absolute size-[min(52vw,46dvh,23rem)] rounded-full border border-[color-mix(in_oklab,var(--accent)_18%,transparent)]" />
        <div
          class="absolute size-[min(46vw,40dvh,20rem)] rounded-full opacity-35 blur-3xl"
          style="background: radial-gradient(circle, var(--accent) 0%, transparent 72%)"
        />
      </div>

      <!-- Hero Centerpiece (Parallax Layer 2) -->
      <div class="parallax-layer layer-2 relative z-10 mx-auto grid w-full max-w-6xl items-center gap-10 lg:grid-cols-[1.12fr_0.88fr] lg:gap-12">
        <div class="space-y-7">
          <div class="inline-flex items-center gap-2.5 rounded-full border border-[var(--line-strong)] bg-[var(--surface)] px-3.5 py-1.5 backdrop-blur-md">
            <span class="size-1.5 animate-pulse rounded-full bg-[var(--accent)]" />
            <span class="font-mono text-[0.6875rem] tracking-[0.16em] text-[var(--accent)]">
              {{ landing.hero.eyebrow }}
            </span>
          </div>

          <h1 class="display text-[clamp(2.5rem,6vw,4.75rem)] leading-[1.08] tracking-[-0.02em]">
            <span class="block">{{ landing.hero.titleLine1 }}</span>
            <span class="block bg-gradient-to-r from-[var(--ink)] via-[var(--accent-strong)] to-[var(--accent)] bg-clip-text text-transparent">
              {{ landing.hero.titleLine2 }}
            </span>
          </h1>

          <p class="max-w-[46ch] text-[1.0625rem] leading-[1.85] text-[var(--ink-muted)] sm:text-[1.125rem]">
            {{ landing.hero.lead }}
          </p>

          <div class="flex flex-wrap items-center gap-3.5 pt-2">
            <UiButtonLink to="/register" variant="primary" size="lg">
              {{ landing.hero.primaryCta }}
            </UiButtonLink>

            <button
              type="button"
              class="inline-flex min-h-12 items-center gap-2.5 rounded-full border border-[var(--line-strong)] bg-[var(--surface)] px-5 text-[0.9375rem] font-medium text-[var(--ink)] transition hover:border-[var(--accent)] hover:text-[var(--accent)]"
              @click="toggleAutoplay"
            >
              <span class="grid size-5 place-items-center rounded-full bg-[var(--accent-dim)] text-[var(--accent)]">
                <svg v-if="!autoplaying" viewBox="0 0 24 24" fill="currentColor" class="size-3">
                  <path d="M8 5.5v13l10.5-6.5L8 5.5Z" />
                </svg>
                <svg v-else viewBox="0 0 24 24" fill="currentColor" class="size-3">
                  <path d="M7 6h3.5v12H7V6Zm6.5 0H17v12h-3.5V6Z" />
                </svg>
              </span>
              {{ autoplaying ? landing.hero.autoplayStop : landing.hero.autoplayStart }}
            </button>

            <UiButtonLink to="/login" variant="ghost" size="lg">
              {{ landing.hero.secondaryCta }}
            </UiButtonLink>
          </div>
        </div>

        <!-- Floating Live Preview Portal (Parallax Layer 3) -->
        <div class="parallax-layer layer-3 relative mx-auto flex w-full max-w-lg flex-col items-center py-4">
          <div class="relative aspect-square w-[min(82vw,24rem)]">
            <!-- Subtle circular halo frame set well inside the canvas so the orb's light radiates freely -->
            <div
              class="pointer-events-none absolute inset-[10%] rounded-full border border-[var(--line)] bg-[color-mix(in_oklab,var(--surface-sunken)_68%,transparent)] shadow-2xl backdrop-blur-xl"
              aria-hidden="true"
            />

            <!-- Full-bleed canvas with zero inner padding clipping -->
            <div class="relative z-10 size-full">
              <ConversationOrb state="speaking" :energy="0.68" />
            </div>

            <!-- Floating telemetry pill top-left -->
            <div class="absolute top-6 left-0 z-20 flex items-center gap-2 rounded-2xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_88%,transparent)] px-3.5 py-2 shadow-lg backdrop-blur-md sm:-left-4">
              <span class="size-2 rounded-full bg-[var(--color-sage-400)]" />
              <div class="text-left">
                <p class="font-mono text-[0.625rem] tracking-[0.12em] text-[var(--ink-faint)]">CEFR DIAGNOSTIC</p>
                <p class="tnum text-[0.8125rem] font-medium">B1 · 明瞭度 94%</p>
              </div>
            </div>

            <!-- Floating grammar correction pill bottom-right -->
            <div class="absolute right-0 bottom-6 z-20 max-w-[14.5rem] rounded-2xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_90%,transparent)] px-3.5 py-2.5 shadow-lg backdrop-blur-md sm:-right-4">
              <p class="font-mono text-[0.625rem] tracking-[0.12em] text-[var(--accent)]">INSTANT REFLECTION</p>
              <p class="mt-0.5 text-[0.75rem] text-[var(--ink-faint)] line-through">If I have time, I will go.</p>
              <p class="text-[0.8125rem] font-medium text-[var(--color-sage-400)]">If I had time, I would go.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Scroll cue at bottom of Hero -->
      <div class="relative z-10 mx-auto mt-10 flex flex-col items-center gap-2 text-center">
        <a
          href="#story"
          class="group inline-flex flex-col items-center gap-2 text-[0.75rem] tracking-[0.14em] text-[var(--ink-faint)] uppercase transition hover:text-[var(--accent)]"
        >
          <span>{{ landing.hero.scrollHint }}</span>
          <span class="flex h-8 w-5 items-start justify-center rounded-full border border-[var(--line-strong)] p-1">
            <span class="size-1.5 animate-bounce rounded-full bg-[var(--accent)]" />
          </span>
        </a>
      </div>
    </section>

    <!-- =======================================================================
         SCROLLYTELLING CINEMA TRACK (#story)
         As the user scrolls through the 5 tracked sections in `#tracked`, the
         sticky `#animated` stage morphs like an interactive film.
         ======================================================================= -->
    <section id="story" class="relative border-t border-[var(--line)]">
      <!-- Sticky Cinema Viewport -->
      <div class="sticky top-16 z-20 flex h-[calc(100dvh-4rem)] w-full flex-col justify-between overflow-hidden">
        <!-- Subtle ambient radial light that shifts with active chapter -->
        <div
          class="pointer-events-none absolute inset-0 transition-opacity duration-700"
          :style="{
            background: `radial-gradient(circle at ${activeChapterIndex % 2 === 0 ? '68%' : '32%'} 46%, color-mix(in oklab, var(--accent) 14%, transparent), transparent 64%)`,
          }"
          aria-hidden="true"
        />

        <!-- Top Stage HUD Bar -->
        <div class="relative z-20 mx-auto flex w-full max-w-6xl items-center justify-between px-5 pt-4 sm:px-6 sm:pt-6">
          <div class="flex items-center gap-2.5">
            <span class="size-2 animate-pulse rounded-full bg-[var(--accent)]" />
            <span class="font-mono text-[0.6875rem] tracking-[0.16em] text-[var(--ink-faint)]">
              {{ landing.stage.liveIndicator }}
            </span>
          </div>

          <div class="flex items-center gap-3 font-mono text-[0.75rem] text-[var(--ink-muted)]">
            <span class="rounded-full border border-[var(--line)] bg-[var(--surface)] px-2.5 py-0.5 text-[var(--accent)]">
              SCENE {{ currentChapter.index }} / 05
            </span>
            <span class="tnum hidden sm:inline">{{ cinemaTimecode }} / 01:00</span>
          </div>
        </div>

        <!-- Main Split Cinema Frame: Left Narrative + Right Interactive Stage -->
        <div class="relative z-20 mx-auto grid w-full max-w-6xl flex-1 items-center gap-6 px-5 py-2 sm:px-6 lg:grid-cols-[0.92fr_1.08fr] lg:gap-12">
          <!-- Left column: Synchronized Chapter Narrative -->
          <Transition name="cinema-copy" mode="out-in">
            <div :key="currentChapter.id" class="space-y-4 sm:space-y-5">
              <div class="inline-flex items-center gap-2 rounded-full bg-[var(--accent-dim)] px-3 py-1 font-mono text-[0.6875rem] font-semibold tracking-[0.14em] text-[var(--accent)]">
                <span>{{ currentChapter.tag }}</span>
              </div>

              <h2 class="display text-[clamp(1.625rem,3.4vw,2.65rem)] leading-[1.18] tracking-[-0.01em]">
                {{ currentChapter.title }}
              </h2>

              <p class="max-w-[44ch] text-[0.9375rem] leading-[1.85] text-[var(--ink-muted)] sm:text-[1rem]">
                {{ currentChapter.body }}
              </p>

              <p class="hidden border-l-2 border-[var(--accent)] pl-3.5 text-[0.8125rem] leading-relaxed text-[var(--ink-faint)] sm:block">
                {{ currentChapter.caption }}
              </p>
            </div>
          </Transition>

          <!-- Right column: The Animated Scrollytelling Stage (#animated) -->
          <div
            id="animated"
            class="relative flex min-h-[22rem] w-full items-center justify-center sm:min-h-[27rem]"
          >
            <!-- Persistent Live Orb Backplane: anchored at center and choreographed purely via transform + opacity so it never snaps or clips -->
            <div
              class="pointer-events-none absolute top-1/2 left-1/2 z-0 size-52 transition-[transform,opacity] duration-700 ease-out sm:size-60"
              :class="[
                activeChapterIndex === 0 ? '-translate-x-1/2 -translate-y-[66%] scale-100 opacity-100' : '',
                activeChapterIndex === 1 ? '-translate-x-[6%] -translate-y-[64%] scale-75 opacity-55' : '',
                activeChapterIndex === 2 ? '-translate-x-[4%] -translate-y-[14%] scale-70 opacity-40' : '',
                activeChapterIndex === 3 ? '-translate-x-1/2 -translate-y-[70%] scale-80 opacity-70' : '',
                activeChapterIndex === 4 ? '-translate-x-[6%] -translate-y-[12%] scale-65 opacity-35' : '',
              ]"
            >
              <ConversationOrb :state="stageOrbState" :energy="stageOrbEnergy" />
            </div>

            <!-- SCENE 1 CARD: Prologue — Acoustic Awakening -->
            <section
              class="cinema-card absolute inset-x-0 mx-auto mb-1 max-w-lg self-end rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_92%,transparent)] p-5 shadow-2xl backdrop-blur-xl sm:mb-3 sm:p-6"
              :class="{ 'is-active': activeChapterIndex === 0 }"
              :inert="activeChapterIndex !== 0"
            >
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="label text-[var(--accent)]">VOICE-FIRST ARCHITECTURE</p>
                  <p class="mt-1 text-[1rem] font-medium">あなたの声に反応する光のコア</p>
                </div>
                <span class="tnum rounded-full bg-[var(--accent-dim)] px-3 py-1 font-mono text-[0.75rem] text-[var(--accent)]">
                  60 FPS · LIVE
                </span>
              </div>

              <!-- Animated acoustic spectrum bars synced to scroll -->
              <div class="mt-4 flex h-12 items-end justify-between gap-1.5 rounded-2xl bg-[var(--surface-sunken)] px-4 py-2.5" aria-hidden="true">
                <span
                  v-for="bar in 24"
                  :key="bar"
                  class="w-1.5 rounded-full bg-[var(--accent)] transition-all duration-150"
                  :style="{
                    height: `${Math.max(18, Math.min(100, Math.round(35 + Math.sin(trackProgress * 45 + bar * 0.65) * 45 + scrollVelocityBoost * 60)))}%`,
                    opacity: 0.45 + (bar % 3) * 0.2,
                  }"
                />
              </div>
            </section>

            <!-- SCENE 2 CARD: 1-Minute Voice Diagnostic & CEFR Level -->
            <section
              class="cinema-card absolute inset-x-0 mx-auto max-w-lg space-y-4 rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_94%,transparent)] p-5 shadow-2xl backdrop-blur-xl sm:p-6"
              :class="{ 'is-active': activeChapterIndex === 1 }"
              :inert="activeChapterIndex !== 1"
            >
              <div class="flex items-center justify-between">
                <span class="label">LIVE TRANSCRIPTION · 00:58</span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-[var(--accent-dim)] px-2.5 py-0.5 text-[0.75rem] font-semibold text-[var(--accent)]">
                  <span class="size-1.5 animate-ping rounded-full bg-[var(--accent)]" />
                  {{ landing.stage.detectedLevel }}
                </span>
              </div>

              <!-- Scroll-synced typewriter transcript -->
              <p class="min-h-[4.25rem] rounded-2xl bg-[var(--surface-sunken)] px-4 py-3 text-[0.9375rem] leading-relaxed text-[var(--ink)]">
                <span
                  v-for="(word, idx) in transcriptWords"
                  :key="idx"
                  class="inline-block mr-1 transition-opacity duration-200"
                  :class="idx < visibleWordCount ? 'opacity-100' : 'opacity-20'"
                >
                  {{ word }}
                </span>
              </p>

              <div class="grid grid-cols-2 gap-3 pt-1">
                <div class="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-3">
                  <p class="label">発音の目安</p>
                  <p class="tnum mt-1 text-[1rem] font-medium text-[var(--color-sage-400)]">
                    {{ landing.stage.clarityLabel }}
                  </p>
                </div>

                <div class="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-3">
                  <p class="label">聞き取りにくかった語</p>
                  <div class="mt-1.5 flex flex-wrap gap-1.5">
                    <span
                      v-for="item in landing.stage.uncertainWords"
                      :key="item.word"
                      class="rounded-full border border-[var(--line-strong)] px-2 py-0.5 text-[0.75rem]"
                    >
                      {{ item.word }} <span class="tnum text-[var(--ink-faint)]">{{ item.confidence }}%</span>
                    </span>
                  </div>
                </div>
              </div>
            </section>

            <!-- SCENE 3 CARD: Personalized CEFR Roadmap Rail -->
            <section
              class="cinema-card absolute inset-x-0 mx-auto max-w-lg rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_94%,transparent)] p-5 shadow-2xl backdrop-blur-xl sm:p-6"
              :class="{ 'is-active': activeChapterIndex === 2 }"
              :inert="activeChapterIndex !== 2"
            >
              <div class="mb-4 flex items-center justify-between">
                <span class="label">PERSONALIZED GRAMMAR RAIL</span>
                <span class="tnum text-[0.75rem] text-[var(--ink-faint)]">English · A2–B2</span>
              </div>

              <ol class="space-y-3">
                <li
                  v-for="item in landing.stage.roadmapItems"
                  :key="item.index"
                  class="flex items-start gap-3 rounded-2xl border p-3 transition-all"
                  :class="item.status === 'ready'
                    ? 'border-[var(--accent)] bg-[var(--accent-dim)]'
                    : 'border-[var(--line)] bg-[var(--surface)]'"
                >
                  <span
                    class="tnum mt-0.5 grid size-7 shrink-0 place-items-center rounded-full text-[0.75rem] font-semibold"
                    :class="{
                      'bg-[var(--accent)] text-[var(--accent-ink)]': item.status === 'ready',
                      'bg-[color-mix(in_oklab,var(--accent)_20%,transparent)] text-[var(--accent)]': item.status === 'completed',
                      'bg-[var(--surface-sunken)] text-[var(--ink-faint)]': item.status === 'locked',
                    }"
                  >
                    {{ item.status === 'completed' ? '✓' : item.index }}
                  </span>

                  <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                      <span class="label">{{ item.category }}</span>
                      <span
                        v-if="item.status === 'ready'"
                        class="rounded-full bg-[var(--accent)] px-2 py-0.2 text-[0.625rem] font-semibold text-[var(--accent-ink)]"
                      >
                        いまここ
                      </span>
                    </div>
                    <p class="truncate text-[0.9375rem] font-medium">{{ item.title }}</p>
                    <p v-if="item.prompt" class="mt-1 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
                      {{ item.prompt }}
                    </p>
                  </div>
                </li>
              </ol>
            </section>

            <!-- SCENE 4 CARD: Live AI Voice Conversation -->
            <section
              class="cinema-card absolute inset-x-0 mx-auto max-w-lg space-y-3.5 rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_94%,transparent)] p-5 shadow-2xl backdrop-blur-xl sm:p-6"
              :class="{ 'is-active': activeChapterIndex === 3 }"
              :inert="activeChapterIndex !== 3"
            >
              <div class="flex items-center justify-between border-b border-[var(--line)] pb-3">
                <div class="flex items-center gap-2">
                  <span class="size-2 rounded-full bg-[var(--color-sage-400)]" />
                  <span class="text-[0.8125rem] font-medium">AI講師が参加中 · Second Conditional</span>
                </div>
                <span class="tnum font-mono text-[0.8125rem] text-[var(--accent)]">{{ simulatedSessionClock }}</span>
              </div>

              <div class="space-y-2.5 text-[0.875rem]">
                <div class="rounded-2xl bg-[var(--surface)] px-3.5 py-2.5">
                  <p class="label text-[var(--accent)]">AI COACH</p>
                  <p class="mt-0.5 text-[var(--ink)]">{{ landing.stage.dialogue.aiPrompt }}</p>
                </div>

                <div class="ml-6 rounded-2xl border border-[var(--line-strong)] bg-[var(--surface-sunken)] px-3.5 py-2.5">
                  <p class="label">YOU (SPOKEN)</p>
                  <p class="mt-0.5 text-[var(--ink-muted)]">{{ landing.stage.dialogue.userReply }}</p>
                </div>

                <div class="rounded-2xl bg-[var(--surface)] px-3.5 py-2.5">
                  <p class="label text-[var(--accent)]">AI COACH</p>
                  <p class="mt-0.5 text-[var(--ink)]">{{ landing.stage.dialogue.aiFollowup }}</p>
                </div>
              </div>
            </section>

            <!-- SCENE 5 CARD: Mistake Reflection & SM-2 Spaced Repetition -->
            <section
              class="cinema-card absolute inset-x-0 mx-auto max-w-lg space-y-4 rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_95%,transparent)] p-5 shadow-2xl backdrop-blur-xl sm:p-6"
              :class="{ 'is-active': activeChapterIndex === 4 }"
              :inert="activeChapterIndex !== 4"
            >
              <div class="space-y-2.5">
                <div>
                  <p class="label">あなたの発話</p>
                  <p class="text-[0.875rem] text-[var(--ink-faint)] line-through decoration-[var(--color-clay-400)]">
                    {{ landing.stage.reflection.youSaid }}
                  </p>
                </div>

                <div>
                  <p class="label">正しい形</p>
                  <p class="text-[0.9375rem] font-medium text-[var(--color-sage-400)]">
                    {{ landing.stage.reflection.correction }}
                  </p>
                </div>

                <p class="border-l-2 border-[var(--accent)] pl-3 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
                  {{ landing.stage.reflection.explanation }}
                </p>
              </div>

              <!-- SM-2 Retention Timeline -->
              <div class="border-t border-[var(--line)] pt-3">
                <p class="label mb-2">SM-2 間隔反復スケジュール</p>
                <div class="grid grid-cols-5 gap-1.5 text-center">
                  <div
                    v-for="slot in landing.stage.reflection.schedule"
                    :key="slot.label"
                    class="rounded-xl border px-2 py-1.5 text-[0.75rem]"
                    :class="slot.active
                      ? 'border-[var(--accent)] bg-[var(--accent-dim)] font-semibold text-[var(--accent)]'
                      : 'border-[var(--line)] bg-[var(--surface)] text-[var(--ink-faint)]'"
                  >
                    <p class="text-[0.625rem]">{{ slot.label }}</p>
                    <p class="tnum mt-0.5">{{ slot.interval }}</p>
                  </div>
                </div>
              </div>
            </section>
          </div>
        </div>

        <!-- Bottom Video-Style Chapter Scrubber Bar -->
        <div class="relative z-20 mx-auto w-full max-w-6xl px-5 pb-5 sm:px-6 sm:pb-7">
          <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-[var(--line)] bg-[color-mix(in_oklab,var(--surface-strong)_82%,transparent)] p-2 backdrop-blur-xl">
            <div class="flex flex-wrap items-center gap-1 sm:gap-1.5" role="tablist" aria-label="ストーリーチャプター">
              <button
                v-for="(chapter, idx) in landing.chapters"
                :key="chapter.id"
                type="button"
                role="tab"
                :aria-selected="activeChapterIndex === idx"
                class="flex items-center gap-2 rounded-xl px-3 py-1.5 text-[0.75rem] font-medium transition-all sm:text-[0.8125rem]"
                :class="activeChapterIndex === idx
                  ? 'bg-[var(--accent)] text-[var(--accent-ink)] shadow-sm'
                  : 'text-[var(--ink-muted)] hover:bg-[var(--surface)] hover:text-[var(--ink)]'"
                @click="scrollToChapter(idx)"
              >
                <span class="tnum font-mono text-[0.6875rem] opacity-75">{{ chapter.index }}</span>
                <span>{{ chapter.shortTitle }}</span>
              </button>
            </div>

            <button
              type="button"
              class="ml-auto inline-flex items-center gap-2 rounded-xl border border-[var(--line-strong)] px-3 py-1.5 font-mono text-[0.75rem] text-[var(--ink-muted)] transition hover:border-[var(--accent)] hover:text-[var(--accent)]"
              @click="toggleAutoplay"
            >
              <span>{{ autoplaying ? '❚❚ PAUSE' : '▶ AUTOPLAY' }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- The Scroll Driver Track (#tracked): 5 tall sections that drive --tl-1 .. --tl-5 -->
      <div id="tracked" ref="trackedContainer" class="relative z-10 -mt-[calc(100dvh-4rem)]">
        <section
          v-for="chapter in landing.chapters"
          :key="chapter.id"
          class="scrolly-step pointer-events-none h-[95dvh]"
          :aria-label="chapter.title"
        />
      </div>
    </section>

    <LandingMethod />

    <LandingPlayground v-model:state="playgroundState" v-model:energy="playgroundEnergy" />

    <LandingPrivacy />

    <LandingCta />
  </div>
</template>

<style>
/*
 * Global rather than `scoped`: two of the marketing sections below the cinema
 * track now render in their own components (LandingMethod, LandingPrivacy), and
 * a scoped rule compiled in this SFC would carry this component's data attribute
 * and never reach the `.scroll-reveal-card` elements those children render. The
 * rest of these rules target markup that still lives here, so one global block
 * replaces what would otherwise have to be split across two. Every selector
 * below is unique to this landing page (`#tracked`, `#animated`, `.scrolly-root`,
 * `.scrolly-step`, `.cinema-card`, `.cinema-copy-*`, `.parallax-wrapper`,
 * `.parallax-layer`, `.scroll-reveal-card`), so nothing leaks past it.
 */

/*
 * CSS Scroll-Driven Animations (`scrollytelling`, `parallax-scroll-effects`,
 * and `scroll-entry-exit-effects`).
 *
 * `timeline-scope` hoists the named view timelines declared in `#tracked` so the
 * sticky stage in `#animated` can bind to them directly.
 */
.scrolly-root {
  timeline-scope: --tl-1, --tl-2, --tl-3, --tl-4, --tl-5, --hero-wrapper;
}

/*
 * Sequenced scene transitions in `#animated` and the left narrative column:
 * Outgoing cards fade out immediately (140ms, 0ms delay) and become hidden,
 * while the incoming active card waits 120ms before gliding in over 260ms.
 * This prevents two translucent cards from overlapping during scroll or chapter jumps.
 */
#animated .cinema-card {
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  z-index: 1;
  transform: translateY(14px) scale(0.96);
  transition:
    opacity 140ms cubic-bezier(0.4, 0, 1, 1) 0ms,
    transform 140ms cubic-bezier(0.4, 0, 1, 1) 0ms,
    visibility 0ms linear 140ms;
}

#animated .cinema-card.is-active {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  z-index: 10;
  transform: translateY(0) scale(1);
  transition:
    opacity 260ms cubic-bezier(0.22, 1, 0.36, 1) 120ms,
    transform 280ms cubic-bezier(0.22, 1, 0.36, 1) 120ms,
    visibility 0ms linear 0ms;
}

.cinema-copy-enter-active {
  transition:
    opacity 220ms cubic-bezier(0.22, 1, 0.36, 1),
    transform 220ms cubic-bezier(0.22, 1, 0.36, 1);
}

.cinema-copy-leave-active {
  transition:
    opacity 120ms cubic-bezier(0.4, 0, 1, 1),
    transform 120ms cubic-bezier(0.4, 0, 1, 1);
}

.cinema-copy-enter-from {
  opacity: 0;
  transform: translateY(10px);
}

.cinema-copy-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

@keyframes parallax-drift {
  from {
    transform: translateY(var(--offset, 0px));
  }
  to {
    transform: translateY(calc(var(--offset, 0px) * -0.35));
  }
}

@keyframes card-reveal-in {
  from {
    opacity: 0.15;
    transform: translateY(28px) scale(0.94);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@keyframes card-reveal-out {
  from {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  to {
    opacity: 0.25;
    transform: translateY(-18px) scale(0.96);
  }
}

@media (prefers-reduced-motion: no-preference) {
  @supports ((animation-timeline: view()) and (animation-range: entry)) {
    .parallax-wrapper {
      view-timeline: --hero-wrapper block;
    }

    .parallax-layer.layer-1 {
      --offset: -16px;
    }
    .parallax-layer.layer-2 {
      --offset: 12px;
    }
    .parallax-layer.layer-3 {
      --offset: 24px;
    }

    .parallax-layer {
      animation: parallax-drift linear both;
      animation-timeline: --hero-wrapper;
    }

    .scroll-reveal-card {
      animation:
        card-reveal-in auto linear backwards,
        card-reveal-out auto linear forwards;
      animation-timeline: view();
      animation-range: entry 10% cover 32%, exit 68% exit 100%;
    }
  }

  @supports ((animation-timeline: scroll()) and (animation-range: 0% 100%)) {
    #tracked .scrolly-step:nth-child(1) {
      view-timeline: --tl-1 block;
    }
    #tracked .scrolly-step:nth-child(2) {
      view-timeline: --tl-2 block;
    }
    #tracked .scrolly-step:nth-child(3) {
      view-timeline: --tl-3 block;
    }
    #tracked .scrolly-step:nth-child(4) {
      view-timeline: --tl-4 block;
    }
    #tracked .scrolly-step:nth-child(5) {
      view-timeline: --tl-5 block;
    }
  }
}

/* Mandatory accessibility override for motion-sensitive users */
@media (prefers-reduced-motion: reduce) {
  .parallax-layer,
  .scroll-reveal-card,
  #animated .cinema-card,
  .cinema-copy-enter-active,
  .cinema-copy-leave-active {
    animation: none !important;
    transition: none !important;
    transform: none !important;
    translate: 0 0 !important;
  }
}
</style>
