import type { Client, CombinedError } from '@urql/vue'
import type Echo from 'laravel-echo'

declare module '#app' {
  interface NuxtApp {
    $urql: Client
    $echo: Echo
  }
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $urql: Client
    $echo: Echo
  }
}

export type { CombinedError }
