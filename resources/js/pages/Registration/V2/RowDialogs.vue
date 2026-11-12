<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import { NOT_READY_REASONS } from '@/lib/registration-v2';
import type { VisitRow } from '@/types';

/**
 * Version 2 dialogs for a Registration row: "Mark not ready", "Ready to be called" and "Cancel visit", on `V2Dialog`.
 * Same endpoints, payload keys, lock versions and idempotency key as the Classic dialogs
 * (`components/queue/CallReadinessActions.vue`, `components/patient-board/VisitCancellationDialog.vue`), which stay
 * untouched. Only the filled Confirm of "Cancel visit" is destructive.
 */
const props = defineProps<{
    kind: 'notReady' | 'ready' | 'cancel' | null;
    visit: VisitRow | null;
    branchId: number;
}>();
const emit = defineEmits<{ close: []; changed: [] }>();

const reason = ref('');
const error = ref('');
const busy = ref(false);
let key = '';

const open = computed(() => props.kind !== null && props.visit !== null);

watch(
    () => [props.kind, props.visit?.visitNumber],
    () => {
        reason.value = '';
        error.value = '';
        key = '';
    },
);
watch(reason, () => {
    key = '';
});

const finish = (succeeded: boolean) => {
    busy.value = false;

    if (succeeded) {
        emit('close');
        emit('changed');
    }
};

const readiness = (clear: boolean) => {
    const visit = props.visit!;

    if (!clear && !reason.value) {
        error.value = 'Select a reason.';

        return;
    }

    key ||= crypto.randomUUID();
    busy.value = true;
    error.value = '';
    let succeeded = false;
    router.visit(
        `/visits/${encodeURIComponent(visit.visitNumber)}/queue/readiness`,
        {
            method: clear ? 'patch' : 'post',
            data: {
                expected_branch_id: props.branchId,
                queue_lock_version: visit.queueLockVersion,
                ...(clear
                    ? {
                          lock_version: visit.callHold?.lockVersion,
                          call_hold_id: visit.callHold?.id,
                      }
                    : { reason_code: reason.value }),
                idempotency_key: key,
                from: 'registration',
            },
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'The action failed.';
            },
            onSuccess: () => {
                succeeded = true;
            },
            onFinish: () => finish(succeeded),
        },
    );
};

const cancel = (cancellationReason: string | null) => {
    const visit = props.visit!;

    if (!visit.visitLockVersion || !cancellationReason) {
        return;
    }

    busy.value = true;
    error.value = '';
    let succeeded = false;
    router.patch(
        `/visits/${encodeURIComponent(visit.visitNumber)}/cancel`,
        {
            expected_branch_id: props.branchId,
            lock_version: visit.visitLockVersion,
            queue_lock_version: visit.queueLockVersion,
            cancellation_reason: cancellationReason.slice(0, 500),
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ?? 'Visit cancellation failed.';
            },
            onSuccess: () => {
                succeeded = true;
            },
            onFinish: () => finish(succeeded),
        },
    );
};

const confirm = (cancellationReason: string | null) => {
    if (busy.value) {
        return;
    }

    if (props.kind === 'cancel') {
        cancel(cancellationReason);
    } else {
        readiness(props.kind === 'ready');
    }
};
</script>

<template>
    <V2Dialog
        :open="open"
        :title="
            kind === 'cancel'
                ? 'Cancel visit'
                : kind === 'ready'
                  ? 'Ready to be called?'
                  : 'Not ready to call'
        "
        :description="
            kind === 'cancel'
                ? 'Record an operational reason. This action remains subject to current visit authorization.'
                : kind === 'ready'
                  ? 'Clear this flag when the issue is resolved. The doctor will be notified.'
                  : 'Select an administrative reason. The queue order and patient number stay unchanged.'
        "
        :confirm-label="kind === 'cancel' ? 'Cancel visit' : 'Confirm'"
        :cancel-label="kind === 'cancel' ? 'Keep visit' : 'Cancel'"
        :tone="kind === 'cancel' ? 'danger' : 'default'"
        :reason-label="kind === 'cancel' ? 'Cancellation reason' : undefined"
        :busy="busy"
        @update:open="(value) => !value && emit('close')"
        @confirm="confirm"
    >
        <p v-if="kind === 'ready'" class="text-sm break-words">
            {{ visit?.callHold?.reason }}
        </p>
        <label v-else-if="kind === 'notReady'" class="grid gap-1.5 text-sm">
            <span class="font-medium">Reason</span>
            <select
                v-model="reason"
                class="h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
            >
                <option value="">Select a reason</option>
                <option
                    v-for="item in NOT_READY_REASONS"
                    :key="item[0]"
                    :value="item[0]"
                >
                    {{ item[1] }}
                </option>
            </select>
        </label>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
    </V2Dialog>
</template>
