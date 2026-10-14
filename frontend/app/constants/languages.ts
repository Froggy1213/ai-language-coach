/**
 * Japanese language labels for the target-language picker.
 *
 * Source of truth for backend supported languages:
 * backend/config/languages.php (`supported`).
 */

/** Display names written the way a Japanese learner expects to read them. */
export const LANGUAGE_NAMES: Record<string, string> = {
  en: '英語',
  de: 'ドイツ語',
  es: 'スペイン語',
  fr: 'フランス語',
}

/**
 * Target language codes offered at registration.
 * Source of truth: backend/config/languages.php ('supported' => ['en']).
 */
export const SUPPORTED_LANGUAGES: readonly string[] = ['en'] as const

/**
 * Returns the Japanese name for a language code, falling back to the code.
 *
 * @param code - Language code (e.g. 'en').
 * @returns Japanese language name, or the code when unknown.
 */
export function languageOptionLabel(code: string): string {
  return LANGUAGE_NAMES[code] ?? code
}

/**
 * Returns a display name with its code, e.g. "英語 (en)".
 *
 * @param code - Language code, or null/undefined.
 * @returns Formatted display name, the raw code, or "—" when empty.
 */
export function languageDisplayName(code: string | null | undefined): string {
  if (!code) {
    return '—'
  }

  const name = LANGUAGE_NAMES[code]

  return name ? `${name} (${code})` : code
}
