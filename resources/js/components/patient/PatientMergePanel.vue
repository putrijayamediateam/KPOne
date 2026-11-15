<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { GitMerge, Search, Undo2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

type Option = { value: string; label: string };
type MergeRequestRow = {
    publicId: string;
    kind: 'merge' | 'unmerge';
    keptPatientNumber: string | null;
    mergedPatientNumber: string | null;
    reason: string;
    proposedBy: string | null;
    proposedAt: string;
    lockVersion: number;
    canDecide: boolean;
    canWithdraw: boolean;
};
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
export type PatientMergePanelData = {
    canPropose: boolean;
    canApprove: boolean;
    lockVersion: number;
    mergedFrom: {
        patientNumber: string;
        fullName: string;
        mergedAt: string | null;
        lockVersion: number;
    }[];
    pending: MergeRequestRow[];
    mergeReasons: Option[];
    unmergeReasons: Option[];
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
const formatTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('en-MY', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'Asia/Kuala_Lumpur',
          }).format(new Date(value))
        : 'None';

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
    });
};

const unmergeTarget = ref<string | null>(null);
const unmergeReason = ref('');
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
            onSuccess: () => {
                unmergeTarget.value = null;
                unmergeReason.value = '';
            },
        },
    );
};

const decide = (
    request: MergeRequestRow,
    action: 'approve' | 'reject' | 'withdraw',
) => {
    const question =
        action === 'approve'
            ? request.kind === 'merge'
                ? `Approve merging ${request.mergedPatientNumber} into ${request.keptPatientNumber}?`
                : `Approve unmerging ${request.mergedPatientNumber} from ${request.keptPatientNumber}?`
            : action === 'reject'
              ? 'Reject this request?'
              : 'Withdraw your request?';

    if (!confirm(question)) {
        return;
    }

    router.post(
        `/patient-merges/${request.publicId}/${action}`,
        {
            expected_branch_id: branchId,
            lock_version: request.lockVersion,
            from: 'patient',
            patient_number: props.patientNumber,
        },
        { preserveScroll: true },
    );
};

const rows = (summary: Summary) => [
    ['KP number', summary.patientNumber],
    ['Name', summary.fullName],
    ['Date of birth', summary.dateOfBirth ?? 'Not recorded'],
    ['Gender', summary.sex],
    [
        'IC / Passport',
        summary.identifier
            ? `${summary.identifier.type.toUpperCase()} ${summary.identifier.maskedValue}`
            : 'None',
    ],
    ['Phone', summary.maskedPhone ?? 'Not recorded'],
    ['Visits', String(summary.visitCount)],
    ['Last visit', formatTime(summary.lastVisitAt)],
];
</script>

<template>
    <section
        v-if="merge.mergedFrom.length"
        class="space-y-3 rounded-lg border border-border bg-muted/40 p-5"
        data-testid="merged-records"
    >
        <h2 class="font-semibold">Merged records</h2>
        <p class="text-sm text-muted-foreground">
            These duplicate records were merged into this patient. Their visits,
            invoices and documents keep their own KP number and are listed with
            this patient.
        </p>
        <div
            v-for="record in merge.mergedFrom"
            :key="record.patientNumber"
            class="flex flex-col gap-2 rounded-md border bg-card p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0 break-words">
                <span class="font-mono font-medium">{{
                    record.patientNumber
                }}</span>
                · {{ record.fullName }} · merged
                {{ formatTime(record.mergedAt) }}
            </div>
            <div
                v-if="merge.canPropose"
                class="flex flex-wrap items-center gap-2"
            >
                <template v-if="unmergeTarget === record.patientNumber">
                    <select
                        v-model="unmergeReason"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                        aria-label="Reason for unmerge"
                    >
                        <option value="" disabled>Choose a reason</option>
                        <option
                            v-for="option in merge.unmergeReasons"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <Button
                        size="sm"
                        :disabled="!unmergeReason"
                        @click="
                            submitUnmerge(
                                record.patientNumber,
                                record.lockVersion,
                            )
                        "
                        >Send for approval</Button
                    >
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="unmergeTarget = null"
                        >Cancel</Button
                    >
                </template>
                <Button
                    v-else
                    size="sm"
                    variant="outline"
                    @click="unmergeTarget = record.patientNumber"
                    ><Undo2 class="size-4" /> Unmerge</Button
                >
            </div>
        </div>
    </section>

    <section
        v-if="merge.pending.length"
        class="space-y-3 rounded-lg border border-warning/25 bg-warning/10 p-5"
        data-testid="pending-merges"
    >
        <h2 class="font-semibold">Waiting for approval</h2>
        <div
            v-for="request in merge.pending"
            :key="request.publicId"
            class="flex flex-col gap-2 rounded-md border bg-card p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0 break-words">
                <span class="font-medium">{{
                    request.kind === 'merge' ? 'Merge' : 'Unmerge'
                }}</span>
                <span class="font-mono">
                    {{ request.mergedPatientNumber }}</span
                >
                {{ request.kind === 'merge' ? 'into' : 'from' }}
                <span class="font-mono">{{ request.keptPatientNumber }}</span>
                · {{ request.reason }}
                <div class="text-xs text-muted-foreground">
                    Proposed by {{ request.proposedBy }} ·
                    {{ formatTime(request.proposedAt) }}
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <template v-if="request.canDecide">
                    <Button size="sm" @click="decide(request, 'approve')"
                        >Approve</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="decide(request, 'reject')"
                        >Reject</Button
                    >
                </template>
                <Button
                    v-if="request.canWithdraw"
                    size="sm"
                    variant="outline"
                    @click="decide(request, 'withdraw')"
                    >Withdraw</Button
                >
                <span
                    v-if="!request.canDecide && !request.canWithdraw"
                    class="text-xs text-muted-foreground"
                    >A different CA Supervisor or Director must approve.</span
                >
            </div>
        </div>
    </section>

    <section
        v-if="merge.canPropose"
        class="space-y-4 rounded-lg border bg-card p-5"
        data-testid="merge-duplicate"
    >
        <div>
            <h2 class="flex items-center gap-2 font-semibold">
                <GitMerge class="size-4" /> Merge a duplicate into this record
            </h2>
            <p class="text-sm text-muted-foreground">
                Use this when the same person was registered twice. This record
                is kept; the duplicate is hidden, never deleted. A different CA
                Supervisor or Director must approve, and it can be undone.
            </p>
        </div>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="check">
            <label class="grid min-w-0 flex-1 gap-1.5 text-sm">
                <span class="font-medium">KP number of the duplicate</span>
                <input
                    v-model="otherNumber"
                    class="h-10 w-full min-w-0 rounded-md border bg-background px-3 font-mono uppercase"
                    placeholder="KP-00000000"
                    maxlength="20"
                    autocomplete="off"
                />
            </label>
            <Button
                type="submit"
                variant="outline"
                :disabled="checking || !otherNumber.trim()"
                ><Search class="size-4" />
                {{ checking ? 'Checking…' : 'Check' }}</Button
            >
        </form>
        <InputError :message="checkError" />

        <div v-if="comparison" class="space-y-4">
            <div class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="[label, summary] in [
                        ['Kept record', comparison.kept],
                        ['Duplicate (will be hidden)', comparison.other],
                    ] as const"
                    :key="label"
                    class="min-w-0 rounded-md border p-3"
                >
                    <h3 class="mb-2 text-sm font-semibold">{{ label }}</h3>
                    <dl class="grid gap-1 text-sm">
                        <div
                            v-for="[term, value] in rows(summary)"
                            :key="term"
                            class="flex justify-between gap-3"
                        >
                            <dt class="text-muted-foreground">{{ term }}</dt>
                            <dd
                                class="min-w-0 text-right break-words"
                                :class="{ capitalize: term === 'Gender' }"
                            >
                                {{ value }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
            <ul
                v-if="comparison.blockers.length"
                class="space-y-1 rounded-md border border-destructive/25 bg-destructive/10 p-3 text-sm text-destructive"
                data-testid="merge-blockers"
            >
                <li v-for="blocker in comparison.blockers" :key="blocker">
                    {{ blocker }}
                </li>
            </ul>
            <form
                v-else
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="submitMerge"
            >
                <label class="grid min-w-0 flex-1 gap-1.5 text-sm">
                    <span class="font-medium">Reason</span>
                    <select
                        v-model="proposal.reason_code"
                        class="h-10 w-full min-w-0 rounded-md border bg-background px-2"
                    >
                        <option value="" disabled>Choose a reason</option>
                        <option
                            v-for="option in merge.mergeReasons"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <Button
                    type="submit"
                    :disabled="proposal.processing || !proposal.reason_code"
                    >Send for approval</Button
                >
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
</template>
