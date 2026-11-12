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

// UI-RESTYLE 0.7: below 640 px a dialog is a bottom sheet (full width, rounded top, a grab bar, scrolls when tall).
// OperationalConfirmDialog sets sheetOnPhone to false so a confirm question stays centred.
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
      v-bind="{ ...$attrs, ...forwarded }"
      :class="
        cn(
          'bg-card data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed z-50 grid w-full gap-4 border p-6 shadow-[var(--shadow-dialog)] duration-200 sm:top-[50%] sm:left-[50%] sm:max-w-lg sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-[18px] [&_[data-slot=dialog-title]]:pr-9',
          sheetOnPhone
            ? 'max-sm:inset-x-0 max-sm:bottom-0 max-sm:max-h-[90dvh] max-sm:overflow-y-auto max-sm:rounded-t-[18px] max-sm:pb-[max(1.5rem,env(safe-area-inset-bottom))] max-sm:data-[state=open]:slide-in-from-bottom-8 max-sm:data-[state=closed]:slide-out-to-bottom-8'
            : 'max-sm:top-[50%] max-sm:left-[50%] max-sm:max-w-[calc(100%-2rem)] max-sm:translate-x-[-50%] max-sm:translate-y-[-50%] max-sm:rounded-[18px]',
          props.class,
        )"
    >
      <div
        v-if="sheetOnPhone"
        class="bg-border mx-auto -mb-2 h-1 w-10 rounded-full sm:hidden"
        aria-hidden="true"
        data-slot="dialog-grab-bar"
      />
      <slot />

      <DialogClose
        v-if="showCloseButton"
        data-slot="dialog-close"
        class="text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-ring absolute top-2 right-2 grid size-11 cursor-pointer place-items-center rounded-[10px] transition-colors focus-visible:ring-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4"
      >
        <X />
        <span class="sr-only">Close</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
