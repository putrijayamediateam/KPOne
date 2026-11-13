<script setup lang="ts">
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { nextTick, ref, watch } from 'vue';

/**
 * Version 2 dialog: 18px corners, a bottom sheet below 640px, a pinned footer, and the focus trap and Esc that the
 * Classic dialog has (from reka-ui). `reasonLabel` adds a required reason field: Confirm shows aria-disabled until
 * something is typed (it can still be pressed: that shows the error and moves focus to the box; it stays at full opacity, the error carries the message), and `confirm` emits the reason. At most one filled destructive button: `tone="danger"` makes the
 * Confirm button the destructive one, and Cancel is outline. The page decides what Confirm does.
 *
 * `size="tall"` is for long forms (a purchase order, receiving): full height on a phone, 42rem from 640px and 56rem from
 * 1024px, up to 90dvh tall, with the body scrolling and the footer staying put.
 *
 * `dirty` is a discard guard: while it is true, every way of closing (Esc, the X, a tap on the backdrop, Cancel) first
 * asks "Discard what you typed?" inside the same dialog, with "Keep editing" focused, and only closes on "Discard"
 * (which emits `discard`, then `update:open(false)`). Esc while the question shows means Keep editing. The page sets
 * `dirty` from its own form state, and closing the dialog from the page (after a save) is never guarded. Everything
 * behind the question is inert, so Tab cannot reach the form. Wording and buttons are the same on every V2 form.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        confirmLabel: string;
        cancelLabel?: string;
        tone?: 'default' | 'danger';
        reasonLabel?: string;
        busy?: boolean;
        size?: 'default' | 'tall';
        dirty?: boolean;
    }>(),
    {
        description: undefined,
        cancelLabel: 'Cancel',
        tone: 'default',
        reasonLabel: undefined,
        busy: false,
        size: 'default',
        dirty: false,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [reason: string | null];
    discard: [];
}>();

const reason = ref('');
const tried = ref(false);
const box = ref<HTMLTextAreaElement | null>(null);

const asking = ref(false);
const keepButton = ref<HTMLButtonElement | null>(null);
const discardButton = ref<HTMLButtonElement | null>(null);
let returnFocusTo: HTMLElement | null = null;

watch(
    () => props.open,
    (open) => {
        asking.value = false;

        if (open) {
            reason.value = '';
            tried.value = false;
        }
    },
);

// Every way of closing comes through here. A dirty dialog asks first; the question itself closes (as Keep editing) on Esc.
const onOpenChange = (value: boolean) => {
    if (value) {
        emit('update:open', true);

        return;
    }

    if (asking.value) {
        keepEditing();

        return;
    }

    if (props.dirty) {
        returnFocusTo = document.activeElement as HTMLElement | null;
        asking.value = true;
        nextTick(() => keepButton.value?.focus());

        return;
    }

    emit('update:open', false);
};

// The page behind the question is inert, so the dialog's own focus trap would run out of places and drop focus on the
// page: keep Tab and Shift+Tab inside the two buttons here.
const trapTab = (event: KeyboardEvent) => {
    if (event.key !== 'Tab') {
        return;
    }

    const first = discardButton.value;
    const last = keepButton.value;

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
};

const keepEditing = () => {
    asking.value = false;
    nextTick(() => returnFocusTo?.focus());
};

const discard = () => {
    asking.value = false;
    emit('discard');
    emit('update:open', false);
};

const submit = () => {
    if (props.reasonLabel && reason.value.trim() === '') {
        tried.value = true;
        nextTick(() => box.value?.focus());

        return;
    }

    emit('confirm', props.reasonLabel ? reason.value.trim() : null);
};
</script>

<template>
    <DialogRoot :open="open" @update:open="onOpenChange">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 bg-black/50" />
            <DialogContent
                data-slot="v2-dialog"
                class="v2-dialog fixed z-50 flex w-full flex-col overflow-hidden border border-border bg-card text-card-foreground outline-none sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-[18px] dark:border-foreground/20"
                :class="
                    size === 'tall'
                        ? 'max-sm:inset-0 max-sm:h-dvh max-sm:rounded-none sm:max-h-[90dvh] sm:max-w-2xl lg:max-w-[56rem]'
                        : 'max-h-[90dvh] max-sm:inset-x-0 max-sm:bottom-0 max-sm:rounded-t-[18px] sm:max-w-lg'
                "
            >
                <div
                    class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-5"
                    :inert="asking"
                >
                    <DialogTitle
                        class="pr-10 text-lg leading-7 font-semibold"
                        >{{ title }}</DialogTitle
                    >
                    <DialogDescription
                        v-if="description"
                        class="text-sm leading-5 text-muted-foreground"
                        >{{ description }}</DialogDescription
                    >
                    <slot />
                    <div v-if="reasonLabel" class="flex flex-col gap-1.5">
                        <label
                            for="v2-dialog-reason"
                            class="text-sm font-medium"
                            >{{ reasonLabel }}
                            <span class="text-destructive" aria-hidden="true"
                                >*</span
                            ></label
                        >
                        <textarea
                            id="v2-dialog-reason"
                            ref="box"
                            v-model="reason"
                            rows="3"
                            :aria-invalid="tried && reason.trim() === ''"
                            :aria-describedby="
                                tried && reason.trim() === ''
                                    ? 'v2-dialog-reason-error'
                                    : undefined
                            "
                            class="w-full rounded-[10px] border bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                            :class="
                                tried && reason.trim() === ''
                                    ? 'border-destructive'
                                    : 'border-input'
                            "
                        />
                        <p
                            v-if="tried && reason.trim() === ''"
                            id="v2-dialog-reason-error"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            Enter a reason to continue.
                        </p>
                    </div>
                </div>
                <div
                    class="flex flex-col-reverse gap-2 border-t bg-card px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))] sm:flex-row sm:justify-end"
                    :inert="asking"
                >
                    <DialogClose
                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9"
                    >
                        {{ cancelLabel }}
                    </DialogClose>
                    <button
                        type="button"
                        :disabled="busy"
                        :aria-disabled="
                            reasonLabel && reason.trim() === ''
                                ? 'true'
                                : undefined
                        "
                        class="inline-flex h-11 items-center justify-center rounded-[10px] px-4 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 lg:h-9"
                        :class="
                            tone === 'danger'
                                ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90'
                                : 'bg-primary text-primary-foreground hover:bg-primary/90'
                        "
                        @click="submit"
                    >
                        {{ confirmLabel }}
                    </button>
                </div>
                <div
                    v-if="asking"
                    data-slot="v2-dialog-discard"
                    role="alertdialog"
                    aria-labelledby="v2-dialog-discard-title"
                    aria-describedby="v2-dialog-discard-text"
                    class="absolute inset-0 z-10 flex flex-col justify-end gap-3 bg-card p-5 sm:justify-center"
                    @keydown="trapTab"
                >
                    <h3
                        id="v2-dialog-discard-title"
                        class="text-lg leading-7 font-semibold"
                    >
                        Discard what you typed?
                    </h3>
                    <p
                        id="v2-dialog-discard-text"
                        class="text-sm leading-5 text-muted-foreground"
                    >
                        If you close now, what you entered here is lost.
                    </p>
                    <div
                        class="flex flex-col-reverse gap-2 pt-2 pb-[max(0rem,env(safe-area-inset-bottom))] sm:flex-row sm:justify-end"
                    >
                        <button
                            ref="discardButton"
                            type="button"
                            class="inline-flex h-11 items-center justify-center rounded-[10px] bg-destructive px-4 text-sm font-medium text-destructive-foreground outline-none hover:bg-destructive/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9"
                            @click="discard"
                        >
                            Discard
                        </button>
                        <button
                            ref="keepButton"
                            type="button"
                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9"
                            @click="keepEditing"
                        >
                            Keep editing
                        </button>
                    </div>
                </div>
                <DialogClose
                    :inert="asking"
                    class="absolute top-2.5 right-2.5 inline-flex size-11 items-center justify-center rounded-full outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label="Close"
                >
                    <svg
                        class="size-4"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.25"
                        stroke-linecap="round"
                        aria-hidden="true"
                    >
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </DialogClose>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
