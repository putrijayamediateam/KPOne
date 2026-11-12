<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { X } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import {
  DialogClose,
  DialogContent,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"
import DialogOverlay from "./DialogOverlay.vue"

defineOptions({
  inheritAttrs: false,
})

// UI-RESTYLE 0.7: below 640 px a dialog is a bottom sheet (full width, rounded top, scrolls when tall, with safe-area
// padding). Keep the default for a dialog that holds fields or a long list. Set sheetOnPhone to false for a short
// confirm question, which stays centred (OperationalConfirmDialog does). There is no grab bar: it would suggest a drag
// that does not exist; add one only to a sheet that supports drag-to-dismiss.
const props = withDefaults(defineProps<DialogContentProps & { class?: HTMLAttributes["class"], showCloseButton?: boolean, sheetOnPhone?: boolean }>(), {
  showCloseButton: true,
  sheetOnPhone: true,
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class", "sheetOnPhone")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <DialogPortal>
    <DialogOverlay />
    <DialogContent
      data-slot="dialog-content"
      :data-sheet="sheetOnPhone ? 'true' : undefined"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="
        cn(
          'bg-card data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 group/dialog fixed z-50 grid w-full gap-4 border p-6 shadow-[var(--shadow-dialog)] dark:border-foreground/20 duration-200 sm:top-[50%] sm:left-[50%] sm:max-w-lg sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-[18px] [&_[data-slot=dialog-title]]:pr-9',
          sheetOnPhone
            ? 'max-sm:inset-x-0 max-sm:bottom-0 max-sm:max-h-[90dvh] max-sm:overflow-y-auto max-sm:rounded-t-[18px] max-sm:data-[state=open]:slide-in-from-bottom-8 max-sm:data-[state=closed]:slide-out-to-bottom-8'
            : 'max-sm:top-[50%] max-sm:left-[50%] max-sm:max-w-[calc(100%-2rem)] max-sm:translate-x-[-50%] max-sm:translate-y-[-50%] max-sm:rounded-[18px]',
          props.class,
        )"
    >
      <slot />

      <DialogClose
        v-if="showCloseButton"
        data-slot="dialog-close"
        class="text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-ring absolute top-2.5 right-2.5 grid size-11 cursor-pointer place-items-center rounded-[10px] transition-colors focus-visible:ring-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4"
      >
        <X />
        <span class="sr-only">Close</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
