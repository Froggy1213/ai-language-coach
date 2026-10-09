import type { CodegenConfig } from '@graphql-codegen/cli'

/**
 * GraphQL schema types for the SPA, generated from the backend SDL.
 *
 * The schema is loaded from `../backend/graphql/**\/*.graphql` — the committed SDL:
 * - `schema.graphql` plus aggregate types under `types/`.
 * - `codegen-enums.graphql` — declarations of the five native PHP enums
 *   (`CefrLevel`, `RoadmapStatus`, `LessonCardStatus`, `AssessmentStatus`,
 *   `VoiceSessionStatus`). These reach GraphQL at runtime through `TypeRegistry`
 *   (`AppServiceProvider`) rather than the served SDL (README decision 6),
 *   while `codegen-enums.graphql` provides them to codegen and IDEs, with
 *   parity enforced by `CodegenEnumsTest`.
 *
 * Loaded as an explicit glob with `skipGraphQLImport`, because `schema.graphql`
 * uses Lighthouse's `#import types/*.graphql` directive, which is not the syntax
 * graphql-tools parses. SDL validation is skipped as well: Lighthouse directives
 * (`@guard`, `@rename`, `@scalar(class:)`, …) are implemented in PHP or built
 * into the framework and are not declared in these files.
 */
const config: CodegenConfig = {
  schema: [
    {
      '../backend/graphql/**/*.graphql': {
        skipGraphQLImport: true,
        assumeValidSDL: true,
      },
    },
  ],
  generates: {
    'app/types/graphql.ts': {
      plugins: [
        {
          add: {
            content: '// GENERATED FILE — DO NOT EDIT. Run `npm run codegen` after changing backend/graphql/**.',
          },
        },
        'typescript',
      ],
      config: {
        // String unions, not TS enums: the SPA compares against the wire values
        // ('active', 'A1') and never needs a runtime enum object.
        enumsAsTypes: true,
        scalars: {
          DateTime: { input: 'string', output: 'string' },
        },
        // Keep the generated types aligned with the rest of the codebase, which
        // writes `T | null` rather than a `Maybe<T>` helper.
        maybeValue: 'T | null',
        avoidOptionals: {
          field: false,
          inputValue: false,
          object: false,
          defaultValue: false,
        },
      },
    },
  },
}

export default config
