<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { plainValue } from '@/lib/agent-plain';
import { formatDate, formatDateTime } from '@/lib/presentation';

/**
 * Version 2 Approval inbox (shown only in V2; Classic is `pages/Agents/Inbox.vue`, untouched). Same props, same routes
 * (`/agents/inbox`, `/agents/inbox/{id}/approve|reject`), same permissions, same lock versions and payloads.
 */
type Tab = 'pending' | 'approved' | 'rejected';

type ProposalRow = {
    id: number;
    type: string;
    typeLabel: string;
    agent: string | null;
    agentTitle: string | null;
    status: Tab | 'withdrawn' | 'expired';
    createdAt: string | null;
    decidedAt: string | null;
    decidedBy: string | null;
    reason: string | null;
    lockVersion: number;
    lines: { label: string; value: string }[];
    canDecide: boolean;
};

const props = defineProps<{
    branch: { code: string; label: string };
    tab: Tab;
    counts: Record<Tab, number>;
    proposals: ProposalRow[];
    pagination: { page: number; lastPage: number; total: number };
}>();

const PER_PAGE = 20;
const tabs = computed(() => [
    { value: 'pending', label: 'Pending', count: props.counts.pending },
    { value: 'approved', label: 'Approved', count: props.counts.approved },
    { value: 'rejected', label: 'Rejected', count: props.counts.rejected },
]);
const emptyText: Record<Tab, string> = {
    pending:
        'Pending holds suggestions waiting for your decision. Nothing is waiting now.',
    approved:
        'Approved holds suggestions you have said yes to. None have been approved yet.',
    rejected:
        'Rejected holds suggestions you have said no to, with the reason. None have been rejected yet.',
};
const statusChip = (status: ProposalRow['status']) =>
    ({
        pending: { tone: 'warning', label: 'Pending' },
        approved: { tone: 'success', label: 'Approved' },
        rejected: { tone: 'danger', label: 'Rejected' },
        withdrawn: { tone: 'neutral', label: 'Withdrawn' },
        expired: { tone: 'neutral', label: 'Expired' },
    })[status] as {
        tone: 'neutral' | 'success' | 'warning' | 'danger';
        label: string;
    };

const deciding = ref<{
    proposal: ProposalRow;
    action: 'approve' | 'reject';
} | null>(null);
const processing = ref(false);
const error = ref('');
const open = (proposal: ProposalRow, action: 'approve' | 'reject') => {
    deciding.value = { proposal, action };
    error.value = '';
};
const dialogOpen = computed({
    get: () => deciding.value !== null,
    set: (value: boolean) => {
        if (!value) {
            deciding.value = null;
        }
    },
});
const decisionTitle = computed(() => {
    const kind = deciding.value?.proposal.typeLabel.toLowerCase() ?? '';

    return deciding.value?.action === 'reject'
        ? `Reject this ${kind}?`
        : `Approve this ${kind}?`;
});
const submit = (reason: string | null) => {
    const current = deciding.value;

    if (!current) {
        return;
    }

    processing.value = true;
    router.post(
        `/agents/inbox/${current.proposal.id}/${current.action}?tab=${props.tab}&page=${props.pagination.page}`,
        { lock_version: current.proposal.lockVersion, reason },
        {
            preserveScroll: true,
            onSuccess: () => {
                deciding.value = null;
            },
            onError: (errors) => {
                const fresh = props.proposals.find(
                    (row) => row.id === current.proposal.id,
                );

                if (deciding.value && fresh) {
                    deciding.value = { ...deciding.value, proposal: fresh };
                }

                error.value =
                    errors.reason ??
                    errors.lock_version ??
                    errors.status ??
                    'This decision was not saved.';
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
const selectTab = (value: string) =>
    router.get(
        `/agents/inbox?tab=${value}`,
        {},
        { preserveScroll: true, preserveState: false },
    );
const changePage = (page: number) =>
    router.get(`/agents/inbox?tab=${props.tab}&page=${page}`);
const decidedLine = (proposal: ProposalRow) =>
    `${proposal.status === 'approved' ? 'Approved' : proposal.status === 'rejected' ? 'Rejected' : 'Closed'} by ${proposal.decidedBy ?? 'a staff member'} · ${formatDateTime(proposal.decidedAt)}`;
const lineValue = (proposal: ProposalRow, label: string) =>
    proposal.lines.find((line) => line.label === label)?.value ?? null;
const headline = (proposal: ProposalRow) => lineValue(proposal, 'Item');
const place = (proposal: ProposalRow) => lineValue(proposal, 'Location');
const detailLines = (proposal: ProposalRow) =>
    proposal.lines.filter(
        (line) => line.label !== 'Item' && line.label !== 'Location',
    );
// Staff-readable values: an ISO date reads "20 Jan 2027", a code reads as a name.
const shown = (value: string) =>
    /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value) : plainValue(value);
const outline =
    'inline-flex h-11 flex-1 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background sm:flex-none lg:h-9';
</script>

<template>
    <Head title="Approval inbox" />
    <PageFrame>
        <V2WorkLayout
            title="Approval inbox"
            :description="`Suggestions from AI agents for ${branch.label}.`"
        >
            <template #main>
                <p
                    class="text-[15px] leading-[22px] text-muted-foreground"
                    data-test="inbox-notice"
                >
                    AI agents suggest; you decide. Approving records the
                    decision only: nothing in stock changes. Act on an approved
                    suggestion from the Inventory screens.
                </p>

                <V2Tabs
                    :model-value="tab"
                    :tabs="tabs"
                    aria-label="Suggestion status"
                    data-test="inbox-tabs"
                    @update:model-value="selectTab"
                />

                <div
                    role="tabpanel"
                    :aria-label="`${tabs.find((t) => t.value === tab)?.label} suggestions`"
                    class="flex min-w-0 flex-col gap-3"
                >
                    <p
                        v-if="proposals.length === 0"
                        class="rounded-[14px] border border-dashed border-border p-5 text-[15px] leading-[22px] text-muted-foreground"
                        data-test="inbox-empty"
                    >
                        {{ emptyText[tab] }}
                    </p>
                    <ul v-else class="flex flex-col gap-3">
                        <li
                            v-for="proposal in proposals"
                            :key="proposal.id"
                            class="flex min-w-0 flex-col gap-1.5 rounded-[14px] border border-border bg-card p-3"
                            :data-test="`proposal-${proposal.id}`"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-base font-semibold">{{
                                    proposal.typeLabel
                                }}</span>
                                <V2Chip
                                    :tone="statusChip(proposal.status).tone"
                                    :label="statusChip(proposal.status).label"
                                />
                            </div>
                            <p
                                class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] leading-[18px] text-muted-foreground"
                            >
                                <V2Chip tone="info" label="AI agent" />
                                <span
                                    >{{ proposal.agent ?? 'An AI agent' }} ·
                                    {{ formatDateTime(proposal.createdAt) }}</span
                                >
                            </p>
                            <p
                                v-if="headline(proposal)"
                                class="text-sm leading-5 font-medium break-words"
                            >
                                {{ headline(proposal) }}
                                <span
                                    v-if="place(proposal)"
                                    class="block font-normal text-muted-foreground"
                                    >{{ place(proposal) }}</span
                                >
                            </p>
                            <dl
                                class="grid max-w-md grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-0.5 text-sm"
                            >
                                <template
                                    v-for="line in detailLines(proposal)"
                                    :key="line.label"
                                >
                                    <dt class="text-muted-foreground">
                                        {{ line.label }}
                                    </dt>
                                    <dd class="min-w-0 text-right break-words">
                                        {{ shown(line.value) }}
                                    </dd>
                                </template>
                            </dl>
                            <p
                                v-if="proposal.decidedAt"
                                class="text-[13px] leading-[18px] text-muted-foreground"
                            >
                                {{ decidedLine(proposal) }}
                                <template v-if="proposal.reason">
                                    · Reason: {{ proposal.reason }}
                                </template>
                            </p>
                            <div
                                v-if="proposal.canDecide"
                                class="flex gap-2"
                            >
                                <button
                                    type="button"
                                    :class="outline"
                                    :data-test="`approve-${proposal.id}`"
                                    @click="open(proposal, 'approve')"
                                >
                                    Approve
                                </button>
                                <button
                                    type="button"
                                    :class="outline"
                                    :data-test="`reject-${proposal.id}`"
                                    @click="open(proposal, 'reject')"
                                >
                                    Reject
                                </button>
                            </div>
                        </li>
                    </ul>

                    <V2Pager
                        v-if="pagination.lastPage > 1"
                        :current-page="pagination.page"
                        :last-page="pagination.lastPage"
                        :total="pagination.total"
                        :per-page="PER_PAGE"
                        @change="changePage"
                    />
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        v-model:open="dialogOpen"
        :title="decisionTitle"
        :description="
            deciding?.action === 'reject'
                ? 'Say briefly why. Do not enter patient details.'
                : 'Approving records your decision only. Nothing in stock changes.'
        "
        :confirm-label="deciding?.action === 'reject' ? 'Reject' : 'Approve'"
        :tone="deciding?.action === 'reject' ? 'danger' : 'default'"
        :reason-label="
            deciding?.action === 'reject' ? 'Reason for rejecting' : undefined
        "
        :busy="processing"
        @confirm="submit"
    >
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
    </V2Dialog>
</template>
