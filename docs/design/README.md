# Kirie — design notes

What the frontend redesign settled on, why, and the parts that are load-bearing enough
that changing them casually will break something.

Reference renders live next to this file: `today-mobile.jpg`, `conversation-mobile.jpg`,
`roadmap-desktop.jpg`, `orb-states-dark.jpg`, `orb-states-light.jpg`,
`settings-mobile.jpg`.

## The brief this answers

The app is for **Japanese speakers learning English**. The interface is Japanese; the
learning content is English. One page does one job. The conversation screen leans on a
single luminous orb that reacts to whoever is talking.

## Design decisions worth stating

**Warm ink, one accent.** The old interface was slate-on-sky, i.e. the default
framework palette. The new one is a warm graphite neutral ramp with exactly one
chromatic accent (aqua) and semantic signals (amber, clay, sage). Warm neutrals read as
crafted rather than defaulted, and they keep the aqua from looking like every other
"AI product" blue. Authored in `oklch()`.

**Japanese type rules are not Latin type rules.** Body line-height is 1.75 rather than
1.4–1.5, letter-spacing is positive or zero, and Japanese glyphs come from the *system*
stack (Hiragino / Yu Gothic / Noto Sans JP). A JP webfont would be several megabytes and
would still lose on metrics to the face the OS already ships. Latin faces — Newsreader
for display, Inter for UI — are the only webfonts, and they lead the stack so Latin
glyphs stay crisp.

**One page, one job.** The old build put the roadmap, the current lesson, the cheat
sheet, the live session, the mistake analysis and the action footer on one screen. Now:
今日 (what is next), ロードマップ (the path), 会話 (talk), 振り返り (what went wrong),
復習 (recall), レベル判定 (placement), 設定 (account). The split is not tidiness for its
own sake — talking and reviewing have *opposite* needs. Conversation wants no text on
screen at all; review wants the mistakes and nothing else. Splitting them is what lets
each one be quiet.

**A native shell, not a website.** Persistent bottom tab bar on phones (five labelled
tabs, ≥44px), sticky header on desktop. No tab is ever disabled: a locked lesson shows
an explaining empty state on its own screen, because a greyed-out control teaches
nothing.

**Dark by default.** Spoken practice happens in the evening and a luminous orb needs
something to glow against. Light mode is a complete second theme, not an afterthought —
it is one block of token overrides on `.theme-light`.

## The orb

`frontend/app/components/ConversationOrb.vue`. Canvas 2D, not WebGL: the effect is
*light*, not geometry. Stacked radial gradients under a `screen` composite produce
additive bloom no single blurred element can fake, at one draw call per layer.

Five states, each differing in **speed, deformation, size and glow** — not in hue. Hue
stays inside the aqua family and only `error` leaves it, because colour-only state coding
is invisible to colour-blind learners.

| State | Motion | Size | Reads as |
|---|---|---|---|
| `idle` | inaudible 3s breath, near-perfect sphere | 0.76 → 0.90 | asleep, inviting a tap |
| `listening` | driven by the **learner's** microphone | 0.82 → 1.00 | attentive |
| `speaking` | driven by the **agent's** output, widest waves | 0.90 → 1.12 | animated |
| `thinking` | fast swirl, an orbiting tracer, no audio input | 0.88 → 0.98 | working |
| `error` | fast, small, warm | 0.85 → 0.97 | something is wrong |

Hard-won details, all of which caused a real visible defect before they were fixed:

- **Amplitude is smoothed asymmetrically** (attack ≈ 0.09 s, release ≈ 0.30 s). Raw FFT
  data jitters frame to frame and an orb that flickers reads as broken.
- **No geometry may reach the canvas edge.** The halo is scaled by the distance
  available along the diagonal and the contact shadow is clamped to the space left below
  the sphere. A gradient that runs off the edge is clipped into a visible *rectangle*,
  which reads as a rendering bug rather than as light.
- **All radii are clamped at the source.** `createRadialGradient` and `arc()` both throw
  on a negative radius, and a throw inside a `requestAnimationFrame` callback stops the
  loop *permanently* — the orb's speaking state rendered as a blank tile until this was
  found. Every gradient now goes through the guarded `radial()` helper.
- **Two silent canvas traps** are documented in `resolveColor()`: `ctx.fillStyle =
  'var(--x)'` is ignored outright (the 2D context is not a CSS property), and
  `getComputedStyle().color` returns *authored* text for a custom property
  (`oklch(...)`), not `rgb()`.
- The orb is a `<button>` — it starts the session — with the canvas hidden from
  assistive tech and a real `aria-label` on the control.
- `prefers-reduced-motion` collapses the motion to a near-static sphere while leaving
  audio-driven brightness intact, so the orb still responds to a voice.

`/dev/orb` renders every state side by side with an energy slider. It is a design
harness, not a product surface: not linked from anywhere, and kept because tuning the
orb blind is guesswork.

## Fonts and first paint

`nuxt.config.ts` injects a small `<head>` script that applies the stored theme class
before the body paints. Without it the dark canvas flashes white on every load in light
mode. Canvas code that caches resolved theme colours must listen for
`THEME_CHANGE_EVENT` from `useTheme()`.

## Where to look first

| Concern | File |
|---|---|
| Tokens, base styles, component classes | `frontend/app/assets/css/main.css` |
| All interface copy (the only place it should live) | `frontend/app/constants/strings.ts` |
| The orb | `frontend/app/components/ConversationOrb.vue` |
| Shell and navigation | `frontend/app/app.vue`, `components/TabBar.vue` |
| Audio analysis behind the orb | `frontend/app/composables/useConversation.ts` |
