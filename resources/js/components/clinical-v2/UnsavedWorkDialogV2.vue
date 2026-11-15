<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import V2Dialog from '@/components/v2/V2Dialog.vue';

/**
 * Version 2 "Unsaved clinical work" dialog (Classic: `components/clinical/UnsavedClinicalWorkDialog.vue`, untouched).
 * Same props and events: this is purely presentational, the page owns the save and hold calls. "Save and hold" is the
 * one filled button (the recommended choice), "Discard and hold" is an outline button inside the body, and Cancel
 * keeps the doctor on the consultation.
 */
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
const description = computed(
    () => `${joinWithAnd(props.unsavedItems)} not saved yet.`,
);
</script>

<template>
    <V2Dialog
        :open="open"
        title="Unsaved clinical work"
        :description="description"
        :confirm-label="
            processing && processingAction === 'confirm'
                ? 'Saving…'
                : 'Save and hold'
        "
        cancel-label="Cancel"
        :busy="processing"
        @update:open="(value) => !processing && emit('update:open', value)"
        @confirm="emit('save-and-hold')"
    >
        <Button
            type="button"
            variant="outline"
            class="h-11 w-full sm:w-fit lg:h-9"
            :disabled="processing"
            @click="emit('hold-without-saving')"
            >{{
                processing && processingAction === 'secondary'
                    ? 'Holding…'
                    : 'Discard and hold'
            }}</Button
        >
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
    </V2Dialog>
</template>
