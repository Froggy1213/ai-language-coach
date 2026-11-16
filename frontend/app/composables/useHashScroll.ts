/**
 * Scrolls to the URL fragment once the page that owns it has mounted.
 *
 * A Nuxt SPA resolves the router's own hash scroll before an async page has
 * painted its sections, so a cold load of `/settings/account#password` lands at
 * the top with the target nowhere in the document. This waits for the first
 * render and then scrolls `scroll-mt-*` into effect like a native anchor.
 */
export function useHashScroll(): void {
  const route = useRoute()

  onMounted(async () => {
    await nextTick()

    const target = route.hash.slice(1)

    if (target) {
      document.getElementById(target)?.scrollIntoView({ block: 'start' })
    }
  })
}
