import { errors as errorsCopy } from '~/constants/strings'

/**
 * Structural shape of a GraphQL error or Urql CombinedError.
 */
export interface GraphQLErrorLike {
  message?: string
  networkError?: Error | unknown
  response?: { status?: number } | unknown
  graphQLErrors?: ReadonlyArray<{
    message?: string
    extensions?: {
      code?: string
      category?: string
      validation?: Record<string, string[]>
      [key: string]: unknown
    }
  }>
}

function isObject(val: unknown): val is Record<string, unknown> {
  return typeof val === 'object' && val !== null
}

function getResponseStatus(error: Record<string, unknown>): number | null {
  const response = isObject(error.response) ? error.response : undefined
  return typeof response?.status === 'number' ? response.status : null
}

function graphQLErrorIsInternal(error: Record<string, unknown>): boolean {
  const graphQLErrors = Array.isArray(error.graphQLErrors) ? error.graphQLErrors : []
  const first = graphQLErrors[0]
  const extensions = isObject(first) && isObject(first.extensions) ? first.extensions : undefined
  return extensions?.category === 'internal'
}

function isRateLimitMessage(message: string | null): boolean {
  if (!message) {
    return false
  }
  return /^(?:\[GraphQL\]\s*|\[\w+\]\s*)?Rate limit\b/i.test(message)
    || /too many (?:requests|attempts)/i.test(message)
}

function isInternalServerMessage(message: string | null): boolean {
  if (!message) {
    return false
  }
  return /^(?:\[(?:Network|GraphQL)\]\s*)?Internal server error\.?$/i.test(message)
}

function isNetworkMessage(message: string | null): boolean {
  if (!message) {
    return false
  }
  return /^\[Network\]/i.test(message)
    || /^(?:Failed to fetch|Load failed|NetworkError\b|fetch failed)/i.test(message)
}

const JAPANESE_CHAR_RE = /[\u3040-\u30ff\u3400-\u4dbf\u4e00-\u9fff]/

/**
 * A message worth showing the user.
 *
 * Lighthouse reports a failed validation as a generic "Validation failed for
 * the field [login]." plus a per-field map, so the field messages are what the
 * form should display; everything else falls back to the error itself.
 */
export function graphQLErrorMessage(error: GraphQLErrorLike | unknown): string | null {
  if (!isObject(error)) {
    return null
  }

  const graphQLErrors = Array.isArray(error.graphQLErrors) ? error.graphQLErrors : []
  const first = graphQLErrors[0]
  const extensions = isObject(first) && isObject(first.extensions) ? first.extensions : undefined
  const validation = extensions && isObject(extensions.validation) ? (extensions.validation as Record<string, string[]>) : undefined
  const fieldMessage = validation ? Object.values(validation)[0]?.[0] : undefined

  const firstMsg = isObject(first) && typeof first.message === 'string' ? first.message : undefined
  const topMsg = typeof error.message === 'string' ? error.message : undefined

  return fieldMessage ?? firstMsg ?? topMsg ?? null
}

/**
 * Returns the first GraphQL error's extensions.code, if present.
 */
export function graphQLErrorCode(error: GraphQLErrorLike | unknown): string | null {
  if (!isObject(error)) {
    return null
  }

  const graphQLErrors = Array.isArray(error.graphQLErrors) ? error.graphQLErrors : []
  const first = graphQLErrors[0]
  const extensions = isObject(first) && isObject(first.extensions) ? first.extensions : undefined

  return typeof extensions?.code === 'string' ? extensions.code : null
}

/**
 * True when the server rejected the *input* rather than the request.
 *
 * Lighthouse marks these with `extensions.category = 'validation'`, and their
 * message text is generated server-side — in the server's language, which is not
 * the interface language. Detecting them lets a caller show its own copy instead
 * of rendering a sentence the learner may not read.
 */
export function graphQLErrorIsValidation(error: GraphQLErrorLike | unknown): boolean {
  if (!isObject(error)) {
    return false
  }

  const graphQLErrors = Array.isArray(error.graphQLErrors) ? error.graphQLErrors : []
  const first = graphQLErrors[0]
  const extensions = isObject(first) && isObject(first.extensions) ? first.extensions : undefined
  const validation = extensions && isObject(extensions.validation) ? extensions.validation : undefined

  return extensions?.category === 'validation' || Boolean(validation)
}


/**
 * Returns the first GraphQL error's `extensions` object, if present.
 *
 * Billing failures carry numbers in extensions (`usedSeconds`,
 * `includedSeconds`, `resetsAt`) because the paywall renders them; parsing
 * those out of the message would be guesswork.
 */
export function graphQLErrorExtensions(error: GraphQLErrorLike | unknown): Record<string, unknown> | null {
  if (!isObject(error)) {
    return null
  }

  const graphQLErrors = Array.isArray(error.graphQLErrors) ? error.graphQLErrors : []
  const first = graphQLErrors[0]
  const extensions = isObject(first) && isObject(first.extensions) ? first.extensions : undefined

  return extensions ?? null
}

/** The one message that must never depend on an index lookup, so it stays total. */
export const AUTH_EXPIRED_MESSAGE = errorsCopy.authExpired

export const DEFAULT_GRAPHQL_ERROR_MESSAGES: Record<string, string> = {
  UNAUTHENTICATED: AUTH_EXPIRED_MESSAGE,
  REVIEW_ITEM_NOT_FOUND: errorsCopy.reviewItemNotFound,
  GRAMMAR_POINT_NOT_FOUND: errorsCopy.grammarPointNotFound,
  CONFIRMATION_REQUIRED: errorsCopy.confirmationRequired,
  PASSWORD_MISMATCH: errorsCopy.passwordMismatch,
  PASSWORD_RESET_INVALID: errorsCopy.passwordResetInvalid,
  EMAIL_VERIFICATION_INVALID: errorsCopy.emailVerificationInvalid,
  EMAIL_VERIFICATION_EXPIRED: errorsCopy.emailVerificationExpired,
  VOICE_CONSENT_REQUIRED: errorsCopy.voiceConsentRequired,
  SCENARIO_DRAFT_FAILED: errorsCopy.scenarioDraftFailed,
  SCENARIO_NOT_FOUND: errorsCopy.scenarioNotFound,
  BILLING_CANCEL_FAILED: errorsCopy.billingCancelFailed,
}

/**
 * Translates a GraphQL CombinedError into a user-facing Japanese error string.
 *
 * Resolution order:
 * 1. Checks `graphQLErrorCode(error)` in `{ ...DEFAULT_GRAPHQL_ERROR_MESSAGES, ...options.codes }`.
 * 2. Inspects the raw server message: an exact `Unauthenticated.` (Laravel
 *    Lighthouse guards emit this without an error code) maps to shared copy,
 *    and explicit `options.messages` mappings run before generic validation
 *    fallback so domain-specific validation rules (such as `voice-errors.ts`)
 *    remain reachable.
 * 3. Maps HTTP 401, `@throttle` / HTTP 429 rate-limit responses, HTTP 5xx /
 *    internal server errors, and network transport failures (`[Network] ...`)
 *    to Japanese strings from `constants/strings.ts`.
 * 4. When the server rejected the input (`extensions.category === 'validation'`),
 *    returns the caller's `fallback` instead of English server validation text.
 * 5. Returns an already-localized Japanese message if present, or the fallback.
 *
 * Never returns null or undefined.
 */
export function graphQLErrorMessageFor(
  error: GraphQLErrorLike | unknown,
  options?: {
    codes?: Record<string, string>
    messages?: Record<string, string>
    fallback?: string
  },
): string {
  const fallback = options?.fallback ?? errorsCopy.fallback

  if (!isObject(error)) {
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

  const status = getResponseStatus(error)

  if (status === 401) {
    return mergedCodes.UNAUTHENTICATED ?? AUTH_EXPIRED_MESSAGE
  }

  if (status === 429 || isRateLimitMessage(serverMessage)) {
    return errorsCopy.rateLimited
  }

  if ((status !== null && status >= 500) || graphQLErrorIsInternal(error) || isInternalServerMessage(serverMessage)) {
    return errorsCopy.serverError
  }

  if (Boolean(error.networkError) || isNetworkMessage(serverMessage)) {
    return errorsCopy.network
  }

  if (graphQLErrorIsValidation(error)) {
    return fallback
  }

  if (serverMessage && JAPANESE_CHAR_RE.test(serverMessage)) {
    return serverMessage
  }

  return fallback
}
