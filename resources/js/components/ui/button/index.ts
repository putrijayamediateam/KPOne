import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-[10px] text-sm font-medium transition-[background-color,border-color,color,box-shadow,opacity] disabled:cursor-not-allowed disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/40 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "border border-primary bg-primary text-primary-foreground hover:bg-primary/85 disabled:hover:bg-primary",
        primary:
          "border border-primary bg-primary text-primary-foreground hover:bg-primary/85 disabled:hover:bg-primary",
        destructive:
          "border border-destructive bg-destructive text-destructive-foreground hover:bg-destructive/90 disabled:hover:bg-destructive focus-visible:ring-destructive/30",
        outline:
          "border border-input bg-card text-foreground hover:border-foreground/25 hover:bg-muted disabled:hover:border-input disabled:hover:bg-card",
        secondary:
          "border border-input bg-secondary text-secondary-foreground hover:border-foreground/25 hover:bg-muted disabled:hover:border-input disabled:hover:bg-secondary",
        ghost:
          "hover:bg-accent disabled:hover:bg-transparent dark:hover:bg-accent/50 dark:disabled:hover:bg-transparent",
        link: "text-primary underline-offset-4 hover:underline disabled:hover:no-underline",
      },
      size: {
        "default": "h-11 px-4 py-2 has-[>svg]:px-3 lg:h-9",
        "sm": "h-11 gap-1.5 px-3 has-[>svg]:px-2.5 lg:h-8",
        "lg": "h-11 px-6 has-[>svg]:px-4 lg:h-10",
        "icon": "size-11 lg:size-9",
        "icon-sm": "size-11 lg:size-8",
        "icon-lg": "size-11 lg:size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
