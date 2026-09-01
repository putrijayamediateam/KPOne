<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PatientBoardRow } from '@/types';

const props = defineProps<{
    open: boolean;
    row: PatientBoardRow | null;
    branchId: number;
    visitLockVersion: number | null;
    queueLockVersion: number | null;
}>();
const emit = defineEmits<{ 'update:open': [value: boolean]; completed: [] }>();
const reason = ref('');
const error = ref('');
const processing = ref(false);

watch(
    () => props.open,
    (open) => {
        if (open) {
            reason.value = '';
            error.value = '';
        }
    },
);

const submit = () => {
    if (!props.row || !props.visitLockVersion || processing.value) {
        return;
    }

    processing.value = true;
    error.value = '';
    router.patch(
        `/visits/${encodeURIComponent(props.row.visitNumber)}/cancel`,
        {
            expected_branch_id: props.branchId,
            lock_version: props.visitLockVersion,
            queue_lock_version: props.queueLockVersion,
            cancellation_reason: reason.value,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ?? 'Visit cancellation failed.';
            },
            onSuccess: () => {
                emit('update:open', false);
                emit('completed');
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Cancel Visit</DialogTitle>
                <DialogDescription
                    >Record an operational reason. This action remains subject
                    to current Visit authorization.</DialogDescription
                >
            </DialogHeader>
            <label class="grid gap-1 text-sm">
                Cancellation reason
                <textarea
                    v-model="reason"
                    maxlength="500"
                    rows="4"
                    class="rounded-md border bg-background p-3"
                />
            </label>
            <InputError :message="error" />
            <DialogFooter>
                <Button
                    variant="outline"
                    type="button"
                    @click="emit('update:open', false)"
                    >Keep Visit</Button
                >
                <Button
                    variant="destructive"
                    type="button"
                    :disabled="processing || !reason.trim()"
                    @click="submit"
                >
                    <LoaderCircle
                        v-if="processing"
                        class="size-4 animate-spin"
                    />
                    Cancel Visit
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
