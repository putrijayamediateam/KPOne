<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ShieldCheck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';

type ClinicalSafetyConfirmationKind =
    | 'declare-no-known'
    | 'allergy-entered-in-error'
    | 'problem-resolve'
    | 'problem-entered-in-error';

const props = defineProps<{
    open: boolean;
    kind: ClinicalSafetyConfirmationKind | null;
    visitNumber: string;
    branchId: number;
    profileLockVersion?: number | null;
    recordPublicId?: string | null;
    recordLockVersion?: number | null;
    recordLabel?: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    processing: [value: boolean];
}>();

const processing = ref(false);
const error = ref('');

const configuration = computed(() => {
    switch (props.kind) {
        case 'declare-no-known':
            return {
                title: 'Declare no known allergies?',
                description:
                    'Confirm that you reviewed the current Allergy Profile and identified no known allergies at this time.',
                confirmLabel: 'Declare no known allergies',
                processingLabel: 'Declaring…',
                destructive: false,
                icon: 'shield',
            } as const;
        case 'allergy-entered-in-error':
            return {
                title: 'Mark this allergy as entered in error?',
                description:
                    'This record will no longer be treated as an active allergy. Use this only when the record itself was erroneous, not when an allergy was cured.',
                confirmLabel: 'Mark as entered in error',
                processingLabel: 'Marking…',
                destructive: true,
                icon: 'warning',
            } as const;
        case 'problem-resolve':
            return {
                title: 'Resolve this problem?',
                description:
                    'Confirm that this problem should be marked as resolved.',
                confirmLabel: 'Mark as resolved',
                processingLabel: 'Resolving…',
                destructive: false,
                icon: 'check',
            } as const;
        case 'problem-entered-in-error':
            return {
                title: 'Mark this problem as entered in error?',
                description:
                    'This record will no longer be treated as an active clinical problem. Use this only when the record itself was erroneous.',
                confirmLabel: 'Mark as entered in error',
                processingLabel: 'Marking…',
                destructive: true,
                icon: 'warning',
            } as const;
        default:
            return {
                title: '',
                description: '',
                confirmLabel: 'Confirm',
                processingLabel: 'Working…',
                destructive: false,
                icon: 'shield',
            } as const;
    }
});

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

const finish = () => {
    processing.value = false;
    emit('processing', false);
};

const fail = (errors: Record<string, string>) => {
    const message =
        Object.values(errors)[0] ??
        (props.kind?.startsWith('allergy') || props.kind === 'declare-no-known'
            ? 'The Allergy action could not be completed. Review the latest information and try again.'
            : 'The Problem List action could not be completed. Review the latest information and try again.');
    error.value = message;
};

const options = () => ({
    preserveScroll: true,
    onError: fail,
    onSuccess: () => emit('update:open', false),
    onFinish: finish,
});

const submit = () => {
    if (!props.kind || processing.value) {
        return;
    }

    const baseUrl = `/visits/${encodeURIComponent(props.visitNumber)}/encounter`;
    processing.value = true;
    error.value = '';
    emit('processing', true);

    if (props.kind === 'declare-no-known') {
        router.post(
            `${baseUrl}/allergies/no-known`,
            {
                expected_branch_id: props.branchId,
                profile_lock_version: props.profileLockVersion ?? null,
            },
            options(),
        );

        return;
    }

    if (!props.recordPublicId) {
        finish();

        return;
    }

    if (props.kind === 'allergy-entered-in-error') {
        router.patch(
            `${baseUrl}/allergies/${encodeURIComponent(props.recordPublicId)}/entered-in-error`,
            {
                expected_branch_id: props.branchId,
                profile_lock_version: props.profileLockVersion ?? null,
            },
            options(),
        );

        return;
    }

    const action =
        props.kind === 'problem-resolve' ? 'resolve' : 'entered-in-error';
    router.patch(
        `${baseUrl}/problems/${encodeURIComponent(props.recordPublicId)}/${action}`,
        {
            expected_branch_id: props.branchId,
            lock_version: props.recordLockVersion ?? null,
            ...(action === 'resolve' ? { resolved_date: null } : {}),
        },
        options(),
    );
};
</script>

<template>
    <OperationalConfirmDialog
        :open="open"
        :title="configuration.title"
        :description="configuration.description"
        :confirm-label="configuration.confirmLabel"
        :processing-label="configuration.processingLabel"
        :processing="processing"
        :destructive="configuration.destructive"
        :error="error"
        @update:open="updateOpen"
        @confirm="submit"
    >
        <template #icon>
            <AlertTriangle
                v-if="configuration.icon === 'warning'"
                class="size-5"
            />
            <CheckCircle2
                v-else-if="configuration.icon === 'check'"
                class="size-5"
            />
            <ShieldCheck v-else class="size-5" />
        </template>

        <div
            v-if="recordLabel"
            class="rounded-xl border border-border/70 bg-muted/35 px-4 py-3"
        >
            <p class="font-medium break-words text-foreground">
                {{ recordLabel }}
            </p>
        </div>
    </OperationalConfirmDialog>
</template>
