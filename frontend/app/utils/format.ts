/**
 * Formats a total duration in seconds into a "mm:ss" clock display.
 * Used for session countdown and elapsed time in onboarding and practice.
 *
 * @example
 * formatClock(0)   // "00:00"
 * formatClock(65)  // "01:05"
 * formatClock(125) // "02:05"
 *
 * @param totalSeconds - Total number of seconds to format.
 * @returns Formatted time string padded as "mm:ss".
 */
export function formatClock(totalSeconds: number): string {
  const safe = Math.max(0, Math.floor(Number.isFinite(totalSeconds) ? totalSeconds : 0))
  const minutes = Math.floor(safe / 60)
  const seconds = safe % 60

  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}

/**
 * Formats a duration in seconds into a Russian human-readable string.
 * Preserves the exact Russian wording emitted by session feedback.
 *
 * @example
 * formatDuration(5)   // "5 сек"
 * formatDuration(60)  // "1 мин"
 * formatDuration(65)  // "1 мин 5 сек"
 * formatDuration(0)   // ""
 *
 * @param seconds - Duration in seconds.
 * @returns Human-readable duration like "1 мин 5 сек", or empty string if <= 0.
 */
export function formatDuration(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds <= 0) {
    return ''
  }

  const total = Math.floor(seconds)
  const mins = Math.floor(total / 60)
  const remainingSec = total % 60

  if (mins === 0) {
    return `${remainingSec} сек`
  }

  return `${mins} мин ${remainingSec > 0 ? `${remainingSec} сек` : ''}`.trim()
}

/**
 * Formats an ISO date string into Russian locale representation.
 * Used for account profile settings (e.g. voice consent timestamp).
 *
 * @example
 * formatDate('2026-10-09T08:30:00Z') // e.g. "09.10.2026, 08:30:00"
 * formatDate('')                     // "Не записано"
 *
 * @param value - ISO date string or empty string.
 * @returns Formatted date string, or 'Не записано' when empty/missing.
 */
export function formatDate(value: string): string {
  if (!value) {
    return 'Не записано'
  }

  try {
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU')
  } catch {
    return value
  }
}

/**
 * Formats a review timestamp relative to now for spaced repetition queue.
 * Dates in the past or missing timestamps yield 'Срок настал'.
 * Future dates format with Russian day, short month, hour, and minute.
 *
 * @example
 * formatReviewDate('')                    // "Срок настал"
 * formatReviewDate('2020-01-01T00:00:00') // "Срок настал"
 * formatReviewDate('2030-05-12T14:30:00') // "12 мая, 14:30"
 *
 * @param value - ISO date string to format.
 * @returns Formatted date string or 'Срок настал'.
 */
export function formatReviewDate(value: string): string {
  if (!value) {
    return 'Срок настал'
  }

  try {
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) {
      return value
    }

    const now = new Date()
    if (d <= now) {
      return 'Срок настал'
    }

    return d.toLocaleDateString('ru-RU', {
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return value
  }
}
