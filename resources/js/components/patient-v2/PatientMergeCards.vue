<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import type { PatientMergePanelData } from '@/components/patient/PatientMergePanel.vue';
import { OperationalSelect } from '@/components/ui/select';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import { formatDateTime } from '@/lib/presentation';

/**
 * Version 2 merge cards (DD-78: a copy of the Classic panel's logic in V2 cards, to be restyled with the Patient
 * merges page). Same props, same endpoints, same payloads and lock versions as `PatientMergePanel.vue`
 * (`GET merge-compare`, `POST merge-requests`, `POST /patient-merges/{id}/approve|reject|withdraw`). Every confirm is
 * a V2Dialog instead of the browser's confirm(); no filled button sits outside a dialog.
 */
type Option = { value: string; label: string };
type MergeRequestRow = PatientMergePanelData['pending'][number];
type Summary = {
    patientNumber: string;
    fullName: string;
    dateOfBirth: string | null;
    sex: string;
    identifier: { type: string; maskedValue: string } | null;
    maskedPhone: string | null;
    visitCount: number;
    lastVisitAt: string | null;
    lockVersion: number;
    mergedAway: boolean;
};

const props = defineProps<{
    patientNumber: string;
    merge: PatientMergePanelData;
}>();

const branchId = (
    usePage().props.branchContext as { active?: { id: number } } | undefined
)?.active?.id;
const patientUrl = computed(
    () => '/patients/' + encodeURIComponent(props.patientNumber),
);
const when = (value: string | null) =>
    value ? formatDateTime(value) : 'No date';
const reasonOptions = (options: Option[]) => [
    { value: '', label: 'Choose a reason' },
    ...options,
];

const outlineButton =
    'inline-flex h-11 items-center justify-center gap-1.5 rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 lg:h-9';

const otherNumber = ref('');
const checking = ref(false);
const checkError = ref('');
const comparison = ref<{
    kept: Summary;
    other: Summary;
    blockers: string[];
} | null>(null);

const proposal = useForm({
    kind: 'merge' as 'merge' | 'unmerge',
    other_patient_number: '',
    reason_code: '',
    expected_branch_id: branchId,
    kept_lock_version: props.merge.lockVersion,
    other_lock_version: 0,
    idempotency_key: crypto.randomUUID(),
});

const check = async () => {
    checking.value = true;
    checkError.value = '';
    comparison.value = null;

    try {
        const response = await fetch(
            patientUrl.value +
                '/merge-compare?other_patient_number=' +
                encodeURIComponent(otherNumber.value.trim()),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );
        const body = await response.json();

        if (!response.ok) {
            checkError.value =
                body?.errors?.other_patient_number?.[0] ??
                'The record could not be checked. Try again.';

            return;
        }

        comparison.value = body;
        proposal.other_patient_number = body.other.patientNumber;
        proposal.other_lock_version = body.other.lockVersion;
        proposal.kept_lock_version = body.kept.lockVersion;
    } catch {
        checkError.value = 'The record could not be checked. Try again.';
    } finally {
        checking.value = false;
    }
};

// One confirm dialog for every action; what it says and does depends on `confirming`.
type Confirming =
    | { kind: 'propose' }
    | { kind: 'unmerge'; record: PatientMergePanelData['mergedFrom'][number] }
    | { kind: 'approve' | 'reject' | 'withdraw'; request: MergeRequestRow };
const confirming = ref<Confirming | null>(null);
const unmergeReason = ref('');

const submitMerge = () => {
    proposal.kind = 'merge';
    proposal.post(patientUrl.value + '/merge-requests', {
        preserveScroll: true,
        onSuccess: () => {
            comparison.value = null;
            otherNumber.value = '';
            proposal.reset('reason_code');
            proposal.idempotency_key = crypto.randomUUID();
        },
        onFinish: () => {
            confirming.value = null;
        },
    });
};

const submitUnmerge = (patientNumber: string, lockVersion: number) => {
    router.post(
        patientUrl.value + '/merge-requests',
        {
            kind: 'unmerge',
            other_patient_number: patientNumber,
            reason_code: unmergeReason.value,
            expected_branch_id: branchId,
            kept_lock_version: props.merge.lockVersion,
            other_lock_version: lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onStart: () => {
                acting.value = true;
            },
            onSuccess: () => {
                unmergeReason.value = '';
            },
            onFinish: () => {
                acting.value = false;
                confirming.value = null;
            },
        },
    );
};

const decide = (
    request: MergeRequestRow,
    action: 'approve' | 'reject' | 'withdraw',
) => {
    router.post(
        `/patient-merges/${request.publicId}/${action}`,
        {
            expected_branch_id: branchId,
            lock_version: request.lockVersion,
            from: 'patient',
            patient_number: props.patientNumber,
        },
        {
            preserveScroll: true,
            onStart: () => {
                acting.value = true;
            },
            onFinish: () => {
                acting.value = false;
                confirming.value = null;
            },
        },
    );
};

const dialog = computed(() => {
    const c = confirming.value;

    if (!c) {
        return null;
    }

    if (c.kind === 'propose') {
        return {
            title: 'Send this merge for approval?',
            description: `This record (${props.patientNumber}) is kept and ${comparison.value?.other.patientNumber ?? 'the duplicate'} is hidden, never deleted. A different CA Supervisor or Director must approve it, and it can be undone.`,
            confirmLabel: 'Send for approval',
            tone: 'default' as const,
        };
    }

    if (c.kind === 'unmerge') {
        return {
            title: `Ask to unmerge ${c.record.patientNumber}?`,
            description:
                'A different CA Supervisor or Director must approve before the record comes back.',
            confirmLabel: 'Send for approval',
            tone: 'default' as const,
        };
    }

    const request = c.request;

    if (c.kind === 'approve') {
        return {
            title:
                request.kind === 'merge'
                    ? `Approve merging ${request.mergedPatientNumber} into ${request.keptPatientNumber}?`
                    : `Approve unmerging ${request.mergedPatientNumber} from ${request.keptPatientNumber}?`,
            description: 'This takes effect straight away.',
            confirmLabel: 'Approve',
            tone: 'default' as const,
        };
    }

    return c.kind === 'reject'
        ? {
              title: 'Reject this request?',
              description: 'The records stay as they are.',
              confirmLabel: 'Reject',
              tone: 'danger' as const,
          }
        : {
              title: 'Withdraw your request?',
              description: 'The records stay as they are.',
              confirmLabel: 'Withdraw',
              tone: 'default' as const,
          };
});

const confirmDialog = () => {
    const c = confirming.value;

    if (!c) {
        return;
    }

    if (c.kind === 'propose') {
        submitMerge();
    } else if (c.kind === 'unmerge') {
        if (!unmergeReason.value) {
            unmergeTried.value = true;

            return;
        }

        submitUnmerge(c.record.patientNumber, c.record.lockVersion);
    } else {
        decide(c.request, c.kind);
    }
};

const acting = ref(false);
const busy = computed(() => proposal.processing || acting.value);
const unmergeTried = ref(false);

const identifierText = (summary: Summary) =>
    summary.identifier
        ? `${summary.identifier.type === 'nric' ? 'NRIC' : 'Passport'} ${summary.identifier.maskedValue}`
        : 'None recorded';
const sexText = (sex: string) => sex.charAt(0).toUpperCase() + sex.slice(1);
const rows = (summary: Summary) => [
    ['KP number', summary.patientNumber],
    ['Name', summary.fullName],
    ['Date of birth', summary.dateOfBirth ?? 'Not recorded'],
    ['Gender', sexText(summary.sex)],
    ['IC or passport', identifierText(summary)],
    ['Phone', summary.maskedPhone ?? 'Not recorded'],
    ['Visits', String(summary.visitCount)],
    ['Last visit', when(summary.lastVisitAt)],
];
</script>

<template>
    <div class="flex min-w-0 flex-col gap-5" data-slot="patient-merge-cards">
        <section
            v-if="merge.mergedFrom.length"
            class="flex min-w-0 flex-col gap-3 rounded-[14px] border border-border bg-card p-5"
            data-testid="merged-records"
        >
            <h2 class="text-base font-semibold">Merged records</h2>
            <p class="text-sm text-muted-foreground">
                These duplicate records were merged into this patient. Their
                visits, invoices and documents keep their own KP number and are
                listed with this patient.
            </p>
            <div
                v-for="record in merge.mergedFrom"
                :key="record.patientNumber"
                class="flex flex-col gap-2 rounded-[10px] border p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0 break-words">
                    <span class="font-mono font-medium">{{
                        record.patientNumber
                    }}</span>
                    · {{ record.fullName }} · merged
                    {{ when(record.mergedAt) }}
                </div>
                <div v-if="merge.canPropose">
                    <button
                        type="button"
                        :class="[outlineButton, 'max-sm:w-full']"
                        @click="
                            unmergeReason = '';
                            unmergeTried = false;
                            confirming = { kind: 'unmerge', record };
                        "
                    >
                        Unmerge…
                    </button>
                </div>
            </div>
        </section>

        <section
            v-if="merge.pending.length"
            class="flex min-w-0 flex-col gap-3 rounded-[14px] border border-warning/40 bg-warning/10 p-5"
            data-testid="pending-merges"
        >
            <h2 class="text-base font-semibold">Waiting for approval</h2>
            <div
                v-for="request in merge.pending"
                :key="request.publicId"
                class="flex flex-col gap-3 rounded-[10px] border bg-card p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0 break-words">
                    <span class="font-medium">{{
                        request.kind === 'merge' ? 'Merge' : 'Unmerge'
                    }}</span>
                    <span class="font-mono">
                        {{ request.mergedPatientNumber }}</span
                    >
                    {{ request.kind === 'merge' ? 'into' : 'from' }}
                    <span class="font-mono">{{
                        request.keptPatientNumber
                    }}</span>
                    · {{ request.reason }}
                    <div class="text-xs text-muted-foreground">
                        Proposed by {{ request.proposedBy }} ·
                        {{ when(request.proposedAt) }}
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 max-sm:[&>button]:flex-1">
                    <template v-if="request.canDecide">
                        <button
                            type="button"
                            :class="outlineButton"
                            @click="confirming = { kind: 'approve', request }"
                        >
                            Approve…
                        </button>
                        <button
                            type="button"
                            :class="outlineButton"
                            @click="confirming = { kind: 'reject', request }"
                        >
                            Reject…
                        </button>
                    </template>
                    <button
                        v-if="request.canWithdraw"
                        type="button"
                        :class="outlineButton"
                        @click="confirming = { kind: 'withdraw', request }"
                    >
                        Withdraw…
                    </button>
                    <span
                        v-if="!request.canDecide && !request.canWithdraw"
                        class="text-xs text-muted-foreground"
                        >A different CA Supervisor or Director must
                        approve.</span
                    >
                </div>
            </div>
        </section>

        <section
            v-if="merge.canPropose"
            class="flex min-w-0 flex-col gap-4 rounded-[14px] border border-border bg-card p-5"
            data-testid="merge-duplicate"
        >
            <div>
                <h2 class="text-base font-semibold">
                    Merge a duplicate into this record
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Use this when the same person was registered twice. This
                    record is kept; the duplicate is hidden, never deleted. A
                    different CA Supervisor or Director must approve, and it can
                    be undone.
                </p>
            </div>
            <form
                class="flex flex-col gap-3 sm:flex-row sm:items-end"
                @submit.prevent="check"
            >
                <label class="grid min-w-0 flex-1 gap-1.5 text-sm">
                    <span class="font-medium">KP number of the duplicate</span>
                    <input
                        v-model="otherNumber"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 font-mono text-base uppercase outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                        placeholder="KP-00000000"
                        maxlength="20"
                        autocomplete="off"
                    />
                </label>
                <button
                    type="submit"
                    :class="outlineButton"
                    :disabled="checking || !otherNumber.trim()"
                >
                    {{ checking ? 'Checking…' : 'Check' }}
                </button>
            </form>
            <InputError :message="checkError" />

            <div v-if="comparison" class="flex flex-col gap-4">
                <div class="grid gap-3 md:grid-cols-2">
                    <div
                        v-for="[label, summary] in [
                            ['Kept record', comparison.kept],
                            ['Duplicate (will be hidden)', comparison.other],
                        ] as const"
                        :key="label"
                        class="min-w-0 rounded-[10px] border p-3"
                    >
                        <h3 class="mb-2 text-sm font-semibold">{{ label }}</h3>
                        <dl class="grid gap-1 text-sm">
                            <div
                                v-for="[term, value] in rows(summary)"
                                :key="term"
                                class="flex justify-between gap-3"
                            >
                                <dt class="text-muted-foreground">
                                    {{ term }}
                                </dt>
                                <dd class="min-w-0 text-right break-words">
                                    {{ value }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
                <ul
                    v-if="comparison.blockers.length"
                    class="space-y-1 rounded-[10px] border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
                    data-testid="merge-blockers"
                >
                    <li v-for="blocker in comparison.blockers" :key="blocker">
                        {{ blocker }}
                    </li>
                </ul>
                <form
                    v-else
                    class="flex flex-col gap-3 sm:flex-row sm:items-end"
                    @submit.prevent="confirming = { kind: 'propose' }"
                >
                    <label class="grid min-w-0 flex-1 gap-1.5 text-sm">
                        <span class="font-medium">Reason</span>
                        <OperationalSelect
                            v-model="proposal.reason_code"
                            label="Reason"
                            :options="reasonOptions(merge.mergeReasons)"
                        />
                    </label>
                    <button
                        type="submit"
                        :class="outlineButton"
                        :disabled="proposal.processing || !proposal.reason_code"
                    >
                        Send for approval…
                    </button>
                </form>
                <InputError
                    :message="
                        proposal.errors.other_patient_number ||
                        proposal.errors.reason_code ||
                        proposal.errors.idempotency_key
                    "
                />
            </div>
        </section>

        <V2Dialog
            v-if="dialog"
            :open="true"
            :title="dialog.title"
            :description="dialog.description"
            :confirm-label="dialog.confirmLabel"
            :tone="dialog.tone"
            :busy="busy"
            @update:open="(open: boolean) => !open && (confirming = null)"
            @confirm="confirmDialog"
        >
            <div
                v-if="confirming?.kind === 'unmerge'"
                class="flex flex-col gap-1.5"
            >
                <span class="text-sm font-medium">Reason for unmerge</span>
                <OperationalSelect
                    v-model="unmergeReason"
                    label="Reason for unmerge"
                    :options="reasonOptions(merge.unmergeReasons)"
                />
                <p
                    v-if="unmergeTried && !unmergeReason"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    Choose a reason to continue.
                </p>
            </div>
        </V2Dialog>
    </div>
</template>
