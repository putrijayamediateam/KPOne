<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';

/**
 * BV2-2: Version 2 completion dialog. The script below is a verbatim copy of Classic
 * `components/billing/BillingCompletionDialog.vue` (same post, payload and guards), pinned by
 * `tests/Frontend/bv2-2-billing-v2-dialogs.test.mjs`. Only the template differs (V2Dialog). Classic is untouched.
 */
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
    <V2Dialog
        :open="open"
        :title="`Complete Visitation ${visitNumber.replace(/-/g, '\u2011')}?`"
        description="After you complete the visit, it cannot be edited. The invoice and the payments stay as they are."
        :confirm-label="processing ? 'Completing…' : 'Complete Visitation'"
        :busy="processing"
        data-testid="completion-dialog-v2"
        @update:open="updateOpen"
        @confirm="submit"
    >
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
    </V2Dialog>
</template>
