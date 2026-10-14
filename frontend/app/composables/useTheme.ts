/**
 * Theme preference, shared through `useState` so the shell and the settings page
 * read the same value.
 *
 * Dark is the product default — spoken practice happens in the evening and the
 * conversation orb needs a dark field to glow against. A stored preference wins;
 * otherwise we follow the OS setting.
 */

export type ThemeName = 'dark' | 'light'

const STORAGE_KEY = 'kirie:theme'

/**
 * Fired on `window` whenever the theme changes.
 *
 * Canvas code resolves themed tokens to RGB once and caches them, so it has to
 * know when the tokens underneath it changed. An event is the honest mechanism
 * here: the components involved are not in a parent/child relationship, and the
 * alternative — polling the cache on every frame — costs work to notice
 * something that happens twice a session.
 */
export const THEME_CHANGE_EVENT = 'kirie:theme-change'

export function useTheme() {
  const theme = useState<ThemeName>('ui:theme', () => 'dark')
  const resolved = useState<boolean>('ui:theme-resolved', () => false)

  function apply(next: ThemeName): void {
    theme.value = next

    if (!import.meta.client) {
      return
    }

    const root = document.documentElement
    root.classList.toggle('theme-light', next === 'light')
    root.style.colorScheme = next

    try {
      window.localStorage.setItem(STORAGE_KEY, next)
    } catch {
      // Private browsing can refuse storage; the in-memory value still applies.
    }

    window.dispatchEvent(new CustomEvent(THEME_CHANGE_EVENT, { detail: next }))
  }

  function ensureResolved(): void {
    if (resolved.value || !import.meta.client) {
      return
    }

    let stored: string | null = null

    try {
      stored = window.localStorage.getItem(STORAGE_KEY)
    } catch {
      stored = null
    }

    if (stored === 'light' || stored === 'dark') {
      apply(stored)
    } else {
      const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches
      apply(prefersLight ? 'light' : 'dark')
    }

    resolved.value = true
  }

  function toggle(): void {
    apply(theme.value === 'dark' ? 'light' : 'dark')
  }

  return {
    theme,
    ensureResolved,
    set: apply,
    toggle,
  }
}
