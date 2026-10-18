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

const scrollyRoot = ref<HTMLElement | null>(null)
const heroWrapper = ref<HTMLElement | null>(null)
const trackedContainer = ref<HTMLElement | null>(null)

/** Normalized 0..1 progress through the 5-act scrollytelling cinema track. */
const trackProgress = ref(0)
/** Active chapter index (0..4) derived from scroll position. */
const activeChapterIndex = ref(0)
/** Scroll velocity boost so the orb ripples dynamically when the user scrolls. */
const scrollVelocityBoost = ref(0)
/** Auto-play state for users who want to watch the scroll story like a video. */
const autoplaying = ref(false)

/** Interactive Orb Playground state (below the cinema track). */
const playgroundState = ref<OrbState>('speaking')
const playgroundEnergy = ref(0.72)

const transcriptWords = computed(() => landing.stage.userTranscript.split(' '))

/** How many words of the spoken transcript should be revealed at the current scroll point. */
const visibleWordCount = computed(() => {
  // Scene 2 spans roughly progress 0.18 -> 0.40
  const local = Math.max(0, Math.min(1, (trackProgress.value - 0.17) / 0.18))
  return Math.max(3, Math.ceil(local * transcriptWords.value.length))
})

/** Simulated session clock (00:08 -> 00:54) during Scene 4 (Converse). */
const simulatedSessionClock = computed(() => {
  const local = Math.max(0, Math.min(1, (trackProgress.value - 0.56) / 0.2))
  const seconds = Math.round(8 + local * 46)
  return `00:${String(seconds).padStart(2, '0')}`
})

/** Video-style timecode (`00:00` .. `01:00`) for the cinema timeline bar. */
const cinemaTimecode = computed(() => {
  const totalSec = Math.round(trackProgress.value * 60)
  const mins = Math.floor(totalSec / 60)
  const secs = totalSec % 60
  return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
})

/** Orb state choreographed to the 5 chapters of the scroll film. */
const stageOrbState = computed<OrbState>(() => {
  switch (activeChapterIndex.value) {
    case 0:
      return trackProgress.value > 0.06 ? 'listening' : 'idle'
    case 1:
      return 'listening'
    case 2:
      return 'thinking'
    case 3:
      return trackProgress.value < 0.65 ? 'thinking' : 'speaking'
    case 4:
      return 'speaking'
    default:
      return 'idle'
  }
})

/** Orb audio energy choreographed to the current chapter + scroll velocity. */
const stageOrbEnergy = computed(() => {
  const baseByChapter = [0.35, 0.78, 0.42, 0.86, 0.52][activeChapterIndex.value] ?? 0.35
  const wave = Math.sin(trackProgress.value * Math.PI * 24) * 0.16
  return Math.max(0.12, Math.min(1, baseByChapter + wave + scrollVelocityBoost.value))
})

const currentChapter = computed(
  () => landing.chapters[activeChapterIndex.value] ?? landing.chapters[0]!,
)

function scrollToChapter(index: number): void {
  stopAutoplay()
  const container = trackedContainer.value
  if (!container) {
    return
  }

  const headerOffset = 64
  const rect = container.getBoundingClientRect()
  const containerPageTop = rect.top + window.scrollY
  const scrollableDistance = Math.max(1, rect.height - (window.innerHeight - headerOffset))
  const chapterCount = landing.chapters.length
  const targetProgress = index === 0 ? 0.02 : (index + 0.35) / chapterCount

  window.scrollTo({
    top: containerPageTop - headerOffset + targetProgress * scrollableDistance,
    behavior: 'smooth',
  })
}

let autoplayRaf = 0
let lastAutoplayTime = 0

function stopAutoplay(): void {
  autoplaying.value = false
  if (autoplayRaf) {
    cancelAnimationFrame(autoplayRaf)
    autoplayRaf = 0
  }
}

function toggleAutoplay(): void {
  if (autoplaying.value) {
    stopAutoplay()
    return
  }

  const container = trackedContainer.value
  if (!container) {
    return
  }

  // If the user hasn't reached the cinema track or already finished it, align to the start of the track.
  const rect = container.getBoundingClientRect()
  if (trackProgress.value >= 0.98 || rect.top > window.innerHeight * 0.5) {
    window.scrollTo({
      top: window.scrollY + rect.top - 64,
      behavior: 'smooth',
    })
  }

  autoplaying.value = true
  lastAutoplayTime = performance.now()

  const step = (now: number): void => {
    if (!autoplaying.value) {
      return
    }

    const dt = Math.min(0.05, (now - lastAutoplayTime) / 1000)
    lastAutoplayTime = now

    // Scroll smoothly at ~195px/sec through the story
    window.scrollBy(0, dt * 195)

    if (trackProgress.value >= 0.995) {
      stopAutoplay()
      return
    }

    autoplayRaf = requestAnimationFrame(step)
  }

  autoplayRaf = requestAnimationFrame(step)
}

onMounted(() => {
  let lastScrollY = window.scrollY
  let decayTimer: ReturnType<typeof setTimeout> | null
  const cleanupFns: Array<() => void> = []

  const updateScrollDirector = (): void => {
    const scrollY = window.scrollY
    const dy = Math.abs(scrollY - lastScrollY)
    lastScrollY = scrollY

    scrollVelocityBoost.value = Math.min(0.35, dy / 90)
    if (decayTimer) {
      clearTimeout(decayTimer)
    }
    decayTimer = setTimeout(() => {
      scrollVelocityBoost.value = 0
    }, 120)

    const container = trackedContainer.value
    if (!container) {
      return
    }

    const headerOffset = 64
    const rect = container.getBoundingClientRect()
    const scrollableDistance = Math.max(1, rect.height - (window.innerHeight - headerOffset))
    const rawProgress = (headerOffset - rect.top) / scrollableDistance
    const clamped = Math.max(0, Math.min(1, rawProgress))
    trackProgress.value = clamped

    const chapterCount = landing.chapters.length
    const idx = Math.min(chapterCount - 1, Math.floor(clamped * chapterCount))
    activeChapterIndex.value = idx
  }

  window.addEventListener('scroll', updateScrollDirector, { passive: true })
  window.addEventListener('resize', updateScrollDirector, { passive: true })
  window.addEventListener('wheel', stopAutoplay, { passive: true })
  window.addEventListener('touchstart', stopAutoplay, { passive: true })
  updateScrollDirector()

  cleanupFns.push(() => {
    window.removeEventListener('scroll', updateScrollDirector)
    window.removeEventListener('resize', updateScrollDirector)
    window.removeEventListener('wheel', stopAutoplay)
    window.removeEventListener('touchstart', stopAutoplay)
    if (decayTimer) {
      clearTimeout(decayTimer)
    }
  })

  // Fallback for browsers that do not support CSS Scroll-Driven Animations
  // (`scrollytelling`, `parallax-scroll-effects`, and `scroll-entry-exit-effects` guides).
  const supportsViewTimeline =
    typeof CSS !== 'undefined'
    && CSS.supports('(animation-timeline: view()) and (animation-range: entry)')
  const supportsScrollTimeline =
    typeof CSS !== 'undefined'
    && CSS.supports('(animation-timeline: scroll()) and (animation-range: 0% 100%)')

  if (!supportsScrollTimeline && trackedContainer.value) {
    const animatedSections = document.querySelectorAll<HTMLElement>('#animated .cinema-card')
    const trackedSections = trackedContainer.value.querySelectorAll<HTMLElement>('.scrolly-step')

    const scrollyObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          const sectionIndex = Array.from(trackedSections).indexOf(entry.target as HTMLElement)
          if (sectionIndex !== -1 && animatedSections[sectionIndex]) {
            const animatedSection = animatedSections[sectionIndex]!
            const ratio = entry.intersectionRatio
            animatedSection.style.opacity = String(Math.min(1, ratio * 1.6))
            animatedSection.style.transform = `scale(${0.88 + ratio * 0.12}) translateY(${(1 - ratio) * 24}px)`
          }
        })
      },
      { threshold: Array.from({ length: 21 }, (_, i) => i / 20) },
    )

    trackedSections.forEach((section) => scrollyObserver.observe(section))
    cleanupFns.push(() => scrollyObserver.disconnect())
  }

  if (!supportsViewTimeline) {
    // Parallax fallback for hero layers
    const wrapper = heroWrapper.value
    if (wrapper) {
      const layers = wrapper.querySelectorAll<HTMLElement>('.parallax-layer')
      const onHeroScroll = (): void => {
        const scrollY = window.scrollY
        const wrapperRect = wrapper.getBoundingClientRect()
        const wrapperTop = wrapperRect.top + scrollY
        const wrapperHeight = wrapperRect.height
        const windowHeight = window.innerHeight

        if (scrollY >= wrapperTop - windowHeight && scrollY <= wrapperTop + wrapperHeight) {
          const scrollPercent =
            (scrollY - (wrapperTop - windowHeight)) / (wrapperHeight + windowHeight)
          layers.forEach((layer, index) => {
            const initialTranslateY = 14 * index
            const translateY = initialTranslateY * (1 - scrollPercent)
            layer.style.transform = `translateY(${translateY}px)`
          })
        }
      }

      const heroObserver = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              window.addEventListener('scroll', onHeroScroll, { passive: true })
            } else {
              window.removeEventListener('scroll', onHeroScroll)
            }
          })
        },
        { threshold: 0 },
      )

      heroObserver.observe(wrapper)
      onHeroScroll()
      cleanupFns.push(() => {
        heroObserver.disconnect()
        window.removeEventListener('scroll', onHeroScroll)
      })
    }

    // Entry/exit reveal fallback for cards below the fold
    const revealCards = document.querySelectorAll<HTMLElement>('.scroll-reveal-card')
    const revealObserver = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          const target = entry.target as HTMLElement
          const ratio = Math.min(1, entry.intersectionRatio * 1.35)
          target.style.opacity = String(0.25 + ratio * 0.75)
          target.style.scale = String(0.92 + ratio * 0.08)
        }
      },
      { threshold: Array.from({ length: 21 }, (_, i) => i / 20) },
    )

    revealCards.forEach((el) => revealObserver.observe(el))
    cleanupFns.push(() => revealObserver.disconnect())
  }

  onBeforeUnmount(() => {
    stopAutoplay()
    cleanupFns.forEach((fn) => fn())
  })
})
</script>

<template>
  <div ref="scrollyRoot" class="scrolly-root relative">
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
          <div class="space-y-4 sm:space-y-5">
            <div class="inline-flex items-center gap-2 rounded-full bg-[var(--accent-dim)] px-3 py-1 font-mono text-[0.6875rem] font-semibold tracking-[0.14em] text-[var(--accent)]">
              <span>{{ currentChapter.tag }}</span>
            </div>

            <h2
              :key="currentChapter.id"
              class="display rise text-[clamp(1.625rem,3.4vw,2.65rem)] leading-[1.18] tracking-[-0.01em]"
            >
              {{ currentChapter.title }}
            </h2>

            <p
              :key="`${currentChapter.id}-body`"
              class="rise max-w-[44ch] text-[0.9375rem] leading-[1.85] text-[var(--ink-muted)] sm:text-[1rem]"
            >
              {{ currentChapter.body }}
            </p>

            <p class="hidden border-l-2 border-[var(--accent)] pl-3.5 text-[0.8125rem] leading-relaxed text-[var(--ink-faint)] sm:block">
              {{ currentChapter.caption }}
            </p>
          </div>

          <!-- Right column: The Animated Scrollytelling Stage (#animated) -->
          <div
            id="animated"
            class="relative flex min-h-[22rem] w-full items-center justify-center sm:min-h-[27rem]"
          >
            <!-- Persistent Live Orb Backplane that scales & shifts per scene without clipping -->
            <div
              class="pointer-events-none absolute transition-all duration-700 ease-out"
              :class="[
                activeChapterIndex === 0 ? 'top-0 size-60 sm:size-72 opacity-100 scale-100' : '',
                activeChapterIndex === 1 ? 'top-1 right-2 size-40 sm:size-48 opacity-90 scale-95' : '',
                activeChapterIndex === 2 ? 'bottom-1 right-2 size-36 sm:size-44 opacity-45 scale-90' : '',
                activeChapterIndex === 3 ? 'top-0 left-1/2 -translate-x-1/2 size-48 sm:size-56 opacity-95 scale-100' : '',
                activeChapterIndex === 4 ? 'bottom-1 right-4 size-32 sm:size-40 opacity-40 scale-90' : '',
              ]"
            >
              <ConversationOrb :state="stageOrbState" :energy="stageOrbEnergy" />
            </div>

            <!-- SCENE 1 CARD: Prologue — Acoustic Awakening -->
            <section
              class="cinema-card absolute inset-x-0 mx-auto max-w-lg rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_76%,transparent)] p-5 shadow-2xl backdrop-blur-xl transition-all duration-500 sm:p-6"
              :class="activeChapterIndex === 0 ? 'pointer-events-auto translate-y-24 sm:translate-y-28 opacity-100 scale-100' : 'pointer-events-none translate-y-10 opacity-0 scale-90'"
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
              class="cinema-card absolute inset-x-0 mx-auto max-w-lg space-y-4 rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_88%,transparent)] p-5 shadow-2xl backdrop-blur-xl transition-all duration-500 sm:p-6"
              :class="activeChapterIndex === 1 ? 'pointer-events-auto translate-y-0 opacity-100 scale-100' : 'pointer-events-none translate-y-8 opacity-0 scale-90'"
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
              class="cinema-card absolute inset-x-0 mx-auto max-w-lg rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_90%,transparent)] p-5 shadow-2xl backdrop-blur-xl transition-all duration-500 sm:p-6"
              :class="activeChapterIndex === 2 ? 'pointer-events-auto translate-y-0 opacity-100 scale-100' : 'pointer-events-none translate-y-8 opacity-0 scale-90'"
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
              class="cinema-card absolute inset-x-0 mx-auto max-w-lg space-y-3.5 rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_86%,transparent)] p-5 shadow-2xl backdrop-blur-xl transition-all duration-500 sm:p-6"
              :class="activeChapterIndex === 3 ? 'pointer-events-auto translate-y-12 opacity-100 scale-100' : 'pointer-events-none translate-y-8 opacity-0 scale-90'"
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
              class="cinema-card absolute inset-x-0 mx-auto max-w-lg space-y-4 rounded-3xl border border-[var(--line-strong)] bg-[color-mix(in_oklab,var(--canvas-raised)_92%,transparent)] p-5 shadow-2xl backdrop-blur-xl transition-all duration-500 sm:p-6"
              :class="activeChapterIndex === 4 ? 'pointer-events-auto translate-y-0 opacity-100 scale-100' : 'pointer-events-none translate-y-8 opacity-0 scale-90'"
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

    <!-- =======================================================================
         WHY KIRIE WORKS (#method) — Scroll Entry/Exit Cards
         ======================================================================= -->
    <section id="method" class="mx-auto max-w-6xl space-y-12 border-t border-[var(--line)] px-5 py-24 sm:px-6 md:py-32">
      <div class="max-w-2xl space-y-3">
        <p class="label text-[var(--accent)]">{{ landing.pillars.eyebrow }}</p>
        <h2 class="display text-[clamp(1.75rem,3.5vw,2.75rem)] leading-tight">
          {{ landing.pillars.title }}
        </h2>
        <p class="text-[1rem] leading-[1.8] text-[var(--ink-muted)]">
          {{ landing.pillars.subtitle }}
        </p>
      </div>

      <div class="grid gap-6 md:grid-cols-3">
        <article
          v-for="item in landing.pillars.items"
          :key="item.num"
          class="scroll-reveal-card flex flex-col justify-between rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-7"
        >
          <div class="space-y-4">
            <span class="tnum font-mono text-[0.75rem] tracking-[0.14em] text-[var(--accent)]">
              PILLAR {{ item.num }}
            </span>
            <h3 class="display text-[1.375rem] leading-snug">{{ item.title }}</h3>
            <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
              {{ item.body }}
            </p>
          </div>

          <div class="mt-8 border-t border-[var(--line)] pt-5">
            <p class="tnum display text-[2.25rem] leading-none text-[var(--accent)]">{{ item.metric }}</p>
            <p class="mt-1.5 text-[0.8125rem] text-[var(--ink-faint)]">{{ item.metricLabel }}</p>
          </div>
        </article>
      </div>
    </section>

    <!-- =======================================================================
         INTERACTIVE ORB PLAYGROUND (#interactive)
         ======================================================================= -->
    <section id="interactive" class="mx-auto max-w-6xl border-t border-[var(--line)] px-5 py-24 sm:px-6 md:py-32">
      <div class="grid items-center gap-12 rounded-3xl border border-[var(--line-strong)] bg-[var(--surface)] p-6 sm:p-10 lg:grid-cols-[1fr_1fr]">
        <div class="space-y-6">
          <div class="space-y-3">
            <p class="label text-[var(--accent)]">{{ landing.playground.eyebrow }}</p>
            <h2 class="display text-[clamp(1.625rem,3.2vw,2.375rem)] leading-tight">
              {{ landing.playground.title }}
            </h2>
            <p class="text-[0.9375rem] leading-[1.8] text-[var(--ink-muted)]">
              {{ landing.playground.subtitle }}
            </p>
          </div>

          <!-- State selector buttons -->
          <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4" role="group" aria-label="オーブの状態を選択">
            <button
              v-for="item in landing.playground.states"
              :key="item.state"
              type="button"
              class="rounded-2xl border px-3.5 py-2.5 text-left transition"
              :class="playgroundState === item.state
                ? 'border-[var(--accent)] bg-[var(--accent-dim)] text-[var(--accent)]'
                : 'border-[var(--line)] bg-[var(--surface-sunken)] text-[var(--ink-muted)] hover:border-[var(--line-strong)] hover:text-[var(--ink)]'"
              @click="playgroundState = item.state"
            >
              <p class="font-mono text-[0.6875rem] uppercase opacity-75">{{ item.state }}</p>
              <p class="mt-0.5 text-[0.875rem] font-medium">{{ item.label }}</p>
            </button>
          </div>

          <p class="rounded-2xl bg-[var(--surface-sunken)] px-4 py-3 text-[0.875rem] leading-relaxed text-[var(--ink-muted)]">
            {{ landing.playground.states.find((s) => s.state === playgroundState)?.desc }}
          </p>

          <!-- Voice Energy Slider -->
          <label class="block space-y-2">
            <div class="flex items-center justify-between text-[0.8125rem]">
              <span class="text-[var(--ink-muted)]">{{ landing.playground.energyLabel }}</span>
              <span class="tnum font-mono text-[var(--accent)]">{{ Math.round(playgroundEnergy * 100) }}%</span>
            </div>
            <input
              v-model.number="playgroundEnergy"
              type="range"
              min="0.08"
              max="1"
              step="0.02"
              class="w-full accent-[var(--accent)]"
              :aria-label="landing.playground.energyLabel"
            >
          </label>
        </div>

        <div class="flex flex-col items-center justify-center">
          <div class="relative aspect-square w-[min(76vw,22rem)]">
            <div
              class="pointer-events-none absolute inset-[8%] rounded-full border border-[var(--line)] bg-[var(--surface-sunken)] shadow-inner"
              aria-hidden="true"
            />
            <div class="relative z-10 size-full">
              <ConversationOrb :state="playgroundState" :energy="playgroundEnergy" />
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =======================================================================
         PRIVACY BY DESIGN (#privacy)
         ======================================================================= -->
    <section id="privacy" class="mx-auto max-w-6xl border-t border-[var(--line)] px-5 py-20 sm:px-6">
      <div class="scroll-reveal-card rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-6 sm:p-10">
        <div class="max-w-3xl space-y-4">
          <p class="label text-[var(--color-sage-400)]">{{ landing.privacy.eyebrow }}</p>
          <h2 class="display text-[clamp(1.5rem,3vw,2.125rem)] leading-snug">
            {{ landing.privacy.title }}
          </h2>
          <p class="text-[0.9375rem] leading-[1.85] text-[var(--ink-muted)]">
            {{ landing.privacy.body }}
          </p>

          <div class="flex flex-wrap gap-2.5 pt-2">
            <span
              v-for="badge in landing.privacy.badges"
              :key="badge"
              class="inline-flex items-center gap-2 rounded-full border border-[var(--line-strong)] bg-[var(--surface-sunken)] px-3.5 py-1.5 text-[0.8125rem] text-[var(--ink)]"
            >
              <span class="size-1.5 rounded-full bg-[var(--color-sage-400)]" />
              {{ badge }}
            </span>
          </div>
        </div>
      </div>
    </section>

    <!-- =======================================================================
         FINAL CALL TO ACTION
         ======================================================================= -->
    <section class="mx-auto max-w-6xl px-5 pt-8 pb-28 sm:px-6">
      <div class="relative overflow-hidden rounded-3xl border border-[var(--line-strong)] bg-[var(--surface-strong)] px-6 py-14 text-center sm:px-12 sm:py-20">
        <div
          class="pointer-events-none absolute inset-0 opacity-30"
          style="background: radial-gradient(circle at 50% 0%, var(--accent) 0%, transparent 68%)"
          aria-hidden="true"
        />

        <div class="relative z-10 mx-auto max-w-2xl space-y-6">
          <p class="label text-[var(--accent)]">{{ landing.cta.eyebrow }}</p>
          <h2 class="display text-[clamp(1.875rem,4vw,3rem)] leading-tight">
            {{ landing.cta.title }}
          </h2>
          <p class="text-[1rem] leading-[1.8] text-[var(--ink-muted)]">
            {{ landing.cta.subtitle }}
          </p>

          <div class="flex flex-wrap items-center justify-center gap-4 pt-3">
            <UiButtonLink to="/register" variant="primary" size="lg">
              {{ landing.cta.primary }}
            </UiButtonLink>
            <UiButtonLink to="/login" variant="quiet" size="lg">
              {{ landing.cta.secondary }}
            </UiButtonLink>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
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
  #animated .cinema-card {
    animation: none !important;
    transform: none !important;
    translate: 0 0 !important;
  }
}
</style>
