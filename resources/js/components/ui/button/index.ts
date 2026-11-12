import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-[10px] text-sm font-medium transition-[background-color,border-color,color,box-shadow,opacity] disabled:cursor-not-allowed disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "border border-primary bg-primary text-primary-foreground hover:bg-primary-hover active:bg-primary-pressed disabled:hover:bg-primary",
        primary:
          "border border-primary bg-primary text-primary-foreground hover:bg-primary-hover active:bg-primary-pressed disabled:hover:bg-primary",
        destructive:
          "border border-destructive bg-destructive text-destructive-foreground hover:bg-destructive-hover active:bg-destructive-pressed disabled:hover:bg-destructive",
        outline:
          "border border-input bg-card text-foreground hover:border-foreground/25 hover:bg-muted active:bg-muted/70 disabled:hover:border-input disabled:hover:bg-card",
        secondary:
          "border border-input bg-secondary text-secondary-foreground hover:border-foreground/25 hover:bg-muted active:bg-muted/70 disabled:hover:border-input disabled:hover:bg-secondary",
        ghost:
          "hover:bg-accent active:bg-accent/70 disabled:hover:bg-transparent dark:hover:bg-accent/50 dark:disabled:hover:bg-transparent",
        link: "text-primary underline-offset-4 hover:underline disabled:hover:no-underline",
      },
      size: {
        "default": "h-11 px-4 py-2 has-[>svg]:px-3 lg:h-9",
        "sm": "h-11 gap-1.5 px-3 has-[>svg]:px-2.5 lg:h-9",
        // The dense size for buttons inside table rows only: 32 px from 1024 px with a pointer, 44 px otherwise.
        // Never on forms, dialogs or cards.
        "row": "h-11 gap-1.5 px-3 has-[>svg]:px-2.5 lg:pointer-fine:h-8",
        "lg": "h-11 px-6 has-[>svg]:px-4 lg:h-10",
        "icon": "size-11 lg:size-9",
        "icon-sm": "size-11 lg:size-9",
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
