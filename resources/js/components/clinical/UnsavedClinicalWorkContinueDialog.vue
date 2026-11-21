<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';

// Q-14 (owner decision 21 Nov 2026): Complete Consultation must never go ahead with unsaved clinical work. Like the Hold
// dialog (UnsavedClinicalWorkDialog, left as it is), this is purely presentational: Show.vue owns the saves and passes back
// processing and error state. "Continue" because the existing "Complete consultation?" confirm dialog still follows.
const props = defineProps<{
    open: boolean;
    unsavedItems: string[];
    processing: boolean;
    processingAction: 'confirm' | 'secondary';
    error: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'save-and-continue': [];
    'continue-without-saving': [];
}>();

const joinWithAnd = (items: string[]): string =>
    items.length <= 1
        ? (items[0] ?? 'work')
        : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;

const description = computed(
    () => `Not saved yet: ${joinWithAnd(props.unsavedItems)}.`,
);
</script>

<template>
    <OperationalConfirmDialog
        :open="open"
        title="Unsaved clinical work"
        :description="description"
        confirm-label="Save and continue"
        processing-label="Saving…"
        secondary-label="Discard and continue"
        secondary-processing-label="Discarding…"
        secondary-variant="outline"
        :processing="processing"
        :processing-action="processingAction"
        :error="error"
        @update:open="emit('update:open', $event)"
        @confirm="emit('save-and-continue')"
        @secondary="emit('continue-without-saving')"
    >
        <template #icon>
            <AlertTriangle class="size-5" />
        </template>
    </OperationalConfirmDialog>
</template>
