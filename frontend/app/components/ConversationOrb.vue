<script setup lang="ts">
import type { OrbState } from '~/composables/useConversation'
import { THEME_CHANGE_EVENT } from '~/composables/useTheme'

/**
 * The conversation orb.
 *
 * Built with Canvas 2D rather than WebGL on purpose: the effect is *light*, not
 * geometry. Stacking radial gradients in a `lighter` composite produces additive
 * bloom that no single blurred div can fake, and it costs one draw call per layer,
 * so a mid-range phone holds 60fps without a shader pipeline or a GL dependency.
 *
 * Three render passes, in order:
 *  1. **halo** — a wide, dim field that expands on loudness. This is what makes the
 *     orb look like it is emitting light rather than sitting on the page.
 *  2. **body** — a closed blob whose radius is displaced by summed sine harmonics.
 *     The harmonic amplitudes track the audio level, so the silhouette ripples when
 *     someone is talking and settles to a slow breath when nobody is.
 *  3. **core** — a small, near-white centre offset toward the light source, which
 *     is what reads as "a sphere" instead of "a circle".
 *
 * Amplitudes are smoothed exponentially with an asymmetric attack/release, because
 * raw FFT data jitters frame to frame and an orb that flickers looks broken.
 */

const props = withDefaults(
  defineProps<{
    state?: OrbState
    /** 0–1 loudness of whoever is speaking. */
    energy?: number
  }>(),
  {
    state: 'idle',
    energy: 0,
  },
)

const canvas = ref<HTMLCanvasElement | null>(null)
const reducedMotion = ref(false)
const visible = ref(true)

/** The live 2D context, published for the small drawing helpers below. */
let surface: CanvasRenderingContext2D | null = null

type Channels = { r: number; g: number; b: number }

/**
 * Resolves a themed CSS colour to RGB and caches it.
 *
 * Two traps live here, and both fail silently rather than throwing:
 *
 *  - `canvas.fillStyle = 'var(--orb-mid)'` is *ignored* — the 2D context is not a
 *    CSS property, so it parses the string itself, does not know `var()`, and
 *    keeps whatever it had. The token has to be resolved to a real colour first.
 *  - `getComputedStyle().color` returns the *authored* text for a custom property
 *    (`oklch(0.79 0.15 197)`), which is not `rgb()`.
 *
 * So: read the token's computed text, paint it onto a probe to force the browser
 * to resolve any `color-mix().`, then hand that resolved string to `fillStyle`,
 * which normalises `oklch()` too. A token that resolves to nothing yields
 * transparent black, so it is detected and replaced rather than turning the orb
 * into a black disc.
 */
const colorCache = new Map<string, Channels>()

function resolveColor(token: string): Channels {
  const cached = colorCache.get(token)

  if (cached) {
    return cached
  }

  let channels: Channels = { r: 96, g: 214, b: 220 }

  if (import.meta.client) {
    const computed = getComputedStyle(document.documentElement).getPropertyValue(token).trim()
    const probe = document.createElement('canvas')
    probe.width = 1
    probe.height = 1
    const context = probe.getContext('2d', { willReadFrequently: true })

    if (computed && context) {
      context.fillStyle = '#010203'
      context.fillStyle = computed

      // Paint once so `color-mix()` has a surface to resolve against, then read
      // the resolved value back out of fillStyle to feed the real paint.
      context.fillRect(0, 0, 1, 1)
      const resolved = context.fillStyle
      context.fillStyle = resolved
      context.fillRect(0, 0, 1, 1)

      const [r, g, b] = context.getImageData(0, 0, 1, 1).data

      if ((r ?? 0) + (g ?? 0) + (b ?? 0) > 0) {
        channels = { r: r ?? 0, g: g ?? 0, b: b ?? 0 }
      }
    }
  }

  colorCache.set(token, channels)

  return channels
}

/**
 * A radial gradient whose radii are guaranteed valid.
 *
 * `createRadialGradient` throws `IndexSizeError` when any radius is negative, and
 * every radius here is derived from a smoothed animation value that genuinely
 * passes through zero while the orb eases between states. A throw inside a
 * `requestAnimationFrame` callback stops the loop for good and leaves a blank
 * canvas — which is precisely what happened before this helper existed. One
 * guarded entry point beats remembering the rule at five call sites.
 */
function radial(
  cx: number,
  cy: number,
  inner: number,
  reach: number,
  y = cy,
): CanvasGradient {
  const safeReach = Math.max(1, reach)
  const safeInner = Math.min(Math.max(0, inner), safeReach - 1)

  return surface!.createRadialGradient(cx, y, safeInner, cx, cy, safeReach)
}

function rgba({ r, g, b }: Channels, alpha: number): string {
  return `rgba(${r}, ${g}, ${b}, ${Math.max(0, Math.min(1, alpha))})`
}

/** Timed envelope with a fast attack and a slower release. */
class Envelope {
  private value = 0

  update(target: number, dt: number): number {
    const rising = target > this.value
    // Time constants in seconds. Attack is deliberately ~3x faster than release:
    // a symmetric envelope reads as "the app is guessing" when someone speaks.
    const tau = rising ? 0.09 : 0.3
    const k = 1 - Math.exp(-dt / tau)
    this.value += (Math.max(0, Math.min(1, target)) - this.value) * k

    return this.value
  }

  get current(): number {
    return this.value
  }

  reset(): void {
    this.value = 0
  }
}

/**
 * Eased scalar used for per-state size. A state change moves the orb's scale over
 * a few hundred milliseconds instead of snapping, which is the difference between
 * "the orb reacted" and "the orb teleported".
 */
class Glide {
  private value: number

  constructor(initial: number) {
    this.value = initial
  }

  update(target: number, dt: number): number {
    const k = 1 - Math.exp(-dt / 0.28)
    this.value += (target - this.value) * k

    return this.value
  }
}

onMounted(() => {
  const element = canvas.value

  if (!element) {
    return
  }

  const context = element.getContext('2d', { alpha: true })

  if (!context) {
    return
  }

  surface = context

  const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
  reducedMotion.value = motionQuery.matches

  // Themed tokens resolve to different RGB values in the light theme, so the
  // cache has to be dropped rather than kept for the life of the page.
  const handleThemeChange = (): void => colorCache.clear()
  window.addEventListener(THEME_CHANGE_EVENT, handleThemeChange)

  const handleMotionChange = (event: MediaQueryListEvent): void => {
    reducedMotion.value = event.matches
  }

  const handleVisibility = (): void => {
    visible.value = !document.hidden
  }

  motionQuery.addEventListener('change', handleMotionChange)
  document.addEventListener('visibilitychange', handleVisibility)
  handleVisibility()

  const level = new Envelope()
  const voice = new Envelope()
  const size = new Glide(0.12)

  let width = 0
  let height = 0
  let dpr = 1
  let frame = 0
  let last = performance.now()
  let elapsed = 0
  let cachedState: OrbState = props.state

  // Orbital motes. They exist to give the empty field around the orb something to
  // do while the agent is thinking — a still screen reads as a frozen app.
  const specks = Array.from({ length: 9 }, (_, index) => ({
    angle: (index / 9) * Math.PI * 2,
    radius: 0.92 + (index % 4) * 0.11,
    speed: 0.06 + (index % 3) * 0.024,
    size: 0.9 + (index % 3) * 0.5,
    alpha: 0.1 + (index % 3) * 0.05,
  }))

  let ripple = 0
  let lastRipple = 0

  function resize(): void {
    const rect = element!.getBoundingClientRect()
    dpr = Math.min(2, window.devicePixelRatio || 1)
    width = Math.max(1, rect.width)
    height = Math.max(1, rect.height)
    element!.width = Math.round(width * dpr)
    element!.height = Math.round(height * dpr)
    context!.setTransform(dpr, 0, 0, dpr, 0, 0)
  }

  const observer = new ResizeObserver(resize)
  observer.observe(element)
  resize()

  /**
   * Per-state motion constants: silhouette deformation, glow reach, animation
   * speed and resting scale.
   *
   * State is communicated by *speed, deformation and glow* — not by hue. Hue is
   * reserved for identity (the aqua family) and for the one state that must be
   * unmissable at a glance (error). Colour-only state coding is invisible to
   * colour-blind learners, so every state also differs in motion and size.
   */
  function motionFor(state: OrbState): {
    displacement: number
    spread: number
    speed: number
    min: number
    max: number
    glow: number
  } {
    if (reducedMotion.value) {
      return { displacement: 0.012, spread: 0.64, speed: 0, min: 0.88, max: 0.94, glow: 0.9 }
    }

    switch (state) {
      case 'speaking':
        return { displacement: 0.085, spread: 0.86, speed: 1.35, min: 0.9, max: 1.12, glow: 1.5 }
      case 'listening':
        return { displacement: 0.055, spread: 0.78, speed: 0.62, min: 0.82, max: 1, glow: 1.35 }
      case 'thinking':
        return { displacement: 0.05, spread: 0.7, speed: 2.4, min: 0.88, max: 0.98, glow: 1.6 }
      case 'error':
        return { displacement: 0.062, spread: 0.64, speed: 3, min: 0.85, max: 0.97, glow: 1.8 }
      default:
        return { displacement: 0.032, spread: 0.68, speed: 0.3, min: 0.76, max: 0.9, glow: 0.85 }
    }
  }

  /** The three palette stops the active state blends across. */
  function paletteFor(state: OrbState): { core: Channels; mid: Channels; deep: Channels } {
    const mid = resolveColor(
      state === 'speaking'
        ? '--orb-speaking'
        : state === 'listening'
          ? '--orb-listening'
          : state === 'thinking'
            ? '--orb-thinking'
            : state === 'error'
              ? '--orb-warn'
              : '--orb-mid',
    )

    return {
      core: resolveColor('--orb-core'),
      mid,
      deep: resolveColor('--orb-deep'),
    }
  }

  function drawHalo(
    cx: number,
    cy: number,
    radius: number,
    palette: ReturnType<typeof paletteFor>,
    spread: number,
    glow: number,
  ): void {
    const halo = resolveColor('--orb-halo')
    const stops = [0.9, 1.05, 1.25, 1.48, 1.72, 1.95]

    // The tile is square but the light is round, so a fixed radius would run past
    // the top and bottom edges and get clipped into a rectangle — which reads as
    // a rendering bug, not as light. Scaling the whole gradient by the distance
    // available along the *inscribed circle* keeps its shape circular while
    // guaranteeing the outermost stop lands safely inside the canvas.
    const slack
      = ((Math.min(width, height) * 0.485) / Math.max(radius, 1)) / stops[stops.length - 1]!

    for (let i = 0; i < stops.length; i += 1) {
      const reach = radius * stops[i]! * Math.min(1, slack)
      const falloff = (i / (stops.length - 1)) ** (1.5 - spread * 0.6)
      // The last stop is the outer limit of the light and is faded to nothing
      // below, so no ring ever lands on the canvas boundary.
      const alpha = (0.115 - i * 0.017) * (0.6 + level.current * 0.8) * glow
        * (0.55 + falloff * 0.7)
        * (1 - (i / (stops.length - 1)) ** 3)
      const gradient = radial(cx, cy, radius * 0.5, reach)
      gradient.addColorStop(0, rgba(i < 2 ? palette.mid : halo, alpha))
      gradient.addColorStop(0.42, rgba(halo, alpha * 0.5))
      gradient.addColorStop(0.75, rgba(halo, alpha * 0.16))
      gradient.addColorStop(1, rgba(halo, 0))
      context!.fillStyle = gradient
      context!.beginPath()
      context!.arc(cx, cy, reach, 0, Math.PI * 2)
      context!.fill()
    }
  }

  function drawBody(
    cx: number,
    cy: number,
    radius: number,
    palette: ReturnType<typeof paletteFor>,
    displacement: number,
    t: number,
  ): Float32Array {
    const points = 128
    // The displaced silhouette is returned so the rim light can trace the same
    // path. Stroking the base radius instead left a visible ring floating
    // outside the shape whenever a wave pushed the surface outward.
    const silhouette = new Float32Array((points + 1) * 2)

    const lit = Math.max(1, radius)
    const gradient = radial(cx - lit * 0.3, cy, lit * 0.05, lit, cy - lit * 0.34)
    gradient.addColorStop(0, rgba(palette.core, 0.95))
    gradient.addColorStop(0.22, rgba(palette.mid, 0.98))
    gradient.addColorStop(0.66, rgba(palette.mid, 0.62))
    gradient.addColorStop(0.88, rgba(palette.deep, 0.92))
    gradient.addColorStop(1, rgba(palette.deep, 0.58))

    context!.beginPath()

    for (let i = 0; i <= points; i += 1) {
      const angle = (i / points) * Math.PI * 2
      const wave =
        Math.sin(angle * 3 + t * 0.9) * 0.22
        + Math.sin(angle * 5 - t * 1.25) * 0.28
        + Math.sin(angle * 8 + t * 0.7) * 0.26
        + Math.sin(angle * 13 - t * 1.5) * 0.16
        + Math.sin(angle * 21 + t * 2.1) * 0.08
      const r = radius * (1 + wave * displacement)
      const x = cx + Math.cos(angle) * r
      const y = cy + Math.sin(angle) * r

      silhouette[i * 2] = x
      silhouette[i * 2 + 1] = y

      if (i === 0) {
        context!.moveTo(x, y)
      } else {
        context!.lineTo(x, y)
      }
    }

    context!.closePath()
    context!.fillStyle = gradient
    context!.fill()

    return silhouette
  }

  /**
   * A soft contact shadow under the sphere.
   *
   * Without it the orb floats: a bright disc on a light canvas needs somewhere
   * to sit, and the shadow is also what stops the bloom from looking like a
   * smudge on the page.
   */
  function drawShadow(cx: number, cy: number, radius: number): void {
    const y = cy + radius * 1.02
    // Clamped against the space actually left below the sphere, squashed
    // vertically, so the soft edge always finishes inside the canvas.
    const reach = Math.max(1, Math.min(radius * 0.72, (height - y) / 0.16 - 1))
    const gradient = radial(cx, y, 0, reach)
    gradient.addColorStop(0, `rgba(4, 10, 16, ${0.3 + level.current * 0.1})`)
    gradient.addColorStop(0.5, 'rgba(4, 10, 16, 0.1)')
    gradient.addColorStop(1, 'rgba(4, 10, 16, 0)')

    context!.save()
    context!.translate(cx, y)
    context!.scale(1, 0.16)
    context!.translate(-cx, -y)
    context!.fillStyle = gradient
    context!.beginPath()
    context!.arc(cx, y, reach, 0, Math.PI * 2)
    context!.fill()
    context!.restore()
  }

  function drawSpecular(
    cx: number,
    cy: number,
    radius: number,
    palette: ReturnType<typeof paletteFor>,
    silhouette: Float32Array,
  ): void {
    const x = cx - radius * 0.36
    const y = cy - radius * 0.4
    const gradient = radial(x, y, 0, radius * 0.3)
    gradient.addColorStop(0, rgba(palette.core, 0.72))
    gradient.addColorStop(0.42, rgba(palette.core, 0.14))
    gradient.addColorStop(1, rgba(palette.core, 0))
    context!.fillStyle = gradient
    context!.beginPath()
    context!.arc(x, y, radius * 0.3, 0, Math.PI * 2)
    context!.fill()

    // A rim along the lit edge, traced from the silhouette the body just drew.
    // Without it the shape dissolves into its own halo and stops reading as a
    // solid object; drawn on the base radius it would float outside the shape.
    context!.strokeStyle = rgba(palette.core, 0.18 + level.current * 0.22)
    context!.lineWidth = 1.25
    context!.beginPath()

    for (let i = 0; i < silhouette.length; i += 2) {
      const x = silhouette[i]!
      const y = silhouette[i + 1]!

      if (i === 0) {
        context!.moveTo(x, y)
      } else {
        context!.lineTo(x, y)
      }
    }

    context!.closePath()
    context!.stroke()
  }

  /** Expanding wavefront emitted when the learner starts speaking. */
  function drawRipple(cx: number, cy: number, radius: number, palette: ReturnType<typeof paletteFor>): void {
    if (ripple <= 0) {
      return
    }

    const maxReach = Math.min(width, height) * 0.48

    for (let i = 0; i < 2; i += 1) {
      const progress = ripple - i * 0.16

      if (progress <= 0 || progress > 1) {
        continue
      }

      const reach = Math.max(1, Math.min(maxReach, radius * (1.08 + progress * 0.65)))
      const alpha = (1 - progress) ** 2 * 0.28
      context!.strokeStyle = rgba(palette.mid, alpha)
      context!.lineWidth = 1.2 * (1 - progress) + 0.3
      context!.beginPath()
      context!.arc(cx, cy, reach, 0, Math.PI * 2)
      context!.stroke()
    }
  }

  function drawSpecks(cx: number, cy: number, radius: number, palette: ReturnType<typeof paletteFor>, t: number): void {
    const activity = props.state === 'idle' ? 0.55 : 1
    const drift = 1 + level.current * 0.12

    for (const speck of specks) {
      const angle = speck.angle + t * speck.speed * (reducedMotion.value ? 0 : 1)
      const distance = Math.max(0, radius * speck.radius * drift)
      const x = cx + Math.cos(angle) * distance
      const y = cy + Math.sin(angle) * distance * 0.86
      const alpha = speck.alpha * activity * (0.5 + level.current * 0.9)

      context!.fillStyle = rgba(palette.core, alpha)
      context!.beginPath()
      context!.arc(x, y, Math.max(0.1, speck.size * dpr * 0.7), 0, Math.PI * 2)
      context!.fill()
    }
  }

  function draw(now: number): void {
    frame = requestAnimationFrame(draw)

    const rawDt = (now - last) / 1000
    last = now

    // A backgrounded tab resumes with a huge dt; clamp so nothing snaps.
    const dt = Math.min(0.05, Math.max(0.001, rawDt))

    if (!visible.value) {
      return
    }

    elapsed += dt

    if (cachedState !== props.state) {
      cachedState = props.state
      level.reset()
    }

    // Target loudness per state: the learner drives `listening`, the agent drives
    // `speaking`, and everything else gets a slow breathing floor.
    const idleBreath = 0.1 + Math.sin(elapsed * 0.75) * 0.045
    const target =
      cachedState === 'speaking'
        ? Math.max(0.2, props.energy)
        : cachedState === 'listening'
          ? Math.max(idleBreath, props.energy)
          : cachedState === 'thinking'
            ? 0.16 + Math.sin(elapsed * 2.2) * 0.05
            : cachedState === 'error'
              ? 0.08
              : idleBreath

    level.update(target, dt)
    voice.update(props.energy, dt)

    // Trigger a ripple when the learner's voice starts rising, no more than
    // twice a second, so it reads as a reaction rather than a loop.
    if (cachedState === 'listening' && voice.current > 0.16 && elapsed - lastRipple > 0.5) {
      ripple = 0.0001
      lastRipple = elapsed
    }

    if (ripple > 0) {
      ripple += dt / 0.85

      if (ripple > 1.15) {
        ripple = 0
      }
    }

    const cx = width / 2
    const cy = height / 2
    // Leave generous headroom inside the canvas so wave displacement, outer
    // halo stops (up to 1.95x), ripples, and the contact shadow never clip
    // against the rectangular canvas border.
    const base = Math.min(width, height) * 0.275

    if (base < 2) {
      return
    }
    const motion = motionFor(cachedState)
    const palette = paletteFor(cachedState)

    // Radius is a mapped range rather than a raw level: `level` is already
    // smoothed, and the exponent stops a quiet room from flattening the orb into
    // a static circle. Scale glides between states so nothing snaps.
    const scale = size.update(level.current ** 1.1, dt)

    // Every other length in this file is `radius * something`, so a single clamp
    // here is what keeps `arc()` and `createRadialGradient()` from throwing on a
    // negative radius. `Glide` is an exponential smoother and cannot itself go
    // negative, but `base` is zero until the first ResizeObserver pass, and the
    // frame must survive that.
    const radius = Math.max(1, base * (motion.min + scale * (motion.max - motion.min)))
    const phase = elapsed * (0.65 + motion.speed * 0.35)

    context!.clearRect(0, 0, width, height)
    context!.globalCompositeOperation = 'source-over'

    drawShadow(cx, cy, radius)

    // 1. Body next: it is the object. Everything after it is light falling off
    //    that object, which is why the bloom passes switch to `screen`.
    const silhouette = drawBody(
      cx,
      cy,
      radius,
      palette,
      motion.displacement * (0.55 + level.current * 1.1),
      phase,
    )

    context!.globalCompositeOperation = 'screen'
    drawHalo(cx, cy, radius, palette, motion.spread, motion.glow)
    drawRipple(cx, cy, radius, palette)
    drawSpecular(cx, cy, radius, palette, silhouette)
    drawSpecks(cx, cy, radius, palette, elapsed)

    // The thinking state gets a single orbiting tracer: one readable "working"
    // signal beats another spinner. Its orbit is clamped the same way the halo
    // is, so the glow never lands on the canvas edge as a hard cut.
    if (cachedState === 'thinking' && !reducedMotion.value) {
      const angle = elapsed * 1.5
      const headroom = (Math.min(width, height) * 0.5) / Math.max(radius, 1)
      const distance = radius * Math.min(1.42, headroom - 0.36)
      const x = cx + Math.cos(angle) * distance
      const y = cy + Math.sin(angle) * distance * 0.9
      const trail = radial(x, y, 0, radius * 0.34)
      trail.addColorStop(0, rgba(palette.core, 0.5))
      trail.addColorStop(1, rgba(palette.core, 0))
      context!.fillStyle = trail
      context!.beginPath()
      context!.arc(x, y, radius * 0.34, 0, Math.PI * 2)
      context!.fill()
    }

    context!.globalCompositeOperation = 'source-over'
  }

  frame = requestAnimationFrame(draw)

  onBeforeUnmount(() => {
    cancelAnimationFrame(frame)
    observer.disconnect()
    motionQuery.removeEventListener('change', handleMotionChange)
    document.removeEventListener('visibilitychange', handleVisibility)
    window.removeEventListener(THEME_CHANGE_EVENT, handleThemeChange)
  })
})
</script>

<template>
  <canvas ref="canvas" class="block size-full" aria-hidden="true" />
</template>
