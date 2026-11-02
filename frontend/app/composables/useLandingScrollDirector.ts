import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'
import { landing } from '~/constants/strings'

/**
 * Scroll engine for the cinematic landing page.
 *
 * Owns the raw scroll position -> normalized track progress mapping, the chapter
 * lock that keeps a clicked chapter from flashing the scenes in between, the
 * cinema autoplay loop, and every listener/observer this page installs —
 * including the fallback used by browsers without CSS Scroll-Driven Animations.
 *
 * @param trackedContainer - The `#tracked` track whose height defines the story timeline.
 * @param heroWrapper - The hero wrapper that owns the `.parallax-layer` elements.
 */
export function useLandingScrollDirector({
  trackedContainer,
  heroWrapper,
}: {
  trackedContainer: Ref<HTMLElement | null>
  heroWrapper: Ref<HTMLElement | null>
}) {
  /** Normalized 0..1 progress through the 5-act scrollytelling cinema track. */
  const trackProgress = ref(0)
  /** Active chapter index (0..4) derived from scroll position. */
  const activeChapterIndex = ref(0)
  /** Scroll velocity boost so the orb ripples dynamically when the user scrolls. */
  const scrollVelocityBoost = ref(0)
  /** Auto-play state for users who want to watch the scroll story like a video. */
  const autoplaying = ref(false)

  /**
   * When the user clicks a chapter pill in the scrubber bar, lock the active
   * chapter index during the programmatic smooth scroll so intermediate scenes
   * do not flash or overlap along the way.
   */
  let lockedChapterIndex: number | null = null
  let unlockChapterTimer: ReturnType<typeof setTimeout> | null = null

  function clearChapterLock(): void {
    lockedChapterIndex = null
    if (unlockChapterTimer) {
      clearTimeout(unlockChapterTimer)
      unlockChapterTimer = null
    }
  }

  function scrollToChapter(index: number): void {
    stopAutoplay()
    const container = trackedContainer.value
    if (!container) {
      return
    }

    clearChapterLock()
    lockedChapterIndex = index
    activeChapterIndex.value = index
    unlockChapterTimer = setTimeout(() => {
      lockedChapterIndex = null
      unlockChapterTimer = null
    }, 650)

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

    clearChapterLock()
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
      if (lockedChapterIndex !== null) {
        if (idx === lockedChapterIndex) {
          clearChapterLock()
        }
      } else {
        activeChapterIndex.value = idx
      }
    }

    const onUserScrollInput = (): void => {
      clearChapterLock()
      stopAutoplay()
    }

    window.addEventListener('scroll', updateScrollDirector, { passive: true })
    window.addEventListener('resize', updateScrollDirector, { passive: true })
    window.addEventListener('wheel', onUserScrollInput, { passive: true })
    window.addEventListener('touchstart', onUserScrollInput, { passive: true })
    updateScrollDirector()

    cleanupFns.push(() => {
      window.removeEventListener('scroll', updateScrollDirector)
      window.removeEventListener('resize', updateScrollDirector)
      window.removeEventListener('wheel', onUserScrollInput)
      window.removeEventListener('touchstart', onUserScrollInput)
      clearChapterLock()
      if (decayTimer) {
        clearTimeout(decayTimer)
      }
    })

    // Fallback for browsers that do not support CSS Scroll-Driven Animations
    // (`parallax-scroll-effects` and `scroll-entry-exit-effects` guides).
    // Note: `#story` (`#animated .cinema-card`) is driven directly by the 60fps
    // `updateScrollDirector` state machine + non-overlapping `.cinema-card` CSS
    // transitions across all browsers so adjacent `.scrolly-step` intersections
    // never write competing inline opacities onto inactive scene cards.
    const supportsViewTimeline =
      typeof CSS !== 'undefined'
      && CSS.supports('(animation-timeline: view()) and (animation-range: entry)')

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

  return {
    trackProgress,
    activeChapterIndex,
    scrollVelocityBoost,
    autoplaying,
    scrollToChapter,
    stopAutoplay,
    toggleAutoplay,
  }
}
