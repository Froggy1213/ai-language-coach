import type { Client, CombinedError } from '@urql/vue'

declare module '#app' {
  interface NuxtApp {
    $urql: Client
  }
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $urql: Client
  }
}

export type { CombinedError }
