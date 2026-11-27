<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { PageFrame } from '@/components/ui/page-frame';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';

type MergeRequestRow = {
    publicId: string;
    kind: 'merge' | 'unmerge';
    status: 'proposed' | 'approved' | 'rejected' | 'withdrawn';
    keptPatientNumber: string | null;
    keptName: string | null;
    mergedPatientNumber: string | null;
    mergedName: string | null;
    reason: string;
    proposedBy: string | null;
    proposedAt: string;
    decidedBy: string | null;
    decidedAt: string | null;
    lockVersion: number;
    canDecide: boolean;
    canWithdraw: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Patients', href: '/patients' },
            { title: 'Merge requests' },
        ],
    },
});
defineProps<{
    requests: { open: MergeRequestRow[]; recent: MergeRequestRow[] };
}>();

const branchId = (
    usePage().props.branchContext as { active?: { id: number } } | undefined
)?.active?.id;
const formatTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('en-MY', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'Asia/Kuala_Lumpur',
          }).format(new Date(value))
        : '';
const statusLabel: Record<MergeRequestRow['status'], string> = {
    proposed: 'Waiting',
    approved: 'Approved',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
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
        { expected_branch_id: branchId, lock_version: request.lockVersion },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Merge requests" />
    <PageFrame>
        <PageHeader
            title="Merge requests"
            description="Duplicate patient merges and unmerges waiting for a second person. Nothing changes until a different CA Supervisor or Director approves."
        />

        <section
            class="overflow-hidden rounded-[14px] border bg-card shadow-card"
        >
            <div class="border-b bg-card-quiet px-4 py-3 sm:px-5">
                <h2 class="text-[15px] font-semibold">Waiting for approval</h2>
            </div>
            <p
                v-if="!requests.open.length"
                class="p-5 text-[13px] text-muted-foreground"
            >
                No requests are waiting.
            </p>
            <div
                v-for="request in requests.open"
                :key="request.publicId"
                class="flex flex-col gap-3 border-b px-4 py-3.5 text-sm last:border-b-0 sm:px-5 md:flex-row md:items-center md:justify-between"
                data-testid="open-merge-request"
            >
                <div class="min-w-0 space-y-1 break-words">
                    <div>
                        <span class="font-medium">{{
                            request.kind === 'merge' ? 'Merge' : 'Unmerge'
                        }}</span>
                        {{ ' ' }}
                        <Link
                            v-if="request.mergedPatientNumber"
                            class="font-mono text-link underline"
                            :href="
                                '/patients/' +
                                encodeURIComponent(request.mergedPatientNumber)
                            "
                            >{{ request.mergedPatientNumber }}</Link
                        >
                        ({{ request.mergedName }})
                        {{ request.kind === 'merge' ? 'into' : 'from' }}
                        <Link
                            v-if="request.keptPatientNumber"
                            class="font-mono text-link underline"
                            :href="
                                '/patients/' +
                                encodeURIComponent(request.keptPatientNumber)
                            "
                            >{{ request.keptPatientNumber }}</Link
                        >
                        ({{ request.keptName }})
                    </div>
                    <div class="text-muted-foreground">
                        {{ request.reason }} · proposed by
                        {{ request.proposedBy }} ·
                        {{ formatTime(request.proposedAt) }}
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <template v-if="request.canDecide">
                        <Button
                            class="max-lg:min-h-11"
                            @click="decide(request, 'approve')"
                            >Approve</Button
                        >
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            @click="decide(request, 'reject')"
                            >Reject</Button
                        >
                    </template>
                    <Button
                        v-if="request.canWithdraw"
                        variant="outline"
                        class="max-lg:min-h-11"
                        @click="decide(request, 'withdraw')"
                        >Withdraw</Button
                    >
                    <span
                        v-if="!request.canDecide && !request.canWithdraw"
                        class="text-[12.5px] text-muted-foreground"
                        >Waiting for a different CA Supervisor or
                        Director.</span
                    >
                </div>
            </div>
        </section>

        <section
            class="overflow-hidden rounded-[14px] border bg-card shadow-card"
        >
            <div class="border-b bg-card-quiet px-4 py-3 sm:px-5">
                <h2 class="text-[15px] font-semibold">Recent decisions</h2>
            </div>
            <p
                v-if="!requests.recent.length"
                class="p-5 text-[13px] text-muted-foreground"
            >
                No decisions yet.
            </p>
            <div
                v-for="request in requests.recent"
                :key="request.publicId"
                class="flex flex-col gap-1 border-b px-4 py-3.5 text-sm last:border-b-0 sm:px-5 md:flex-row md:justify-between"
            >
                <div class="min-w-0 break-words">
                    <span class="font-medium">{{
                        request.kind === 'merge' ? 'Merge' : 'Unmerge'
                    }}</span>
                    {{ ' ' }}
                    <span class="font-mono">
                        {{ request.mergedPatientNumber }}</span
                    >
                    {{ request.kind === 'merge' ? 'into' : 'from' }}
                    <span class="font-mono">{{
                        request.keptPatientNumber
                    }}</span>
                    · {{ request.reason }}
                </div>
                <div
                    class="flex flex-wrap items-center gap-2 text-[13px] text-muted-foreground"
                >
                    <StatusBadge
                        :status="request.status"
                        :label="statusLabel[request.status]"
                        :tone="
                            request.status === 'approved'
                                ? 'success'
                                : request.status === 'rejected'
                                  ? 'danger'
                                  : 'neutral'
                        "
                    />
                    by {{ request.decidedBy }} ·
                    {{ formatTime(request.decidedAt) }}
                </div>
            </div>
        </section>
    </PageFrame>
</template>
