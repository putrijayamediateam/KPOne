<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';

// OH-06c: the doctor may have unsaved edits (clinical note, vitals,
// diagnoses, and/or a treatment plan draft in the sibling panel) when
// pressing On Hold. Clinical save is disabled while a consultation is held,
// so silently holding would strand that work with no way back to it. This
// dialog is purely presentational - Show.vue owns the actual save/hold
// network calls and passes back processing/error state - so it stays a thin
// wrapper over the same OperationalConfirmDialog shell every other clinical
// confirmation in this workspace (see ClinicalSafetyConfirmDialog.vue) uses.
const props = defineProps<{
    open: boolean;
    unsavedItems: string[];
    processing: boolean;
    processingAction: 'confirm' | 'secondary';
    error: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'save-and-hold': [];
    'hold-without-saving': [];
}>();

const joinWithAnd = (items: string[]): string => {
    if (items.length === 0) {
        return 'Work';
    }

    if (items.length === 1) {
        return items[0].charAt(0).toUpperCase() + items[0].slice(1);
    }

    const list = `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;

    return list.charAt(0).toUpperCase() + list.slice(1);
};

// OH-06d: one short line naming what is unsaved - the three buttons are the
// choices, so the description does not need to restate them.
const description = computed(
    () => `${joinWithAnd(props.unsavedItems)} not saved yet.`,
);
</script>

<template>
    <OperationalConfirmDialog
        :open="open"
        title="Unsaved clinical work"
        :description="description"
        confirm-label="Save and hold"
        processing-label="Saving…"
        secondary-label="Discard and hold"
        secondary-processing-label="Holding…"
        secondary-variant="ghost"
        :processing="processing"
        :processing-action="processingAction"
        :error="error"
        @update:open="emit('update:open', $event)"
        @confirm="emit('save-and-hold')"
        @secondary="emit('hold-without-saving')"
    >
        <template #icon>
            <AlertTriangle class="size-5" />
        </template>
    </OperationalConfirmDialog>
</template>
