import type { CombinedError } from '@urql/vue'

/**
 * A message worth showing the user.
 *
 * Lighthouse reports a failed validation as a generic "Validation failed for
 * the field [login]." plus a per-field map, so the field messages are what the
 * form should display; everything else falls back to the error itself.
 */
export function graphQLErrorMessage(error: CombinedError | null | undefined): string | null {
  if (!error) {
    return null
  }

  const extensions = error.graphQLErrors[0]?.extensions as { validation?: Record<string, string[]> } | undefined
  const fieldMessage = extensions?.validation ? Object.values(extensions.validation)[0]?.[0] : undefined

  return fieldMessage ?? error.graphQLErrors[0]?.message ?? error.message
}

/**
 * Returns the first GraphQL error's extensions.code, if present.
 */
export function graphQLErrorCode(error: CombinedError | null | undefined): string | null {
  if (!error) {
    return null
  }

  const extensions = error.graphQLErrors[0]?.extensions as { code?: string } | undefined

  return extensions?.code ?? null
}

/**
 * True when the server rejected the *input* rather than the request.
 *
 * Lighthouse marks these with `extensions.category = 'validation'`, and their
 * message text is generated server-side — in the server's language, which is not
 * the interface language. Detecting them lets a caller show its own copy instead
 * of rendering a sentence the learner may not read.
 */
export function graphQLErrorIsValidation(error: CombinedError | null | undefined): boolean {
  if (!error) {
    return false
  }

  const extensions = error.graphQLErrors[0]?.extensions as
    | { category?: string, validation?: Record<string, string[]> }
    | undefined

  return extensions?.category === 'validation' || Boolean(extensions?.validation)
}

/** The one message that must never depend on an index lookup, so it stays total. */
export const AUTH_EXPIRED_MESSAGE = 'セッションの有効期限が切れました。もう一度ログインしてください。'

export const DEFAULT_GRAPHQL_ERROR_MESSAGES: Record<string, string> = {
  UNAUTHENTICATED: AUTH_EXPIRED_MESSAGE,
}

/**
 * Translates a GraphQL CombinedError into a user-facing Japanese error string.
 *
 * Resolution order:
 * 1. Checks `graphQLErrorCode(error)` in `{ ...DEFAULT_GRAPHQL_ERROR_MESSAGES, ...options.codes }`.
 * 2. When the server rejected the input and the caller supplied a `fallback`,
 *    returns that fallback instead of the server's own sentence — validation text
 *    is written in the server's language, not the interface's.
 * 3. Inspects the raw server message: an exact `Unauthenticated.` (Laravel
 *    Lighthouse guards emit this without an error code) maps to shared copy.
 * 4. Looks the raw server message up in `options.messages`, for the handful of
 *    backend messages that carry no code but still deserve their own wording.
 * 5. Otherwise returns the server message, or the fallback when there is none.
 *
 * Never returns null or undefined.
 */
export function graphQLErrorMessageFor(
  error: CombinedError | null | undefined,
  options?: {
    codes?: Record<string, string>
    messages?: Record<string, string>
    fallback?: string
  },
): string {
  const fallback = options?.fallback ?? 'リクエストを完了できませんでした。しばらくしてからお試しください。'

  if (!error) {
    return fallback
  }

  const mergedCodes = { ...DEFAULT_GRAPHQL_ERROR_MESSAGES, ...options?.codes }
  const code = graphQLErrorCode(error)

  if (code && mergedCodes[code]) {
    return mergedCodes[code]
  }

  if (options?.fallback && graphQLErrorIsValidation(error)) {
    return options.fallback
  }

  const serverMessage = graphQLErrorMessage(error)

  if (serverMessage === 'Unauthenticated.') {
    return mergedCodes.UNAUTHENTICATED ?? AUTH_EXPIRED_MESSAGE
  }

  const mappedMessage = serverMessage ? options?.messages?.[serverMessage] : undefined

  if (mappedMessage) {
    return mappedMessage
  }

  if (serverMessage) {
    return serverMessage
  }

  return fallback
}
