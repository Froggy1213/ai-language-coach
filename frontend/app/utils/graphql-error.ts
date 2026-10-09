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

/** The one message that must never depend on an index lookup, so it stays total. */
export const AUTH_EXPIRED_MESSAGE = 'Сессия истекла. Пожалуйста, выполните вход снова.'

export const DEFAULT_GRAPHQL_ERROR_MESSAGES: Record<string, string> = {
  UNAUTHENTICATED: AUTH_EXPIRED_MESSAGE,
}

/**
 * Translates a GraphQL CombinedError into a user-facing Russian error string.
 *
 * Resolution order:
 * 1. Checks `graphQLErrorCode(error)` in `{ ...DEFAULT_GRAPHQL_ERROR_MESSAGES, ...options.codes }`.
 * 2. If no code matches, inspects the raw server message via `graphQLErrorMessage(error)`.
 * 3. When the raw server message is exactly 'Unauthenticated.', returns the UNAUTHENTICATED text.
 *    Laravel Lighthouse guards (e.g. Sanctum authentication middleware) emit an `AuthenticationException`
 *    which serializes to a root GraphQL error with the message 'Unauthenticated.' without an explicit
 *    `extensions.code`. Checking this string ensures expired or unauthenticated sessions are mapped
 *    consistently to the user-friendly Russian message even when the error code is omitted by the backend.
 * 4. Looks the raw server message up in `options.messages`, for the handful of backend messages that
 *    carry no `extensions.code` but still deserve their own Russian wording.
 * 5. Otherwise returns the server message if present, or falls back to `options.fallback`
 *    (defaulting to 'Не удалось выполнить запрос. Попробуйте позже.').
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
  const fallback = options?.fallback ?? 'Не удалось выполнить запрос. Попробуйте позже.'

  if (!error) {
    return fallback
  }

  const mergedCodes = { ...DEFAULT_GRAPHQL_ERROR_MESSAGES, ...options?.codes }
  const code = graphQLErrorCode(error)

  if (code && mergedCodes[code]) {
    return mergedCodes[code]
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
