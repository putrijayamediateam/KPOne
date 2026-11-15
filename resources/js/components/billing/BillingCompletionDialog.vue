<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleCheckBig } from '@lucide/vue';
import { ref, watch } from 'vue';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';

const props = defineProps<{
    open: boolean;
    visitNumber: string;
    branchId: number;
    visitLockVersion: number;
    invoiceLockVersion: number;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const processing = ref(false);
const error = ref('');

watch(
    () => [props.open, props.visitNumber],
    () => {
        error.value = '';
    },
);

const updateOpen = (open: boolean) => {
    if (!processing.value) {
        emit('update:open', open);
    }
};

const submit = () => {
    if (processing.value) {
        return;
    }

    processing.value = true;
    error.value = '';
    router.post(
        `/visits/${encodeURIComponent(props.visitNumber)}/billing/complete`,
        {
            expected_branch_id: props.branchId,
            lock_version: props.invoiceLockVersion,
            visit_lock_version: props.visitLockVersion,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The Visit could not be completed.';
            },
            onSuccess: () => {
                emit('update:open', false);
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <OperationalConfirmDialog
        :open="open"
        :title="`Complete Visitation ${visitNumber}?`"
        description="This preserves the finalized financial evidence and closes ordinary Visit editing."
        confirm-label="Complete Visitation"
        processing-label="Completing…"
        :processing="processing"
        :error="error"
        @update:open="updateOpen"
        @confirm="submit"
    >
        <template #icon><CircleCheckBig class="size-5" /></template>
    </OperationalConfirmDialog>
</template>
