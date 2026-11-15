import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-[background-color,border-color,color,box-shadow,opacity] disabled:cursor-not-allowed disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-2 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "border border-primary bg-primary text-primary-foreground shadow-xs hover:bg-primary/85 disabled:hover:bg-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background",
        primary:
          "border border-primary bg-primary text-primary-foreground shadow-xs hover:bg-primary/85 disabled:hover:bg-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background",
        destructive:
          "bg-destructive text-white hover:bg-destructive/90 disabled:hover:bg-destructive focus-visible:ring-destructive dark:bg-destructive/60 dark:disabled:hover:bg-destructive/60 focus-visible:ring-offset-2 focus-visible:ring-offset-background",
        outline:
          "border border-input bg-card text-foreground shadow-xs hover:border-foreground/25 hover:bg-muted disabled:hover:border-input disabled:hover:bg-card dark:bg-field-fill/30 dark:hover:bg-field-fill/50 dark:disabled:hover:bg-field-fill/30",
        secondary:
          "border border-input bg-secondary text-secondary-foreground shadow-xs hover:border-foreground/25 hover:bg-muted disabled:hover:border-input disabled:hover:bg-secondary",
        ghost:
          "hover:bg-accent disabled:hover:bg-transparent dark:hover:bg-accent/50 dark:disabled:hover:bg-transparent",
        accent:
          "border border-brand bg-brand text-brand-foreground shadow-xs hover:bg-brand/90 disabled:hover:bg-brand focus-visible:border-brand focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-background",
        link: "text-primary underline-offset-4 hover:underline disabled:hover:no-underline",
      },
      size: {
        "default": "h-9 px-4 py-2 has-[>svg]:px-3",
        "sm": "h-8 rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
        "lg": "h-10 rounded-md px-6 has-[>svg]:px-4",
        "icon": "size-9",
        "icon-sm": "size-8",
        "icon-lg": "size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
