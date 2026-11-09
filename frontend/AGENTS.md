# Frontend — local laws

Nuxt 4 SPA (`ssr: false`), Vue 3, Tailwind 4, `@urql/vue`. Full reference: [`README.md`](README.md);
design system: [`../docs/design/README.md`](../docs/design/README.md); product context:
[`../AGENTS.md`](../AGENTS.md).

## Routes

`/` · `/roadmap` · `/lesson/{cardId}` · `/talk` · `/talk/{code}` · `/reflect` and
`/reflect/{id}` (optional param) · `/review` · `/level` · `/settings` · `/login` · `/register`
· `/dev/orb` (sandbox, deliberately unlinked).

Legacy addresses are one-line pages calling `navigateTo(..., { redirectCode: 301 })`:
`/onboarding` → `/level`, `/practice/{cardId}` → `/lesson/{cardId}`, `/session/{id}` → `/reflect/{id}`.

## Laws

1. **No Japanese string in a template.** Every user-facing string lives in
   `app/constants/strings.ts`. Learning content is English and comes from the API.
2. **`app/types/graphql.ts` is generated.** Never edit it by hand; run `npm run codegen` after any
   `.graphql` change — CI fails on a stale generated file.
3. **Errors go through `graphQLErrorMessageFor`** (`app/utils/graphql-error.ts`). It prefers the
   caller's `fallback` for `extensions.category === 'validation'`, because server validation text
   is not interface copy.
4. **Subscriptions only through `app/composables/useLighthouseSubscription.ts`** — it owns the
   socket-ID wait, private-channel authorization, the `{ more, result }` envelope and teardown.
5. **`UiButton` vs `UiButtonLink`**: `UiButtonLink` renders an anchor. Never nest one inside the
   other — browsers silently repair invalid nested interactive HTML.
6. **Echo dials inside its constructor, on purpose.** The accepted cost is one connection per page
   load and, with Reverb down, one console error per navigation; `e2e/helpers.ts` tolerates exactly
   that message and nothing else.
7. **Style**: 2-space indent, single quotes, no semicolons.

## Gates

`npm run codegen && git diff --exit-code -- app/types/graphql.ts`, `npm run typecheck`,
`npm run build`.
