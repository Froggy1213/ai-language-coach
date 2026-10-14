/**
 * Resolves the theme before the first paint of the app shell.
 *
 * The value is read from storage in an inline `<head>` script (see
 * `nuxt.config.ts`) so the correct canvas colour is already on `<html>` when the
 * body paints; this plugin only syncs the composable's state with what that
 * script decided, so a later toggle starts from the truth rather than a default.
 */
export default defineNuxtPlugin(() => {
  const { ensureResolved } = useTheme()

  ensureResolved()
})
