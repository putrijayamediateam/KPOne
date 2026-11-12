<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { DialogClose } from "reka-ui"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'

const props = withDefaults(defineProps<{
  class?: HTMLAttributes["class"]
  showCloseButton?: boolean
}>(), {
  showCloseButton: false,
})
</script>

<template>
  <div
    data-slot="dialog-footer"
    :class="cn(
      'flex flex-col-reverse gap-2 sm:flex-row sm:justify-end',
      // In a phone bottom sheet the footer stays pinned at the bottom of the scrolling sheet: Save full width on its own
      // row, Cancel under it, with safe-area padding so the home indicator and the keyboard never cover them.
      'max-sm:group-data-[sheet=true]/dialog:sticky max-sm:group-data-[sheet=true]/dialog:-bottom-6 max-sm:group-data-[sheet=true]/dialog:-mx-6 max-sm:group-data-[sheet=true]/dialog:-mb-6 max-sm:group-data-[sheet=true]/dialog:border-t max-sm:group-data-[sheet=true]/dialog:bg-card max-sm:group-data-[sheet=true]/dialog:px-6 max-sm:group-data-[sheet=true]/dialog:pt-3 max-sm:group-data-[sheet=true]/dialog:pb-[max(1.5rem,env(safe-area-inset-bottom))]',
      props.class,
    )"
  >
    <slot />
    <DialogClose v-if="showCloseButton" as-child>
      <Button variant="outline">
        Close
      </Button>
    </DialogClose>
  </div>
</template>
