<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhoneCall } from '@lucide/vue';
import { ref, watch } from 'vue';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import type { PatientBoardRow, QueueRow } from '@/types';

const props = defineProps<{
    open: boolean;
    row: PatientBoardRow | null;
    branchId: number;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    processing: [rowKey: string | null];
    finished: [];
}>();

const processing = ref(false);
const error = ref('');

watch(
    () => props.open,
    (open) => {
        if (open) {
            error.value = '';
        }
    },
);

const updateOpen = (open: boolean) => {
    if (!processing.value) {
        emit('update:open', open);
    }
};

const submit = () => {
    if (!props.row || processing.value) {
        return;
    }

    const row = props.row;
    const entry = row.source as QueueRow;
    processing.value = true;
    error.value = '';
    emit('processing', row.key);

    router.patch(
        `/visits/${encodeURIComponent(row.visitNumber)}/queue/call`,
        {
            expected_branch_id: props.branchId,
            visit_lock_version: entry.visitLockVersion,
            queue_lock_version: entry.queueLockVersion,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'Call In failed.';
            },
            onSuccess: () => {
                emit('update:open', false);
            },
            onFinish: () => {
                processing.value = false;
                emit('processing', null);
                emit('finished');
            },
        },
    );
};
</script>

<template>
    <OperationalConfirmDialog
        :open="open"
        title="Call this patient?"
        description="Confirm that you want to call this patient for consultation."
        confirm-label="Call Patient"
        processing-label="Calling…"
        :processing="processing"
        :error="error"
        @update:open="updateOpen"
        @confirm="submit"
    >
        <template #icon><PhoneCall class="size-5" /></template>
        <div
            v-if="row"
            class="rounded-xl border border-border/70 bg-muted/35 px-4 py-3"
        >
            <p class="font-medium text-foreground">{{ row.patientName }}</p>
            <p class="mt-1 text-xs text-muted-foreground">
                Queue {{ row.queueNumber }} · {{ row.patientNumber }}
            </p>
        </div>
    </OperationalConfirmDialog>
</template>
