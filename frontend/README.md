# Kirie — Frontend

Nuxt 4 Single-Page Application (SPA, `ssr: false`) for the English-speaking coach.

The learner-facing interface: a daily home screen, the personalised roadmap, live voice
conversation with the AI tutor, spaced-repetition review, post-conversation feedback,
and account settings.

## Who this is for

The interface language is **Japanese**. The *learning* content — grammar point titles,
practice prompts, cheat sheets, mistake corrections — is **English** and comes from the
API. Never translate learning content client-side; if a string is in
`app/constants/strings.ts` it is chrome, and if it comes from GraphQL it is content.

Interface copy follows plain polite Japanese (です・ます). Microcopy conventions:
success messages are past tense (`保存しました`), errors are blameless requests
(`〜してください`), and no emoji appear on product surfaces.

## Stack & Architecture

- **Framework**: Nuxt 4 in SPA mode (`ssr: false` in `nuxt.config.ts`, as auth session cookies live in the browser).
- **Styling**: Tailwind CSS v4 via `@tailwindcss/vite` (`app/assets/css/main.css`).
- **Auth**: Laravel Sanctum SPA session cookies via double-submit CSRF handshake (`app/utils/csrf-fetch.ts`).
- **API**: GraphQL via `@urql/vue` (`app/plugins/urql.ts`), installed both as `$urql` and into Vue's `provide`/`inject`.
- **Realtime**: Laravel Reverb WebSockets via `laravel-echo` and `pusher-js` (`app/plugins/echo.ts`), orchestrated through `app/composables/useLighthouseSubscription.ts`.
- **Voice**: WebRTC streaming to self-hosted LiveKit via `livekit-client`
  (`app/composables/useVoiceSession.ts`), with Web Audio analysis layered on top
  (`app/composables/useConversation.ts`).

## Design system

Two files hold the whole visual language.

### `app/assets/css/main.css`

- **Palette.** One warm graphite neutral ramp (`--color-ink-*`) and exactly one
  chromatic accent (`--color-aqua-*`), plus semantic signals (amber, clay, sage). No
  blue-grey slates, no indigo. Colours are authored in `oklch()`.
- **Semantic layer.** Components reference *roles* (`--canvas`, `--surface`,
  `--ink-muted`, `--line`, `--accent`), never ramp stops. The light theme is one block
  of overrides on `.theme-light`, not a fork of the stylesheet.
- **Type.** Latin faces (Newsreader for display, Inter for UI, JetBrains Mono for
  formulas) come from Google Fonts. Japanese glyphs are deliberately left to the
  **system** stack — Hiragino / Yu Gothic / Noto Sans JP ship with their platforms, so
  kanji and kana render natively instead of through a multi-megabyte webfont.
- **Japanese type rules.** Body line-height is 1.75 (the Digital Agency Design System
  asks for at least 1.5, 1.7 standard) and letter-spacing is positive or zero —
  negative tracking breaks the glyph grid and is never used.
- **`.grain`.** A fixed SVG-noise overlay at ~3.5% opacity. It is what stops large flat
  areas from looking like an unstyled canvas.
- **`.field-input`.** The one text input. It lives in CSS rather than a Tailwind string
  in a `<script setup>` constant, because the scanner only reads class attributes and a
  string constant silently loses its styles.

### Theme

Dark by default (spoken practice happens in the evening and the orb needs something to
glow against). `useTheme()` resolves a stored preference, else `prefers-color-scheme`,
and `nuxt.config.ts` injects a small `<head>` script that applies the class **before
first paint** — without it the dark canvas flashes white on every load in light mode.

Canvas code that resolves themed tokens to RGB must listen for
`THEME_CHANGE_EVENT`, because those values are cached.

## The conversation orb

`app/components/ConversationOrb.vue` is Canvas 2D, not WebGL, and that is deliberate:
the effect is *light*, not geometry. Stacking radial gradients under a `screen`
composite produces additive bloom that no single blurred element can fake, at one draw
call per layer.

Three passes: a **contact shadow** so the sphere sits on the surface, a **body** whose
radius is displaced by summed sine harmonics, and then — switched to `screen` — a
**halo**, a **specular** highlight, and drifting **motes**.

Design rules that are load-bearing:

- **State is carried by speed, deformation and glow, not by hue.** Hue stays inside the
  aqua family and only `error` leaves it, because colour-only state coding is invisible
  to colour-blind learners. Every state also differs in size and motion character.
- **Amplitude is smoothed asymmetrically** (attack ≈ 0.09s, release ≈ 0.3s). Raw FFT
  data jitters frame to frame and an orb that flickers reads as broken.
- **Nothing is allowed to reach the canvas edge.** The halo is scaled by the distance
  available along the diagonal and the shadow is clamped to the space left below the
  sphere; a gradient that runs off the edge is clipped into a visible rectangle.
- **Two silent traps** are documented in `resolveColor()`: `ctx.fillStyle = 'var(--x)'`
  is ignored outright, and `getComputedStyle().color` returns *authored* text for a
  custom property (`oklch(...)`), not `rgb()`.
- `prefers-reduced-motion` collapses the motion to a near-static sphere while leaving
  audio-driven brightness intact.

`/dev/orb` renders every state side by side with an energy slider. It is a design
harness — not linked from the app, but kept because tuning the orb blind is guesswork.

## Screens

| Route | File | Job |
|---|---|---|
| `/` | `pages/index.vue` | **今日** Daily entry point: the next lesson, and the three numbers worth acting on. |
| `/roadmap` | `pages/roadmap.vue` | **ロードマップ** The learning path as a vertical rail; only the current lesson expands. |
| `/talk`, `/talk/:id` | `pages/talk/[[id]].vue` | **会話** One task: speak. The orb is both the visualisation and the control. |
| `/reflect/:id` | `pages/reflect/[[id]].vue` | **振り返り** Mistakes as corrections, grouped by grammar point. |
| `/review` | `pages/review.vue` | **復習** Spaced repetition; one 0–5 decision per item. |
| `/level` | `pages/level.vue` | **レベル判定** CEFR placement recording: consent → record → result. |
| `/settings` | `pages/settings.vue` | **設定** Account, theme, session, data deletion. |
| `/login`, `/register` | `pages/login.vue`, `pages/register.vue` | Auth. |

Legacy routes kept as redirects: `/practice/:cardId` → `/talk/:cardId`,
`/session/:id` → `/reflect/:id`.

**One page, one job.** The split exists because talking and reviewing have opposite
needs: conversation wants no text on screen at all, and review wants the mistakes and
nothing else. Splitting them is what lets each be quiet. If you are adding a section,
first ask whether it belongs on a screen of its own.

## Layout conventions

- The shell (`app/app.vue`) is a capped column with a **persistent bottom tab bar** on
  phones and a sticky header on desktop (≥768px). Five tabs, always-visible labels, at
  least 44px tall.
- **No tab is ever disabled.** A locked lesson shows an explaining empty state on its own
  screen instead — a disabled control teaches nothing.
- Pages that are a single task (the conversation, the auth forms) opt out of the page
  header entirely.

## Setup

Install dependencies:

```bash
npm ci
```

*(Note: peer dependencies are managed via `frontend/.npmrc`).*

### Environment Variables

Configuration is declared in `nuxt.config.ts` under `runtimeConfig.public` and can be overridden via `frontend/.env` (see `frontend/.env.example`):

| Variable | Default | Purpose |
|---|---|---|
| `NUXT_PUBLIC_BACKEND_URL` | `http://localhost:8000` | Base URL of the Laravel API backend. |
| `NUXT_PUBLIC_REVERB_APP_KEY` | `""` | Reverb application key for Pusher protocol. |
| `NUXT_PUBLIC_REVERB_HOST` | `localhost` | Reverb WebSocket host. |
| `NUXT_PUBLIC_REVERB_PORT` | `8080` | Reverb WebSocket port (`8081` in Docker `--profile full`). |
| `NUXT_PUBLIC_REVERB_SCHEME` | `http` | Reverb protocol scheme (`http` or `https`). |
| `NUXT_PUBLIC_LIVEKIT_URL` | `ws://localhost:7880` | LiveKit Server WebRTC signaling endpoint. |

## Scripts

| Command | Purpose |
|---|---|
| `npm run dev` | Starts Vite local development server on `http://localhost:3000`. |
| `npm run build` | Compiles production SPA build (`nuxt build`). |
| `npm run preview` | Previews the compiled production build locally (`nuxt preview`). |
| `npm run codegen` | Generates `app/types/graphql.ts` from backend GraphQL SDL and enums (`graphql-codegen --config codegen.ts`). |
| `npm run typecheck` | Runs Vue and TypeScript type checking (`nuxt typecheck` via `vue-tsc`). |

## Directory Map

```
frontend/
├── app/
│   ├── assets/css/        # Design tokens + base + components (main.css)
│   ├── components/        # UI primitives and feature components
│   │                      #   ConversationOrb.vue / ConversationStage.vue / TabBar.vue
│   │                      #   RoadmapLesson.vue / MistakeItem.vue / CheatSheetPanel.vue
│   │                      #   Ui{Button,ButtonLink,Field,Notice,Progress,Spinner}.vue
│   ├── composables/       # useAuth, useAssessment, useConversation, useElapsedTimer,
│   │                      #   useLighthouseSubscription, usePrivacy, useReviews,
│   │                      #   useSessionFeedback, useTheme, useVoiceSession
│   ├── constants/         # strings.ts (ALL interface copy), languages.ts
│   ├── graphql/           # GraphQL queries, mutations, subscriptions (documents.ts)
│   ├── middleware/        # Global navigation route guard (auth.global.ts)
│   ├── pages/             # File-based SPA routes (see the Screens table above)
│   ├── plugins/           # Client plugins (echo.ts, urql.ts, theme.client.ts)
│   ├── types/             # graphql.ts (generated), view-models.ts, nuxt.d.ts
│   └── utils/             # csrf-fetch.ts, format.ts (Japanese formatters),
│                          #   graphql-error.ts
├── codegen.ts             # GraphQL Code Generator configuration
├── nuxt.config.ts         # Nuxt configuration, runtimeConfig, fonts, theme bootstrap
└── package.json           # Dependencies and scripts
```

## Conventions

- **Code formatting**: 2-space indentation, single quotes (`'`), and no semicolons (`;`).
- **Nuxt auto-imports**: Utilities in `app/utils/`, composables in `app/composables/`,
  and components in `app/components/` are auto-imported.
- **All interface copy lives in `app/constants/strings.ts`.** A Japanese string inline in
  a template is a bug: it is the one place a translator (or a reviewer checking tone) has
  to look.
- **Generated GraphQL types**: `app/types/graphql.ts` is fully generated by GraphQL Code
  Generator (`npm run codegen`). **Never edit this file manually.**
- **GraphQL error mapping**: All user-facing error messages are resolved through
  `graphQLErrorMessageFor(error, { codes, messages, fallback })`
  (`app/utils/graphql-error.ts`). It also detects server-side *validation* failures
  (`extensions.category === 'validation'`) and prefers the caller's `fallback` for them,
  because the server writes validation text in its own language, not the interface's.
- **Lighthouse subscriptions**: Realtime subscriptions must always be opened through
  `app/composables/useLighthouseSubscription.ts`, which handles waiting for the Pusher
  socket ID, awaiting Echo channel authorization, unwrapping Lighthouse's
  `{ more, result }` envelope, and tearing down cleanly on scope disposal.
- **Echo connects at bootstrap, on purpose.** `new Echo()` dials Reverb inside its
  constructor and pusher-js offers no "build the client but do not dial" switch (its
  `enabled` key controls *transports*, not the connection). A stub-client workaround
  exists but means forging an object Echo only touches through `any`. The accepted cost
  is one connection per page load, and — only when Reverb is not running — one browser
  console error per navigation. `e2e/helpers.ts` tolerates exactly that message, and
  nothing else.
- **`UiButton` vs `UiButtonLink`**: NuxtLink is an anchor, not a button. Never wrap one
  inside the other — nested interactive elements are invalid HTML that browsers silently
  repair in surprising ways.
