import { billing, pricing } from '~/constants/strings'

/**
 * Japanese name of a billing plan.
 *
 * The backend sends a stable plan code, never a label. Known codes map to the
 * pricing copy; an unknown future code falls back to a generic paid-plan label
 * rather than leaking the raw identifier into an otherwise Japanese screen.
 *
 * @param planCode - `free` or a plan code from `billingOverview`.
 * @returns The name shown in the settings hub and the billing screen.
 */
export function planDisplayName(planCode: string | null | undefined): string {
  const code = planCode ?? 'free'

  if (code === 'free') {
    return billing.freePlan
  }

  return pricing.plans[code as keyof typeof pricing.plans]?.name ?? billing.unknownPlan
}
