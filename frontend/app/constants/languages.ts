/**
 * Single client-side dictionary for supported and known target languages.
 * Source of truth for backend supported languages:
 * /Users/hikki/My_projects/Ai_language_coach/backend/config/languages.php (`supported`)
 */

export const LANGUAGE_NAMES: Record<string, string> = {
  en: 'Английский',
  de: 'Немецкий',
  es: 'Испанский',
  fr: 'Французский',
}

/**
 * Target language codes offered at registration.
 * Source of truth: backend/config/languages.php ('supported' => ['en'])
 */
export const SUPPORTED_LANGUAGES: readonly string[] = ['en'] as const

/**
 * Returns the plain Russian name for a language code, falling back to the code.
 *
 * @param code - Language code (e.g. 'en')
 * @returns Plain Russian language name, or the code if unknown
 */
export function languageOptionLabel(code: string): string {
  return LANGUAGE_NAMES[code] ?? code
}

/**
 * Returns a formatted Russian display name with code (e.g. 'Английский (en)').
 * Preserves '—' for missing values and falls back to the raw code for unknown languages.
 *
 * @param code - Language code or null/undefined
 * @returns Formatted display name, raw code, or '—' if undefined/empty
 */
export function languageDisplayName(code: string | null | undefined): string {
  if (!code) {
    return '—'
  }

  const name = LANGUAGE_NAMES[code]
  return name ? `${name} (${code})` : code
}
