<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/presentation';

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
    // Approval that can also create the stock request or purchase request (null when approval is a decision only).
    prepare?: PrepareOffer | null;
    prepared?: { kind: string; label: string; text: string } | null;
};

type Location = { id: string; name: string };
type PrepareOffer = {
    canPrepare: boolean;
    cannotPrepareReason: string | null;
    actionLabel: string;
    recordLabel: string;
    warning: string | null;
    edits: {
        maxQuantity: string;
        source: string;
        destination: string;
        sourceLocations: Location[];
        destinationLocations: Location[];
    } | null;
};

const props = defineProps<{
    branch: { code: string; label: string };
    tab: Tab;
    counts: Record<Tab, number>;
    proposals: ProposalRow[];
    pagination: { page: number; lastPage: number; total: number };
}>();

const tabs: { key: Tab; label: string }[] = [
    { key: 'pending', label: 'Pending' },
    { key: 'approved', label: 'Approved' },
    { key: 'rejected', label: 'Rejected' },
];
const emptyText: Record<Tab, string> = {
    pending: 'Nothing is waiting for a decision.',
    approved: 'No approved proposals yet.',
    rejected: 'No rejected proposals yet.',
};

const deciding = ref<{
    proposal: ProposalRow;
    action: 'approve' | 'reject';
} | null>(null);
const reason = ref('');
const processing = ref(false);
const error = ref('');
const needsReason = computed(() => deciding.value?.action === 'reject');
// Approving can also create the stock request or purchase request (only when the server offers it). The server checks
// every value again.
const mode = ref<'prepare' | 'decision_only'>('decision_only');
const editQuantity = ref('');
const editSource = ref('');
const editDestination = ref('');
const plainQuantity = (value: string) =>
    value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
const open = (proposal: ProposalRow, action: 'approve' | 'reject') => {
    deciding.value = { proposal, action };
    reason.value = '';
    error.value = '';
    mode.value =
        action === 'approve' && proposal.prepare?.canPrepare
            ? 'prepare'
            : 'decision_only';
    editQuantity.value = plainQuantity(
        proposal.prepare?.edits?.maxQuantity ?? '',
    );
    editSource.value = proposal.prepare?.edits?.source ?? '';
    editDestination.value = proposal.prepare?.edits?.destination ?? '';
};
const offer = computed(() =>
    deciding.value?.action === 'approve'
        ? (deciding.value.proposal.prepare ?? null)
        : null,
);
const preparing = computed(
    () => offer.value !== null && mode.value === 'prepare',
);
const anyPrepare = computed(() =>
    props.proposals.some((row) => row.prepare != null),
);
const close = (value: boolean) => {
    if (!value) {
        deciding.value = null;
    }
};
const submit = () => {
    const current = deciding.value;

    if (!current) {
        return;
    }

    if (needsReason.value && reason.value.trim().length < 3) {
        error.value = 'Give a short reason for rejecting.';

        return;
    }

    const extra: Record<string, unknown> = {};

    if (offer.value) {
        extra.mode = mode.value;

        if (mode.value === 'prepare' && offer.value.edits) {
            const wanted = Number(editQuantity.value);

            if (
                !/^\d{1,12}(\.\d{1,3})?$/.test(editQuantity.value.trim()) ||
                wanted <= 0 ||
                wanted > Number(offer.value.edits.maxQuantity)
            ) {
                error.value = `Enter a quantity above 0, up to ${plainQuantity(offer.value.edits.maxQuantity)}. You can only lower it.`;

                return;
            }

            extra.edits = {
                quantity: editQuantity.value.trim(),
                source_location_public_id: editSource.value,
                destination_location_public_id: editDestination.value,
            };
        }
    }

    processing.value = true;
    router.post(
        `/agents/inbox/${current.proposal.id}/${current.action}?tab=${props.tab}&page=${props.pagination.page}`,
        {
            lock_version: current.proposal.lockVersion,
            reason: reason.value.trim() || null,
            ...extra,
        },
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
                    errors.mode ??
                    errors.quantity ??
                    errors.edits ??
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
const pageLink = (page: number) =>
    `/agents/inbox?tab=${props.tab}&page=${page}`;
</script>

<template>
    <Head title="Approval inbox" />
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Approval inbox"
            :description="`Suggestions from AI agents for ${branch.label}.`"
        />
        <p
            v-if="!anyPrepare"
            class="text-sm text-muted-foreground"
            data-test="inbox-notice"
        >
            AI agents suggest; you decide. Approving records your decision only:
            nothing in stock changes. Act on an approved suggestion from the
            Inventory screens.
        </p>
        <p
            v-else
            class="text-sm text-muted-foreground"
            data-test="inbox-notice"
        >
            AI agents suggest; you decide. Approving records your decision. If
            you choose, it also creates the stock request or purchase request
            for you, as you. Nothing is created unless you choose that.
        </p>

        <nav
            class="flex flex-wrap gap-2"
            aria-label="Proposal status"
            data-test="inbox-tabs"
        >
            <Link
                v-for="item in tabs"
                :key="item.key"
                :href="`/agents/inbox?tab=${item.key}`"
                preserve-scroll
                class="inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm"
                :class="
                    tab === item.key
                        ? 'border-brand bg-brand/10 font-semibold text-brand-strong'
                        : 'text-muted-foreground'
                "
                :aria-current="tab === item.key ? 'page' : undefined"
            >
                {{ item.label }}
                <span class="text-xs">{{ counts[item.key] }}</span>
            </Link>
        </nav>

        <div
            v-if="proposals.length === 0"
            class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
        >
            {{ emptyText[tab] }}
        </div>
        <ul v-else class="grid gap-3">
            <li
                v-for="proposal in proposals"
                :key="proposal.id"
                class="min-w-0 rounded-xl border bg-card p-4"
                :data-test="`proposal-${proposal.id}`"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ proposal.typeLabel }}</span>
                    <StatusBadge :status="proposal.status" />
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    Suggested by {{ proposal.agent ?? 'an AI agent' }}
                    <StatusBadge
                        status="ai_agent"
                        label="AI agent"
                        tone="accent"
                    />
                    · {{ formatDateTime(proposal.createdAt) }}
                </p>
                <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    <div
                        v-for="line in proposal.lines"
                        :key="line.label"
                        class="flex min-w-0 flex-wrap gap-1"
                    >
                        <dt class="text-muted-foreground">{{ line.label }}:</dt>
                        <dd class="min-w-0 break-words">{{ line.value }}</dd>
                    </div>
                </dl>
                <p
                    v-if="proposal.decidedAt"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    {{
                        proposal.status === 'approved' ? 'Approved' : 'Decided'
                    }}
                    by {{ proposal.decidedBy }} ·
                    {{ formatDateTime(proposal.decidedAt) }}
                    <template v-if="proposal.reason">
                        · Reason: {{ proposal.reason }}
                    </template>
                </p>
                <p
                    v-if="proposal.prepared"
                    class="mt-3 text-sm font-medium"
                    :data-test="`prepared-${proposal.id}`"
                >
                    {{ proposal.prepared.text }}
                </p>
                <div
                    v-if="proposal.canDecide"
                    class="mt-3 flex flex-wrap gap-2"
                >
                    <Button
                        size="sm"
                        :data-test="`approve-${proposal.id}`"
                        @click="open(proposal, 'approve')"
                    >
                        Approve
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        :data-test="`reject-${proposal.id}`"
                        @click="open(proposal, 'reject')"
                    >
                        Reject
                    </Button>
                </div>
            </li>
        </ul>

        <nav
            v-if="pagination.lastPage > 1"
            class="flex items-center justify-between text-sm"
            aria-label="Pages"
        >
            <Link
                v-if="pagination.page > 1"
                :href="pageLink(pagination.page - 1)"
                class="underline"
            >
                Previous
            </Link>
            <span class="text-muted-foreground">
                Page {{ pagination.page }} of {{ pagination.lastPage }}
            </span>
            <Link
                v-if="pagination.page < pagination.lastPage"
                :href="pageLink(pagination.page + 1)"
                class="underline"
            >
                Next
            </Link>
        </nav>

        <OperationalConfirmDialog
            :open="deciding !== null"
            :title="
                deciding?.action === 'reject'
                    ? `Reject this ${deciding?.proposal.typeLabel.toLowerCase()}?`
                    : `Approve this ${deciding?.proposal.typeLabel.toLowerCase()}?`
            "
            :description="
                deciding?.action === 'reject'
                    ? 'Say briefly why. Do not enter patient details.'
                    : preparing
                      ? `Approving records your decision and creates the ${offer?.recordLabel} as you. The ${offer?.recordLabel} still needs its own approval.`
                      : 'Approving records your decision only. Nothing in stock changes.'
            "
            :confirm-label="
                deciding?.action === 'reject'
                    ? 'Reject'
                    : preparing
                      ? (offer?.actionLabel ?? 'Approve')
                      : 'Approve'
            "
            :processing="processing"
            :destructive="deciding?.action === 'reject'"
            :error="error"
            @update:open="close"
            @confirm="submit"
        >
            <fieldset
                v-if="offer"
                class="mb-3 grid gap-2 text-sm"
                data-test="prepare-choice"
            >
                <legend class="mb-1 font-medium">
                    How do you want to approve it?
                </legend>
                <label class="flex items-center gap-2">
                    <input
                        v-model="mode"
                        type="radio"
                        name="mode"
                        value="prepare"
                        :disabled="!offer.canPrepare"
                    />
                    {{ offer.actionLabel }}
                </label>
                <label class="flex items-center gap-2">
                    <input
                        v-model="mode"
                        type="radio"
                        name="mode"
                        value="decision_only"
                    />
                    Approve (decision only)
                </label>
                <p
                    v-if="!offer.canPrepare && offer.cannotPrepareReason"
                    class="text-xs text-muted-foreground"
                    data-test="cannot-prepare"
                >
                    {{ offer.cannotPrepareReason }}
                </p>
                <template v-if="preparing">
                    <p
                        v-if="offer.warning"
                        class="rounded-md border border-warning/40 bg-warning/10 p-2 text-xs"
                        data-test="prepare-warning"
                    >
                        {{ offer.warning }}
                    </p>
                    <template v-if="offer.edits">
                        <label class="grid gap-1">
                            Quantity to send
                            <input
                                v-model="editQuantity"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                class="h-9 rounded-md border bg-background px-2"
                                data-test="edit-quantity"
                            />
                            <span class="text-xs text-muted-foreground">
                                Up to
                                {{ plainQuantity(offer.edits.maxQuantity) }}.
                                You can only lower it.
                            </span>
                        </label>
                        <label class="grid gap-1">
                            Sending location
                            <select
                                v-model="editSource"
                                class="h-9 rounded-md border bg-background px-2"
                            >
                                <option
                                    v-for="location in offer.edits
                                        .sourceLocations"
                                    :key="location.id"
                                    :value="location.id"
                                >
                                    {{ location.name }}
                                </option>
                            </select>
                        </label>
                        <label class="grid gap-1">
                            Receiving location
                            <select
                                v-model="editDestination"
                                class="h-9 rounded-md border bg-background px-2"
                            >
                                <option
                                    v-for="location in offer.edits
                                        .destinationLocations"
                                    :key="location.id"
                                    :value="location.id"
                                >
                                    {{ location.name }}
                                </option>
                            </select>
                        </label>
                    </template>
                </template>
            </fieldset>
            <label class="grid gap-1 text-sm">
                {{ needsReason ? 'Reason (required)' : 'Reason (optional)' }}
                <Textarea
                    v-model="reason"
                    maxlength="500"
                    rows="3"
                    data-test="decision-reason"
                />
            </label>
        </OperationalConfirmDialog>
    </main>
</template>
