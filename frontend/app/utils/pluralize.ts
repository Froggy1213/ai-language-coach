/**
 * Selects the appropriate Russian grammatical plural form for a given number.
 *
 * Russian grammatical rules for numerals:
 * - Numbers ending in 11–19 use the third form (genitive plural: forms[2], e.g. 11 дней, 15 ошибок)
 * - Numbers whose last digit is 1 (except 11) use the first form (nominative singular: forms[0], e.g. 1 день, 21 ошибка)
 * - Numbers whose last digit is 2, 3, or 4 (except 12–14) use the second form (paucal / genitive singular: forms[1], e.g. 2 дня, 24 ошибки)
 * - All other numbers (ending in 0, 5–9, etc.) use the third form (genitive plural: forms[2], e.g. 5 дней, 30 ошибок)
 *
 * @param n - The count to pluralize.
 * @param forms - A tuple of three Russian word forms:
 *   - forms[0]: singular nominative (1, 21, 31...) — e.g. 'день', 'повторение', 'ошибка'
 *   - forms[1]: paucal / genitive singular (2–4, 22–24...) — e.g. 'дня', 'повторения', 'ошибки'
 *   - forms[2]: genitive plural (0, 5–20, 25–30...) — e.g. 'дней', 'повторений', 'ошибок'
 * @returns The matching grammatical form from the tuple.
 */
export function pluralize(n: number, forms: readonly [string, string, string]): string {
  const abs = Math.abs(n) % 100
  const rem = abs % 10

  if (abs > 10 && abs < 20) return forms[2]
  if (rem > 1 && rem < 5) return forms[1]
  if (rem === 1) return forms[0]
  return forms[2]
}
