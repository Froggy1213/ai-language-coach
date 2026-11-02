import { computed, type Ref } from 'vue'
import type { OrbState } from '~/composables/useConversation'
import { landing } from '~/constants/strings'

/**
 * Cinema choreography for the cinematic landing page.
 *
 * Pure derivation: scroll progress, the active chapter and the scroll velocity
 * go in; the narrative values (transcript reveal, clocks, orb choreography, the
 * current chapter) come out. Nothing here touches the DOM, so the film's script
 * can be read — and changed — without the scroll engine in view.
 *
 * @param trackProgress - Normalized 0..1 progress through the track.
 * @param activeChapterIndex - Active chapter index (0..4).
 * @param scrollVelocityBoost - Extra amplitude from recent scrolling.
 */
export function useLandingCinema({
  trackProgress,
  activeChapterIndex,
  scrollVelocityBoost,
}: {
  trackProgress: Ref<number>
  activeChapterIndex: Ref<number>
  scrollVelocityBoost: Ref<number>
}) {
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

  return {
    transcriptWords,
    visibleWordCount,
    simulatedSessionClock,
    cinemaTimecode,
    stageOrbState,
    stageOrbEnergy,
    currentChapter,
  }
}
