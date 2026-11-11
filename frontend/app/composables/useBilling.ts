import { ref } from 'vue'
import { billing as copy, pricing as pricingCopy } from '~/constants/strings'
import {
  BILLING_OVERVIEW_QUERY,
  BILLING_PLANS_QUERY,
  CREATE_BILLING_PORTAL_SESSION_MUTATION,
  CREATE_CHECKOUT_SESSION_MUTATION,
} from '~/graphql/documents'
import type { BillingInterval, BillingOverview, BillingPlan } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'

/**
 * Billing state for the settings block and the pricing screen.
 *
 * Stripe is reached only through the two mutations: this composable receives a
 * hosted URL and hands the browser to it, so no card data and no Stripe.js ever
 * enter the SPA. `overview` is read with `network-only` after a checkout redirect
 * because the webhook, not this tab, is what changed the plan.
 */
export function useBilling() {
  const { $urql } = useNuxtApp()

  const overview = ref<BillingOverview | null>(null)
  const fetching = ref(false)
  const error = ref<string | null>(null)

  const plans = ref<BillingPlan[]>([])
  const plansFetching = ref(false)
  const plansError = ref<string | null>(null)

  const checkingOut = ref<string | null>(null)
  const openingPortal = ref(false)

  async function refresh(): Promise<void> {
    fetching.value = true
    error.value = null

    try {
      const { data, error: failure } = await $urql
        .query<{ billingOverview: BillingOverview }>(BILLING_OVERVIEW_QUERY, {}, { requestPolicy: 'network-only' })
        .toPromise()

      if (failure) {
        error.value = graphQLErrorMessageFor(failure, { fallback: copy.loadError })
        return
      }

      overview.value = data?.billingOverview ?? null
    } finally {
      fetching.value = false
    }
  }

  async function loadPlans(): Promise<void> {
    if (plans.value.length > 0) {
      return
    }

    plansFetching.value = true
    plansError.value = null

    try {
      const { data, error: failure } = await $urql
        .query<{ billingPlans: BillingPlan[] }>(BILLING_PLANS_QUERY, {}, { requestPolicy: 'cache-first' })
        .toPromise()

      if (failure) {
        plansError.value = graphQLErrorMessageFor(failure, { fallback: pricingCopy.checkoutError })
        return
      }

      plans.value = data?.billingPlans ?? []
    } finally {
      plansFetching.value = false
    }
  }

  async function checkout(planCode: string, interval: BillingInterval): Promise<boolean> {
    checkingOut.value = `${planCode}:${interval}`
    error.value = null

    try {
      const { data, error: failure } = await $urql
        .mutation<{ createCheckoutSession: { url: string } }>(CREATE_CHECKOUT_SESSION_MUTATION, {
          planCode,
          interval,
        })
        .toPromise()

      if (failure || !data?.createCheckoutSession.url) {
        error.value = graphQLErrorMessageFor(failure, {
          codes: {
            BILLING_NOT_CONFIGURED: 'このプランは現在お申し込みいただけません。',
            BILLING_PLAN_NOT_FOUND: 'このプランは現在お申し込みいただけません。',
            BILLING_PROVIDER_ERROR: '決済画面を開けませんでした。しばらくしてからお試しください。',
          },
          fallback: pricingCopy.checkoutError,
        })
        return false
      }

      window.location.assign(data.createCheckoutSession.url)
      return true
    } finally {
      checkingOut.value = null
    }
  }

  async function openPortal(): Promise<boolean> {
    openingPortal.value = true
    error.value = null

    try {
      const { data, error: failure } = await $urql
        .mutation<{ createBillingPortalSession: { url: string } }>(CREATE_BILLING_PORTAL_SESSION_MUTATION, {})
        .toPromise()

      if (failure || !data?.createBillingPortalSession.url) {
        error.value = graphQLErrorMessageFor(failure, { fallback: copy.portalError })
        return false
      }

      window.location.assign(data.createBillingPortalSession.url)
      return true
    } finally {
      openingPortal.value = false
    }
  }

  return {
    overview,
    fetching,
    error,
    plans,
    plansFetching,
    plansError,
    checkingOut,
    openingPortal,
    refresh,
    loadPlans,
    checkout,
    openPortal,
  }
}
