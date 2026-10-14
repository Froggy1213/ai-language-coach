/**
 * Japanese display formatters.
 *
 * The interface language is Japanese while the *learning* content stays English,
 * so these helpers only ever format UI chrome — never learner-facing English text.
 *
 * Numeral handling is deliberately Japanese-native: counters do not inflect, so
 * `count(3, '回')` renders "3回" without any plural logic. Arabic numerals are used
 * throughout because that is the convention in modern Japanese UI.
 */

/** Japanese counters used across the product. */
export const COUNTER = {
  minutes: '分',
  seconds: '秒',
  times: '回',
  items: '件',
  lessons: 'レッスン',
  days: '日',
} as const

/**
 * Formats a total duration in seconds into a "mm:ss" clock display.
 *
 * @example formatClock(65) // "01:05"
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
 * Formats a duration in seconds as a Japanese "X分Y秒" string.
 *
 * @example
 * formatDuration(5)   // "5秒"
 * formatDuration(60)  // "1分"
 * formatDuration(65)  // "1分5秒"
 * formatDuration(0)   // ""
 *
 * @param seconds - Duration in seconds.
 * @returns Japanese duration label, or an empty string when non-positive.
 */
export function formatDuration(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds <= 0) {
    return ''
  }

  const total = Math.floor(seconds)
  const mins = Math.floor(total / 60)
  const rest = total % 60

  if (mins === 0) {
    return `${rest}秒`
  }

  return rest > 0 ? `${mins}分${rest}秒` : `${mins}分`
}

/**
 * Formats an ISO timestamp as a Japanese date and time.
 *
 * @example
 * formatDate('2026-10-09T08:30:00Z') // "2026年10月9日 17:30"
 * formatDate('')                     // "未記録"
 *
 * @param value - ISO date string, or an empty string.
 * @returns Formatted Japanese date-time, or "未記録" when missing/unparsable.
 */
export function formatDate(value: string): string {
  if (!value) {
    return '未記録'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('ja-JP', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

/**
 * Formats a review timestamp relative to now for the spaced-repetition queue.
 * Past or missing timestamps mean the item is already due.
 *
 * @example
 * formatReviewDate('')                    // "今日が復習日"
 * formatReviewDate('2020-01-01T00:00:00') // "今日が復習日"
 * formatReviewDate('2030-05-12T14:30:00') // "5月12日 14:30"
 *
 * @param value - ISO date string to format.
 * @returns Japanese due-date label.
 */
export function formatReviewDate(value: string): string {
  if (!value) {
    return '今日が復習日'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  if (date.getTime() <= Date.now()) {
    return '今日が復習日'
  }

  return new Intl.DateTimeFormat('ja-JP', {
    month: 'numeric',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

/**
 * Counts a value against a Japanese counter, e.g. `3回`.
 * Counters do not inflect in Japanese, so there is no plural branch to pick.
 *
 * @param n - The count.
 * @param counter - Counter suffix, usually one of {@link COUNTER}.
 * @returns A label such as "12レッスン".
 */
export function count(n: number, counter: string): string {
  return `${Math.max(0, Math.floor(n))}${counter}`
}

/**
 * Counts a value against a counter with a unit word placed before it.
 * Japanese puts the noun first and the count after, e.g. "レッスン 12件".
 *
 * @param n - The count.
 * @param counter - Counter suffix.
 * @param unit - Optional noun to prefix, e.g. "レッスン".
 * @returns A label such as "レッスン 12件".
 */
export function countWithUnit(n: number, counter: string, unit?: string): string {
  const value = count(n, counter)

  return unit ? `${unit} ${value}` : value
}

/**
 * Greets the learner according to the local time of day.
 * Plain polite form (丁寧語), which is the register the rest of the UI uses.
 *
 * @param date - Reference time; defaults to now.
 * @returns One of おはようございます / こんにちは / こんばんは.
 */
export function greetingFor(date: Date = new Date()): string {
  const hour = date.getHours()

  if (hour < 5) {
    return 'こんばんは'
  }
  if (hour < 11) {
    return 'おはようございます'
  }
  if (hour < 18) {
    return 'こんにちは'
  }

  return 'こんばんは'
}
