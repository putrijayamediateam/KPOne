<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatDateTime } from '@/lib/presentation';

/**
 * Version 2 Patient merges list (shown only in V2; Classic is `pages/Patient/MergeRequests.vue`, untouched). Same prop,
 * same `POST /patient-merges/{id}/approve|reject|withdraw` with `expected_branch_id` and `lock_version`, same
 * `canDecide` and `canWithdraw` flags. Every confirm is a V2Dialog instead of the browser's confirm(). No backend change.
 */
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

defineProps<{
    requests: { open: MergeRequestRow[]; recent: MergeRequestRow[] };
}>();

usePageCrumbs(() => [
    { title: 'Patients', href: '/patients' },
    { title: 'Merge requests' },
]);

const branchId = (
    usePage().props.branchContext as { active?: { id: number } } | undefined
)?.active?.id;
const nb = (kp: string | null | undefined) => (kp ?? '').replaceAll('-', '‑');
const when = (value: string | null) =>
    value ? formatDateTime(value) : 'No date';
const patientUrl = (kp: string) => '/patients/' + encodeURIComponent(kp);
const statusTone = {
    proposed: 'warning',
    approved: 'success',
    rejected: 'danger',
    withdrawn: 'neutral',
} as const;
const statusLabel: Record<MergeRequestRow['status'], string> = {
    proposed: 'Waiting',
    approved: 'Approved',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
};

type Action = 'approve' | 'reject' | 'withdraw';
const confirming = ref<{ request: MergeRequestRow; action: Action } | null>(
    null,
);
const acting = ref(false);
const dialogError = ref('');

const dialog = computed(() => {
    const current = confirming.value;

    if (!current) {
        return null;
    }

    const { request, action } = current;
    const merged = nb(request.mergedPatientNumber);
    const kept = nb(request.keptPatientNumber);
    const both = `${merged} (${request.mergedName}) and ${kept} (${request.keptName})`;

    if (action === 'approve') {
        return {
            title:
                request.kind === 'merge'
                    ? `Approve merging ${merged} into ${kept}?`
                    : `Approve unmerging ${merged} from ${kept}?`,
            description:
                request.kind === 'merge'
                    ? `${both}. The two records are linked. Nothing is moved or deleted. You can undo this later with another approval.`
                    : `${both}. The link between the two records is removed. Nothing is moved or deleted.`,
            confirmLabel:
                request.kind === 'merge' ? 'Approve merge' : 'Approve unmerge',
            tone: 'default' as const,
        };
    }

    return action === 'reject'
        ? {
              title: 'Reject this request?',
              description: `${both}. Nothing changes. The person who proposed it can ask again.`,
              confirmLabel: 'Reject request',
              tone: 'danger' as const,
          }
        : {
              title: 'Withdraw your request?',
              description: `${both}. Nothing changes.`,
              confirmLabel: 'Withdraw request',
              tone: 'danger' as const,
          };
});

const ask = (request: MergeRequestRow, action: Action) => {
    dialogError.value = '';
    confirming.value = { request, action };
};
const confirmDialog = () => {
    const current = confirming.value;

    if (!current || acting.value) {
        return;
    }

    router.post(
        `/patient-merges/${current.request.publicId}/${current.action}`,
        {
            expected_branch_id: branchId,
            lock_version: current.request.lockVersion,
        },
        {
            preserveScroll: true,
            onStart: () => {
                acting.value = true;
                dialogError.value = '';
            },
            onSuccess: () => {
                confirming.value = null;
            },
            onError: (errors) => {
                dialogError.value =
                    Object.values(errors)[0] ??
                    'This request has changed. Close this and review the current list.';
            },
            onFinish: () => {
                acting.value = false;
            },
        },
    );
};

const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9';
const approveButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-primary bg-card px-4 text-sm font-medium text-primary outline-none hover:bg-primary/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9';
const linkClass =
    'font-mono underline decoration-muted-foreground/60 underline-offset-2 hover:decoration-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <Head title="Merge requests" />
    <PageFrame>
        <V2WorkLayout
            title="Merge requests"
            description="Duplicate patient merges and unmerges waiting for a second person. Nothing changes until a different CA Supervisor or Director approves."
        >
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <V2FormCard
                        :title="`Waiting for approval (${requests.open.length})`"
                    >
                        <p
                            v-if="!requests.open.length"
                            class="text-sm text-muted-foreground"
                        >
                            No requests are waiting.
                        </p>
                        <ul v-else class="flex min-w-0 flex-col gap-3">
                            <li
                                v-for="request in requests.open"
                                :key="request.publicId"
                                class="flex min-w-0 flex-col gap-3 rounded-[10px] border p-4 text-sm"
                                data-testid="open-merge-request"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <V2Chip
                                        plain
                                        tone="neutral"
                                        :label="
                                            request.kind === 'merge'
                                                ? 'Merge'
                                                : 'Unmerge'
                                        "
                                    />
                                    <V2Chip
                                        tone="warning"
                                        :label="statusLabel[request.status]"
                                    />
                                </div>
                                <p class="min-w-0 break-words">
                                    <Link
                                        v-if="request.mergedPatientNumber"
                                        :href="
                                            patientUrl(
                                                request.mergedPatientNumber,
                                            )
                                        "
                                        :class="linkClass"
                                        >{{
                                            nb(request.mergedPatientNumber)
                                        }}</Link
                                    >
                                    ({{ request.mergedName }})
                                    {{
                                        request.kind === 'merge'
                                            ? 'will be merged into'
                                            : 'will be unmerged from'
                                    }}
                                    <Link
                                        v-if="request.keptPatientNumber"
                                        :href="
                                            patientUrl(
                                                request.keptPatientNumber,
                                            )
                                        "
                                        :class="linkClass"
                                        >{{
                                            nb(request.keptPatientNumber)
                                        }}</Link
                                    >
                                    ({{ request.keptName }})
                                </p>
                                <p class="text-[13px] text-muted-foreground">
                                    {{ request.reason }} · proposed by
                                    {{ request.proposedBy }} ·
                                    {{ when(request.proposedAt) }}
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <template v-if="request.canDecide">
                                        <button
                                            type="button"
                                            :class="approveButton"
                                            @click="ask(request, 'approve')"
                                        >
                                            Approve
                                        </button>
                                        <button
                                            type="button"
                                            :class="outlineButton"
                                            @click="ask(request, 'reject')"
                                        >
                                            Reject
                                        </button>
                                    </template>
                                    <button
                                        v-if="request.canWithdraw"
                                        type="button"
                                        :class="outlineButton"
                                        @click="ask(request, 'withdraw')"
                                    >
                                        Withdraw
                                    </button>
                                    <p
                                        v-if="
                                            !request.canDecide &&
                                            !request.canWithdraw
                                        "
                                        class="text-[13px] text-muted-foreground"
                                    >
                                        Waiting for a different CA Supervisor or
                                        Director.
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </V2FormCard>

                    <V2FormCard title="Recent decisions">
                        <V2Table
                            label="Recent merge decisions"
                            :columns="4"
                            cards="xl"
                            min-width="760px"
                            :empty="requests.recent.length === 0"
                            empty-message="No decisions yet."
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Request</th>
                                    <th scope="col">Reason</th>
                                    <th scope="col">Decision</th>
                                    <th scope="col">Decided</th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="request in requests.recent"
                                    :key="request.publicId"
                                >
                                    <td data-label="Request" data-wrap>
                                        {{
                                            request.kind === 'merge'
                                                ? 'Merge'
                                                : 'Unmerge'
                                        }}
                                        <span class="font-mono">{{
                                            nb(request.mergedPatientNumber)
                                        }}</span>
                                        {{
                                            request.kind === 'merge'
                                                ? 'into'
                                                : 'from'
                                        }}
                                        <span class="font-mono">{{
                                            nb(request.keptPatientNumber)
                                        }}</span>
                                    </td>
                                    <td data-label="Reason" data-wrap>
                                        {{ request.reason }}
                                    </td>
                                    <td data-label="Decision">
                                        <V2Chip
                                            :tone="statusTone[request.status]"
                                            :label="statusLabel[request.status]"
                                        />
                                    </td>
                                    <td data-label="Decided" data-wrap>
                                        {{ request.decidedBy }} ·
                                        {{ when(request.decidedAt) }}
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
                    </V2FormCard>
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            v-if="dialog"
            :open="true"
            :title="dialog.title"
            :description="dialog.description"
            :confirm-label="dialog.confirmLabel"
            :tone="dialog.tone"
            :busy="acting"
            @update:open="(open: boolean) => !open && (confirming = null)"
            @confirm="confirmDialog"
        >
            <p v-if="dialogError" role="alert" class="text-sm text-destructive">
                {{ dialogError }}
            </p>
        </V2Dialog>
    </PageFrame>
</template>
