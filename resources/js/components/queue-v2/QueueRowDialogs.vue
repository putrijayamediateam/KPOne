<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { OperationalSelect } from '@/components/ui/select';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import type { PatientBoardRow, QueueRow } from '@/types';

/**
 * Version 2 dialogs for a Queue board row: "Call this patient" (with the override reason for a not-ready flag), "Mark not ready", "Ready to be called", "Cancel visit" and "Call to a
 * treatment room", on `V2Dialog`. Same endpoints, payload keys, lock versions and idempotency key as the Classic ones
 * (`components/queue/QueueCallDialog.vue`, `components/queue/CallReadinessActions.vue`, `components/patient-board/VisitCancellationDialog.vue` and the
 * treatment-room box in `pages/Queue/Index.vue`), which stay untouched. Only the filled Confirm of "Cancel visit" is
 * destructive.
 */
const props = defineProps<{
    kind: 'call' | 'notReady' | 'ready' | 'cancel' | 'treatmentCall' | null;
    row: PatientBoardRow | null;
    branchId: number;
    treatmentRooms?: Array<{ id: number; name: string }>;
}>();
const emit = defineEmits<{ close: []; changed: []; busy: [busy: boolean] }>();

const NOT_READY_REASONS: Array<[string, string]> = [
    ['awaiting_panel_confirmation', 'Awaiting Panel confirmation'],
    ['awaiting_patient_details', 'Awaiting patient details'],
    ['awaiting_payment_or_deposit', 'Awaiting payment or deposit'],
    ['other_administrative', 'Other administrative'],
];
const reason = ref('');
const overrideReason = ref('');
const roomId = ref<string | number>('');
const error = ref('');
const busy = ref(false);
let key = '';

const open = computed(() => props.kind !== null && props.row !== null);

watch(
    () => [props.kind, props.row?.key],
    () => {
        reason.value = '';
        overrideReason.value = '';
        error.value = '';
        key = '';
        roomId.value =
            props.kind === 'treatmentCall' && props.treatmentRooms?.length === 1
                ? props.treatmentRooms[0].id
                : '';
    },
);
watch(
    () => props.branchId,
    () => {
        key = '';
        emit('close');
    },
);
watch([reason, overrideReason], () => {
    key = '';
});

const finish = (succeeded: boolean) => {
    busy.value = false;
    emit('busy', false);

    if (succeeded) {
        emit('close');
        emit('changed');
    }
};

const readiness = (clear: boolean) => {
    const row = props.row!;
    const entry = row.source as QueueRow;

    if (!clear && !reason.value) {
        error.value = 'Select a reason.';

        return;
    }

    key ||= crypto.randomUUID();
    busy.value = true;
    emit('busy', true);
    error.value = '';
    let succeeded = false;
    router.visit(
        `/visits/${encodeURIComponent(row.visitNumber)}/queue/readiness`,
        {
            method: clear ? 'patch' : 'post',
            data: {
                expected_branch_id: props.branchId,
                queue_lock_version: entry.queueLockVersion,
                ...(clear
                    ? {
                          lock_version: row.callHold?.lockVersion,
                          call_hold_id: row.callHold?.id,
                      }
                    : { reason_code: reason.value }),
                idempotency_key: key,
                from: 'queue',
            },
            preserveScroll: true,
            preserveState: false,
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
    const row = props.row!;
    const entry = row.source as QueueRow;

    if (!entry.visitLockVersion || !cancellationReason) {
        return;
    }

    // The shared V2 reason box has no length limit; say so instead of cutting the reason silently.
    if (cancellationReason.length > 500) {
        error.value = 'Keep the reason to 500 characters or fewer.';

        return;
    }

    busy.value = true;
    emit('busy', true);
    error.value = '';
    let succeeded = false;
    router.patch(
        `/visits/${encodeURIComponent(row.visitNumber)}/cancel`,
        {
            expected_branch_id: props.branchId,
            lock_version: entry.visitLockVersion,
            queue_lock_version: entry.queueLockVersion,
            cancellation_reason: cancellationReason,
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

const callToRoom = () => {
    const row = props.row!;

    if (roomId.value === '') {
        error.value = 'Choose a treatment room.';

        return;
    }

    busy.value = true;
    emit('busy', true);
    error.value = '';
    let succeeded = false;
    router.patch(
        `/visits/${encodeURIComponent(row.visitNumber)}/queue/treatment-call`,
        {
            expected_branch_id: props.branchId,
            queue_lock_version: (row.source as QueueRow).queueLockVersion,
            branch_room_id: roomId.value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                succeeded = true;
            },
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The patient could not be called to the treatment room.';
            },
            onFinish: () => finish(succeeded),
        },
    );
};

const callIn = () => {
    const row = props.row!;
    const entry = row.source as QueueRow;

    if (row.callHold?.active && !overrideReason.value) {
        error.value = 'Select a reason to override the flag.';

        return;
    }

    key ||= crypto.randomUUID();
    busy.value = true;
    emit('busy', true);
    error.value = '';
    let succeeded = false;
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
                      idempotency_key: key,
                  }
                : {}),
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'Call In failed.';
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

    if (props.kind === 'call') {
        callIn();
    } else if (props.kind === 'cancel') {
        cancel(cancellationReason);
    } else if (props.kind === 'treatmentCall') {
        callToRoom();
    } else {
        readiness(props.kind === 'ready');
    }
};
const title = computed(() => {
    switch (props.kind) {
        case 'call':
            return 'Call this patient?';
        case 'cancel':
            return 'Cancel visit';
        case 'ready':
            return 'Ready to be called?';
        case 'treatmentCall':
            return `Call ${props.row?.queueNumber ?? ''} to a treatment room`.replace(
                '  ',
                ' ',
            );
        default:
            return 'Not ready to call';
    }
});
const description = computed(() => {
    switch (props.kind) {
        case 'call':
            return 'Confirm that you want to call this patient for consultation.';
        case 'cancel':
            return `${props.row?.patientName}${props.row?.queueNumber ? `, ${props.row.queueNumber}` : ''}. The visit is removed from the queue and cannot be undone.`;
        case 'ready':
            return 'Clear this flag when the issue is resolved. The doctor will be notified.';
        case 'treatmentCall':
            return 'The waiting-room TV shows and announces it. The consultation stays with the doctor.';
        default:
            return 'Choose why this patient cannot be called yet. Their place in the queue does not change.';
    }
});
const confirmLabel = computed(() =>
    props.kind === 'cancel'
        ? 'Cancel visit'
        : props.kind === 'treatmentCall'
          ? 'Call to room'
          : props.kind === 'call'
            ? 'Call patient'
            : 'Confirm',
);
</script>

<template>
    <V2Dialog
        :open="open"
        :title="title"
        :description="description"
        :confirm-label="confirmLabel"
        :cancel-label="kind === 'cancel' ? 'Keep visit' : 'Cancel'"
        :tone="kind === 'cancel' ? 'danger' : 'default'"
        :reason-label="kind === 'cancel' ? 'Cancellation reason' : undefined"
        :busy="busy"
        @update:open="(value) => !value && !busy && emit('close')"
        @confirm="confirm"
    >
        <template v-if="kind === 'call'">
            <div v-if="row" class="rounded-[14px] border bg-muted/40 px-4 py-3">
                <p class="font-medium text-foreground">{{ row.patientName }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    <span class="whitespace-nowrap">{{ row.queueNumber }}</span>
                    · {{ row.patientNumber }}
                </p>
            </div>
            <div v-if="row?.callHold?.active" class="grid gap-2 text-sm">
                <p class="break-words">
                    Not ready to call: {{ row.callHold.reason }}. Choose a
                    reason to call anyway.
                </p>
                <OperationalSelect
                    v-model="overrideReason"
                    label="Reason to call anyway"
                    placeholder="Select a reason"
                    :options="[
                        {
                            value: 'patient_is_urgent',
                            label: 'Patient is urgent',
                        },
                        {
                            value: 'issue_resolved_verbally',
                            label: 'Issue resolved verbally',
                        },
                        {
                            value: 'patient_at_door',
                            label: 'Patient is at the door',
                        },
                    ]"
                />
            </div>
        </template>
        <p v-else-if="kind === 'ready'" class="text-sm break-words">
            {{ row?.callHold?.reason }}
        </p>
        <V2OptionGroup
            v-else-if="kind === 'notReady'"
            v-model="reason"
            label="Reason"
            required
            :options="
                NOT_READY_REASONS.map(([value, label]) => ({ value, label }))
            "
            :error="error === 'Select a reason.' ? error : undefined"
        />
        <div v-else-if="kind === 'treatmentCall'" class="grid gap-2">
            <OperationalSelect
                v-model="roomId"
                label="Treatment room"
                placeholder="Choose room"
                :options="
                    (treatmentRooms ?? []).map((room) => ({
                        value: room.id,
                        label: room.name,
                    }))
                "
            />
        </div>
        <p
            v-if="error && error !== 'Select a reason.'"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ error }}
        </p>
    </V2Dialog>
</template>
