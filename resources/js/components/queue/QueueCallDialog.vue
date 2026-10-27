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
const overrideReason = ref('');
let retryKey = '';
watch(overrideReason, () => {
    retryKey = '';
});
watch(
    () => props.branchId,
    () => {
        updateOpen(false);
        overrideReason.value = '';
        retryKey = '';
    },
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            error.value = '';
            overrideReason.value = '';
            retryKey = '';
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

    if (props.row.callHold?.active && !overrideReason.value) {
        error.value = 'Select a reason to override the flag.';

        return;
    }

    retryKey ||= crypto.randomUUID();
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
            ...(row.callHold?.active
                ? {
                      override_reason: overrideReason.value,
                      call_hold_id: row.callHold.id,
                      call_hold_lock_version: row.callHold.lockVersion,
                      idempotency_key: retryKey,
                  }
                : {}),
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
        <label
            data-call-readiness-override
            v-if="row?.callHold?.active"
            class="mt-4 grid gap-2 text-sm"
        >
            Not ready to call: {{ row.callHold.reason }}. Confirm a reason to
            call anyway.
            <select
                v-model="overrideReason"
                class="min-h-11 w-full rounded-md border bg-background px-3"
            >
                <option value="">Select a reason</option>
                <option value="patient_is_urgent">Patient is urgent</option>
                <option value="issue_resolved_verbally">
                    Issue resolved verbally
                </option>
                <option value="patient_at_door">Patient is at the door</option>
            </select>
        </label>
    </OperationalConfirmDialog>
</template>

<style scoped>
:global([role='dialog']:has([data-call-readiness-override]) button) {
    min-height: 44px;
    min-width: 44px;
}
</style>
