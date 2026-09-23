<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description: string;
        confirmLabel: string;
        processingLabel?: string;
        cancelLabel?: string;
        processing?: boolean;
        destructive?: boolean;
        error?: string;
        /** Optional third choice, rendered between Cancel and Confirm. Omit for the classic two-choice dialog. */
        secondaryLabel?: string;
        secondaryProcessingLabel?: string;
        secondaryVariant?: 'outline' | 'destructive';
        /** Which button is currently in flight, so only that one shows its spinner/processing label. */
        processingAction?: 'confirm' | 'secondary';
    }>(),
    {
        cancelLabel: 'Cancel',
        processingLabel: 'Working…',
        processing: false,
        destructive: false,
        error: '',
        secondaryLabel: undefined,
        secondaryProcessingLabel: 'Working…',
        secondaryVariant: 'outline',
        processingAction: 'confirm',
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
    secondary: [];
}>();

const updateOpen = (open: boolean) => {
    if (!props.processing) {
        emit('update:open', open);
    }
};

const confirm = () => {
    if (!props.processing) {
        emit('confirm');
    }
};

const secondaryAction = () => {
    if (!props.processing) {
        emit('secondary');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="updateOpen">
        <DialogContent
            class="gap-5 border-border/80 p-5 shadow-2xl shadow-black/15 sm:max-w-md sm:p-6 dark:bg-slate-900"
            :show-close-button="!processing"
        >
            <DialogHeader class="gap-3 text-left">
                <div class="flex items-start gap-3.5">
                    <div
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-pink-50 text-pink-700 dark:bg-pink-950/50 dark:text-pink-300"
                        aria-hidden="true"
                    >
                        <slot name="icon" />
                    </div>
                    <div class="min-w-0 space-y-1.5">
                        <DialogTitle class="text-lg leading-6">{{ title }}</DialogTitle>
                        <DialogDescription class="leading-5">{{ description }}</DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <slot />

            <p
                v-if="error"
                role="alert"
                class="rounded-lg border border-destructive/20 bg-destructive/5 px-3 py-2 text-sm text-destructive"
            >
                {{ error }}
            </p>

            <DialogFooter class="gap-2 sm:gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="updateOpen(false)"
                >
                    {{ cancelLabel }}
                </Button>
                <Button
                    v-if="secondaryLabel"
                    type="button"
                    :variant="secondaryVariant"
                    :disabled="processing"
                    @click="secondaryAction"
                >
                    <LoaderCircle
                        v-if="processing && processingAction === 'secondary'"
                        class="size-4 animate-spin"
                    />
                    {{
                        processing && processingAction === 'secondary'
                            ? secondaryProcessingLabel
                            : secondaryLabel
                    }}
                </Button>
                <Button
                    type="button"
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="processing"
                    @click="confirm"
                >
                    <LoaderCircle
                        v-if="processing && processingAction === 'confirm'"
                        class="size-4 animate-spin"
                    />
                    {{
                        processing && processingAction === 'confirm'
                            ? processingLabel
                            : confirmLabel
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
