import { getCurrentScope, onScopeDispose, ref, type Ref } from 'vue'

/**
 * Manages a 1-second ticking timer for elapsed session duration.
 *
 * Client-only safe (guards against running during SSR via `import.meta.client`),
 * prevents multiple overlapping intervals on repeated `start()` calls, and cleans
 * up active timer intervals when the enclosing Vue scope is disposed.
 *
 * @param options - Configuration options.
 * @param options.autoStart - If true and running on the client, begins counting immediately.
 * @returns An object containing the elapsed seconds Ref and controls: start, stop, reset.
 */
export function useElapsedTimer(options?: { autoStart?: boolean }): {
  elapsed: Ref<number>
  start: () => void
  stop: () => void
  reset: () => void
} {
  const elapsed = ref(0)
  let timerId: ReturnType<typeof setInterval> | null = null

  function stop(): void {
    if (timerId !== null) {
      clearInterval(timerId)
      timerId = null
    }
  }

  function start(): void {
    if (!import.meta.client || timerId !== null) {
      return
    }

    timerId = setInterval(() => {
      elapsed.value += 1
    }, 1000)
  }

  function reset(): void {
    stop()
    elapsed.value = 0
  }

  if (options?.autoStart && import.meta.client) {
    start()
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      stop()
    })
  }

  return {
    elapsed,
    start,
    stop,
    reset,
  }
}
