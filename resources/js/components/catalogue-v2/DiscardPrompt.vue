<script lang="ts">
let lastLabel: string | null = null;
</script>

<script setup lang="ts">
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Page-level "Discard your changes?" question (Version 2 catalogue pages). Same look and order as the shared guard in
 * V2Dialog: on a phone "Keep editing" is on top and takes the focus, "Discard changes" (filled, destructive) is below.
 * Closing it any other way (Esc, the backdrop) means Keep editing. Swap for the shared block when the page can use it.
 */
defineProps<{ open: boolean; description: string }>();

const emit = defineEmits<{ keep: []; discard: [] }>();
const keepButton = ref<HTMLButtonElement | null>(null);

// The field that had focus is remembered by its label, because a browser Back swaps the page (and its fields) before
// this question opens. After "Keep editing" (or Esc) focus goes back to that field, not to the page body.
const clean = (text: string | null | undefined) =>
    (text ?? '').replace(/\s*\*\s*$/, '').trim();
const remember = (event: FocusEvent) => {
    const target = event.target;

    if (
        target instanceof HTMLInputElement ||
        target instanceof HTMLTextAreaElement
    ) {
        const text = clean(target.labels?.[0]?.textContent);

        if (text) {
            lastLabel = text;
        }
    }
};
onMounted(() => document.addEventListener('focusin', remember));
onBeforeUnmount(() => document.removeEventListener('focusin', remember));
const keep = () => {
    emit('keep');
    setTimeout(() => {
        for (const label of document.querySelectorAll('label')) {
            if (clean(label.textContent) === lastLabel) {
                (label as HTMLLabelElement).control?.focus();

                return;
            }
        }
    }, 150);
};
const focusKeep = (event: Event) => {
    event.preventDefault();
    nextTick(() => keepButton.value?.focus());
};
</script>

<template>
    <DialogRoot :open="open" @update:open="(o: boolean) => !o && keep()">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 bg-black/50" />
            <DialogContent
                data-slot="discard-prompt"
                class="fixed z-50 flex w-full flex-col overflow-hidden border border-border bg-card text-card-foreground outline-none max-sm:inset-x-0 max-sm:bottom-0 max-sm:rounded-t-[18px] sm:top-1/2 sm:left-1/2 sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-[18px] dark:border-foreground/20"
                @open-auto-focus="focusKeep"
            >
                <div class="flex flex-col gap-3 p-5">
                    <DialogTitle class="text-lg leading-7 font-semibold"
                        >Discard your changes?</DialogTitle
                    >
                    <DialogDescription
                        class="text-sm leading-5 text-muted-foreground"
                        >{{ description }}</DialogDescription
                    >
                </div>
                <div
                    class="flex flex-col-reverse gap-2 border-t bg-card px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))] sm:flex-row sm:justify-end"
                >
                    <button
                        type="button"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] bg-destructive px-4 text-sm font-medium text-destructive-foreground outline-none hover:bg-destructive/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9"
                        @click="emit('discard')"
                    >
                        Discard changes
                    </button>
                    <button
                        ref="keepButton"
                        type="button"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9"
                        @click="keep()"
                    >
                        Keep editing
                    </button>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
