<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PatientBoardRow, QueueRow } from '@/types';
const props = defineProps<{
    row: PatientBoardRow;
    branchId: number;
    from: 'queue' | 'registration';
}>();
const open = ref(false);
const reason = ref('');
const error = ref('');
const busy = ref(false);
let key = '';
const reasons = [
    ['awaiting_panel_confirmation', 'Awaiting Panel confirmation'],
    ['awaiting_patient_details', 'Awaiting patient details'],
    ['awaiting_payment_or_deposit', 'Awaiting payment or deposit'],
    ['other_administrative', 'Other administrative'],
];
watch(
    () => [
        props.branchId,
        props.row.callHold?.id,
        props.row.callHold?.lockVersion,
    ],
    () => {
        open.value = false;
        reason.value = '';
        key = '';
        error.value = '';
    },
);
watch(reason, () => {
    key = '';
});
const submit = () => {
    if (busy.value) {
        return;
    }

    const clear = props.row.callHold?.active;

    if (!clear && !reason.value) {
        error.value = 'Select a reason.';

        return;
    }

    key ||= crypto.randomUUID();
    busy.value = true;
    error.value = '';
    const entry = props.row.source as QueueRow;
    router.visit(
        `/visits/${encodeURIComponent(props.row.visitNumber)}/queue/readiness`,
        {
            method: clear ? 'patch' : 'post',
            data: {
                expected_branch_id: props.branchId,
                queue_lock_version: entry.queueLockVersion,
                ...(clear
                    ? {
                          lock_version: props.row.callHold?.lockVersion,
                          call_hold_id: props.row.callHold?.id,
                      }
                    : { reason_code: reason.value }),
                idempotency_key: key,
                from: props.from,
            },
            preserveScroll: true,
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'The action failed.';
            },
            onSuccess: () => {
                open.value = false;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
</script>
<template>
    <Button
        v-if="
            row.statusTone === 'waiting' &&
            (row.callHold?.active
                ? row.callHold.canClear
                : row.callHold?.canSet)
        "
        type="button"
        variant="outline"
        class="h-auto min-h-11 w-full py-2 text-xs leading-4 whitespace-normal"
        @click="open = true"
    >
        {{ row.callHold?.active ? 'Ready to be called' : 'Mark not ready' }}
    </Button>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="max-h-[90dvh] overflow-y-auto"
        >
            <DialogHeader>
                <DialogTitle>{{
                    row.callHold?.active
                        ? 'Ready to be called?'
                        : 'Not ready to call'
                }}</DialogTitle>
                <DialogDescription>{{
                    row.callHold?.active
                        ? 'Clear this flag when the issue is resolved. The doctor will be notified.'
                        : 'Select an administrative reason. The queue order and patient number stay unchanged.'
                }}</DialogDescription>
            </DialogHeader>
            <p v-if="row.callHold?.active" class="break-words">
                {{ row.callHold.reason }}
            </p>
            <label v-else class="grid gap-2"
                >Reason
                <select
                    v-model="reason"
                    class="min-h-11 w-full rounded-md border bg-background px-3"
                >
                    <option value="">Select a reason</option>
                    <option
                        v-for="item in reasons"
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
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11"
                    :disabled="busy"
                    @click="open = false"
                    >Cancel</Button
                >
                <Button
                    type="button"
                    class="min-h-11"
                    :disabled="busy"
                    @click="submit"
                    >Confirm</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
