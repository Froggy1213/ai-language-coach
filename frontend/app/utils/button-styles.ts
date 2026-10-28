export type ButtonVariant = 'primary' | 'quiet' | 'ghost' | 'danger'
export type ButtonSize = 'sm' | 'md' | 'lg'

export const BUTTON_BASE_CLASS =
  'inline-flex items-center justify-center gap-2 rounded-full font-medium whitespace-nowrap transition-[color,background-color,border-color,filter,transform] duration-200 select-none active:scale-[0.98]'

export const BUTTON_VARIANT_CLASSES: Record<ButtonVariant, string> = {
  primary:
    'bg-[var(--accent)] text-[var(--accent-ink)] hover:brightness-110 active:brightness-95 shadow-[0_2px_10px_-4px_color-mix(in_oklab,var(--accent)_50%,transparent)]',
  quiet:
    'border border-[var(--line-strong)] text-[var(--ink)] hover:border-[var(--accent)] hover:text-[var(--accent)]',
  ghost: 'text-[var(--ink-muted)] hover:bg-[var(--surface)] hover:text-[var(--ink)]',
  danger:
    'border border-[color-mix(in_oklab,var(--color-clay-400)_45%,transparent)] text-[var(--color-clay-400)] hover:bg-[color-mix(in_oklab,var(--color-clay-500)_14%,transparent)]',
}

export const BUTTON_SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'min-h-9 px-3.5 text-[0.8125rem]',
  md: 'min-h-11 px-4 text-[0.9375rem]',
  lg: 'min-h-12 px-5 text-[1rem]',
}

export function buttonClasses(
  variant: ButtonVariant = 'quiet',
  size: ButtonSize = 'md',
  block = false,
): string[] {
  return [
    BUTTON_BASE_CLASS,
    BUTTON_VARIANT_CLASSES[variant],
    BUTTON_SIZE_CLASSES[size],
    block ? 'w-full' : '',
  ]
}
