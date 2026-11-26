<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { myr } from '@/types/billing';

type Proposal = {
    id: string;
    panelName: string;
    panelKey: string;
    chargeId: string;
    chargeName: string;
    oldAmountSen: number | null;
    newAmountSen: number;
    reference: string;
    effectiveLabel: string;
    effectivePassed: boolean;
    status: 'proposed' | 'approved' | 'rejected' | 'withdrawn';
    proposedBy: string | null;
    proposedAtLabel: string;
    decidedBy: string | null;
    decidedAtLabel: string | null;
    reasonLabel: string | null;
    appliedLabel: string | null;
    mine: boolean;
    lockVersion: number;
};

const props = defineProps<{
    branch: { id: number; name: string; timezone: string };
    canPropose: boolean;
    canApprove: boolean;
    panels: { id: string; name: string }[];
    charges: { id: string; name: string; code: string; type: string }[];
    selection: {
        hasPriceList: boolean;
        version: number;
        currentAmountSen: number | null;
        history: {
            version: number;
            amountSen: number;
            effectiveLabel: string;
            scheduled: boolean;
        }[];
        openProposal: boolean;
    } | null;
    waiting: Proposal[];
    waitingCount: number;
    decided: Proposal[];
    decidedPagination: { page: number; lastPage: number; total: number };
    rejectReasons: Record<string, string>;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const errorMessages = computed(() => [
    ...new Set(
        Object.values(errors.value).filter((message): message is string =>
            Boolean(message),
        ),
    ),
]);

type Tab = 'propose' | 'waiting' | 'decided';
const tab = ref<Tab>(props.canPropose ? 'propose' : 'waiting');
const tabs = computed(() => [
    ...(props.canPropose
        ? [{ id: 'propose' as Tab, label: 'Propose a change' }]
        : []),
    {
        id: 'waiting' as Tab,
        label: `Waiting for approval (${props.waitingCount})`,
    },
    { id: 'decided' as Tab, label: 'Decided' },
]);

const form = reactive({
    book: '',
    charge: '',
    price: '',
    reference: '',
    date: '',
    time: '00:00',
    busy: false,
    key: crypto.randomUUID(),
});

const loadSelection = () => {
    if (form.book === '' || form.charge === '') {
        return;
    }

    router.get(
        '/panel-price-changes',
        { panel: form.book, charge: form.charge },
        {
            only: ['selection'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const priceSen = computed<number | null>(() => {
    const text = form.price.trim();

    if (!/^\d+(\.\d{1,2})?$/.test(text)) {
        return null;
    }

    return Math.round(Number(text) * 100);
});

const change = computed(() => {
    const before = props.selection?.currentAmountSen ?? null;

    if (priceSen.value === null || before === null) {
        return null;
    }

    const diff = priceSen.value - before;
    const pct = before === 0 ? null : (diff / before) * 100;

    return { before, diff, pct, large: pct !== null && Math.abs(pct) > 25 };
});

const sign = (value: number) => (value >= 0 ? '+' : '−');

const referenceOk = computed(() => {
    const length = form.reference.trim().length;

    return length >= 3 && length <= 120;
});

const todayIso = computed(() =>
    new Intl.DateTimeFormat('en-CA', {
        timeZone: props.branch.timezone,
    }).format(new Date()),
);
const datePast = computed(() => form.date !== '' && form.date < todayIso.value);

const missing = computed(() => {
    if (form.book === '') {
        return 'Pick a Panel to continue.';
    }

    if (form.charge === '') {
        return 'Pick a charge to continue.';
    }

    if (props.selection?.openProposal) {
        return 'A change for this Panel and charge is already waiting for a decision.';
    }

    if (priceSen.value === null) {
        return 'Enter the new price, for example 14.50. Type 0.00 if it is free.';
    }

    if (!referenceOk.value) {
        return 'Enter the agreement reference, 3 to 120 characters.';
    }

    if (form.date === '') {
        return 'Pick the date the price starts.';
    }

    if (datePast.value) {
        return 'Choose today or a later date.';
    }

    return '';
});
const canSubmit = computed(() => missing.value === '' && !form.busy);

const propose = () => {
    if (!canSubmit.value || props.selection === null) {
        return;
    }

    form.busy = true;
    router.post(
        '/panel-price-changes',
        {
            expected_branch_id: props.branch.id,
            expected_version: props.selection.version,
            panel: form.book,
            charge: form.charge,
            amount_sen: priceSen.value,
            agreement_reference: form.reference,
            effective_date: form.date,
            effective_time: form.time || '00:00',
            idempotency_key: form.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                form.price = '';
                form.reference = '';
                form.date = '';
                form.time = '00:00';
                form.key = crypto.randomUUID();
                tab.value = props.canApprove ? 'waiting' : 'propose';
                loadSelection();
            },
            onFinish: () => {
                form.busy = false;
            },
        },
    );
};

const proposeAgain = (row: Proposal) => {
    form.book = row.panelKey;
    form.charge = row.chargeId;
    tab.value = 'propose';
    loadSelection();
};

const stale = computed(() =>
    errorMessages.value.some((m) => m.startsWith('The price changed')),
);

// One open action per row: approve, reject or withdraw.
const open = ref<{
    id: string;
    kind: 'approve' | 'reject' | 'withdraw';
} | null>(null);
const rejectReason = ref('');
const rejectNote = ref('');
const acting = ref(false);

const begin = (row: Proposal, kind: 'approve' | 'reject' | 'withdraw') => {
    open.value = { id: row.id, kind };
    rejectReason.value = '';
    rejectNote.value = '';
    nextTick(() => {
        const first = document.getElementById(
            kind === 'reject' ? `reason-${row.id}` : `confirm-${row.id}`,
        );

        first?.closest('form')?.scrollIntoView({ block: 'center' });
        first?.focus();
    });
};

const cancel = (row: Proposal) => {
    const kind = open.value?.kind;

    open.value = null;
    nextTick(() => document.getElementById(`start-${row.id}-${kind}`)?.focus());
};

const act = (row: Proposal) => {
    if (open.value === null || acting.value) {
        return;
    }

    const kind = open.value.kind;

    if (kind === 'reject' && rejectReason.value === '') {
        return;
    }

    acting.value = true;
    router.post(
        `/panel-price-changes/${row.id}/${kind}`,
        {
            expected_branch_id: props.branch.id,
            lock_version: row.lockVersion,
            ...(kind === 'reject'
                ? { reason_code: rejectReason.value, note: rejectNote.value }
                : {}),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = null;
            },
            onFinish: () => {
                acting.value = false;
            },
        },
    );
};

// PNL-30: approve the ticked waiting proposals in one press. Each is checked on its own on the server.
const selected = ref<string[]>([]);
const bulkOpen = ref(false);
const bulkActing = ref(false);
const selectable = computed(() => props.waiting.filter((row) => !row.mine));
const selectedRows = computed(() =>
    selectable.value.filter((row) => selected.value.includes(row.id)),
);
const allSelected = computed(
    () =>
        selectable.value.length > 0 &&
        selectedRows.value.length === selectable.value.length,
);
const toggleAll = () => {
    selected.value = allSelected.value ? [] : selectable.value.map((r) => r.id);
    bulkOpen.value = false;
};
const toggleRow = (row: Proposal) => {
    selected.value = selected.value.includes(row.id)
        ? selected.value.filter((id) => id !== row.id)
        : [...selected.value, row.id];
    bulkOpen.value = false;
};
const approveSelected = () => {
    if (bulkActing.value || selectedRows.value.length === 0) {
        return;
    }

    bulkActing.value = true;
    router.post(
        '/panel-price-changes/approve-selected',
        {
            expected_branch_id: props.branch.id,
            items: selectedRows.value.map((row) => ({
                id: row.id,
                lock_version: row.lockVersion,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = [];
                bulkOpen.value = false;
            },
            onError: (errors) => {
                if (errors.bulk) {
                    selected.value = [];
                    bulkOpen.value = false;
                }
            },
            onFinish: () => {
                bulkActing.value = false;
            },
        },
    );
};

const changeOf = (row: Proposal) => {
    if (row.oldAmountSen === null) {
        return null;
    }

    const diff = row.newAmountSen - row.oldAmountSen;
    const pct = row.oldAmountSen === 0 ? null : (diff / row.oldAmountSen) * 100;

    return {
        diff,
        pct,
        large: pct !== null && Math.abs(pct) > 25,
    };
};
const pctText = (value: number) =>
    Math.abs(value) >= 1000
        ? Math.round(Math.abs(value)).toLocaleString('en-MY')
        : Math.abs(value).toFixed(1);

const statusWord = (status: Proposal['status']) =>
    ({
        proposed: 'Waiting',
        approved: 'Approved',
        rejected: 'Rejected',
        withdrawn: 'Withdrawn',
    })[status];
const statusDot = (status: Proposal['status']) =>
    ({
        proposed: 'bg-warning',
        approved: 'bg-success',
        rejected: 'bg-destructive',
        withdrawn: 'bg-muted-foreground',
    })[status];

const turnPage = (value: number) =>
    router.get(
        '/panel-price-changes',
        value > 1 ? { decided_page: value } : {},
        { preserveScroll: true, replace: true },
    );
</script>

<template>
    <Head title="Panel price changes" />
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Panel price changes"
            :description="`${branch.name} · A Panel Officer proposes a Panel price. Someone else approves it before it applies.`"
        />
        <p class="text-sm text-muted-foreground">
            A new price applies to bills built on or after its start time. Bills
            already built keep the price they were built with. Self-pay prices
            are not changed here.
        </p>
        <p
            v-for="message in errorMessages"
            :key="message"
            :class="
                message.startsWith('The price changed')
                    ? 'border-warning/40 bg-warning/10 text-foreground'
                    : 'border-destructive/40 bg-destructive/10 text-destructive'
            "
            class="rounded-md border p-3 text-sm"
            role="alert"
        >
            {{ message }}
        </p>

        <div class="flex flex-wrap gap-1 border-b" role="tablist">
            <button
                v-for="item in tabs"
                :key="item.id"
                type="button"
                role="tab"
                :aria-selected="tab === item.id"
                class="min-h-11 border-b-2 px-3 text-sm font-medium"
                :class="
                    tab === item.id
                        ? 'border-brand text-brand-strong'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="tab = item.id"
            >
                {{ item.label }}
            </button>
        </div>

        <form
            v-if="tab === 'propose' && canPropose"
            class="grid max-w-2xl gap-4"
            @submit.prevent="propose"
        >
            <label class="grid gap-1 text-sm">
                Panel *
                <select
                    v-model="form.book"
                    class="h-11 w-full rounded-md border bg-background px-2 text-sm lg:h-9"
                    @change="loadSelection"
                >
                    <option value="">Choose a Panel</option>
                    <option v-for="b in panels" :key="b.id" :value="b.id">
                        {{ b.name }}
                    </option>
                </select>
            </label>
            <p
                v-if="selection && !selection.hasPriceList"
                class="-mt-2 text-xs text-muted-foreground"
                data-testid="no-price-list"
            >
                This Panel has no price list yet. It is created when someone
                else approves this.
            </p>
            <label class="grid gap-1 text-sm">
                Charge *
                <select
                    v-model="form.charge"
                    class="h-11 w-full rounded-md border bg-background px-2 text-sm lg:h-9"
                    @change="loadSelection"
                >
                    <option value="">Choose a charge</option>
                    <option v-for="c in charges" :key="c.id" :value="c.id">
                        {{ c.name }} ({{ c.code }})
                    </option>
                </select>
            </label>

            <section
                v-if="selection"
                aria-label="Current Panel price"
                class="rounded-lg border p-3 text-sm"
            >
                <p class="font-medium">
                    Current price:
                    <span class="tabular-nums">{{
                        selection.currentAmountSen === null
                            ? 'No price yet'
                            : myr(selection.currentAmountSen)
                    }}</span>
                </p>
                <ul
                    v-if="selection.history.length"
                    class="mt-2 space-y-1 text-xs text-muted-foreground"
                >
                    <li
                        v-for="h in selection.history"
                        :key="h.version"
                        class="flex justify-between gap-2"
                    >
                        <span
                            >Version {{ h.version }} · from {{ h.effectiveLabel
                            }}<template v-if="h.scheduled">
                                (scheduled)</template
                            ></span
                        >
                        <span class="tabular-nums">{{ myr(h.amountSen) }}</span>
                    </li>
                </ul>
            </section>

            <label class="grid gap-1 text-sm">
                New price (RM) *
                <input
                    v-model="form.price"
                    inputmode="decimal"
                    autocomplete="off"
                    class="h-11 w-full rounded-md border bg-background px-3 text-sm tabular-nums lg:h-9"
                    :aria-invalid="errors.amount_sen ? 'true' : undefined"
                />
                <span
                    v-if="errors.amount_sen"
                    class="text-xs text-destructive"
                    >{{ errors.amount_sen }}</span
                >
            </label>
            <p
                v-if="change"
                class="text-sm tabular-nums"
                data-testid="price-change-line"
            >
                {{ myr(change.before) }} → {{ myr(priceSen ?? 0) }} ({{
                    sign(change.diff)
                }}{{ myr(Math.abs(change.diff))
                }}<template v-if="change.pct !== null"
                    >, {{ sign(change.diff)
                    }}{{ Math.abs(change.pct).toFixed(1) }}%</template
                >)
            </p>
            <p
                v-if="change?.large"
                class="flex items-center gap-2 text-sm text-warning"
            >
                <span
                    class="size-2 rounded-full bg-warning"
                    aria-hidden="true"
                />
                Large change. Check the agreement reference.
            </p>

            <label class="grid gap-1 text-sm">
                Agreement reference *
                <input
                    v-model="form.reference"
                    maxlength="120"
                    autocomplete="off"
                    class="h-11 w-full rounded-md border bg-background px-3 text-sm lg:h-9"
                    :aria-invalid="
                        errors.agreement_reference ? 'true' : undefined
                    "
                />
                <span
                    :class="
                        errors.agreement_reference
                            ? 'text-xs text-destructive'
                            : 'text-xs text-muted-foreground'
                    "
                    >{{
                        errors.agreement_reference ||
                        'Plain text, 3 to 120 characters. Do not enter patient details.'
                    }}</span
                >
            </label>

            <div class="grid items-start gap-3 sm:grid-cols-2">
                <div class="grid gap-1 text-sm">
                    <label for="price-start-date">Starts on *</label>
                    <DateField id="price-start-date" v-model="form.date" />
                    <span class="text-xs text-muted-foreground"
                        >{{ branch.name }} time ({{ branch.timezone }})</span
                    >
                    <span v-if="datePast" class="text-xs text-destructive">{{
                        'Choose today or a later date.'
                    }}</span>
                    <span
                        v-else-if="errors.effective_date"
                        class="text-xs text-destructive"
                        >{{ errors.effective_date }}</span
                    >
                </div>
                <label class="grid content-start gap-1 text-sm">
                    Time
                    <input
                        v-model="form.time"
                        type="time"
                        class="h-11 w-full rounded-md border bg-background px-3 text-sm lg:h-9"
                    />
                </label>
            </div>
            <p class="text-xs text-muted-foreground">
                If the start has already passed when it is approved, the price
                starts at approval.
            </p>

            <p
                v-if="missing"
                class="text-sm text-muted-foreground"
                data-testid="propose-missing"
            >
                {{ missing }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="submit"
                    class="max-sm:min-h-11"
                    :disabled="!canSubmit"
                >
                    {{ form.busy ? 'Proposing…' : 'Propose price change' }}
                </Button>
            </div>
            <p v-if="stale" class="text-sm">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="max-sm:min-h-11"
                    @click="loadSelection"
                    >Propose again</Button
                >
            </p>
        </form>

        <template v-if="tab === 'waiting' || tab === 'decided'">
            <div
                v-if="tab === 'waiting' && canApprove && selectable.length > 0"
                class="mb-3 space-y-2 rounded-xl border bg-card p-4 text-sm"
                data-testid="bulk-approve"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex min-h-11 items-center gap-2">
                        <input
                            type="checkbox"
                            class="size-5"
                            :checked="allSelected"
                            @change="toggleAll"
                        />
                        Select all {{ selectable.length }} that someone else
                        proposed
                    </label>
                    <Button
                        type="button"
                        class="min-h-11"
                        :disabled="selectedRows.length === 0 || bulkActing"
                        @click="bulkOpen = true"
                        >Approve selected ({{ selectedRows.length }})</Button
                    >
                </div>
                <p
                    v-if="waitingCount > waiting.length"
                    class="text-xs text-muted-foreground"
                >
                    Showing the oldest {{ waiting.length }} of
                    {{ waitingCount }} waiting. Approve these and the next ones
                    appear.
                </p>
                <div
                    v-if="bulkOpen && selectedRows.length > 0"
                    class="space-y-2 border-t pt-3"
                    role="group"
                    aria-label="Confirm approval of the selected price changes"
                >
                    <p>
                        Approve {{ selectedRows.length }} price
                        {{ selectedRows.length === 1 ? 'change' : 'changes' }}?
                        Each one is checked on its own. Any that cannot be
                        approved are skipped. Review the refreshed lists and
                        reasons after approval.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            class="min-h-11"
                            :disabled="bulkActing"
                            @click="approveSelected"
                            >Confirm approval</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11"
                            :disabled="bulkActing"
                            @click="bulkOpen = false"
                            >Cancel</Button
                        >
                    </div>
                </div>
            </div>
            <EmptyState
                v-if="(tab === 'waiting' ? waiting : decided).length === 0"
                :title="
                    tab === 'waiting'
                        ? 'No proposals are waiting.'
                        : 'Nothing has been decided yet.'
                "
                description="Proposed Panel price changes appear here."
            />
            <ul
                v-else
                class="divide-y rounded-xl border bg-card"
                :aria-label="
                    tab === 'waiting'
                        ? 'Waiting proposals'
                        : 'Decided proposals'
                "
            >
                <li
                    v-for="row in tab === 'waiting' ? waiting : decided"
                    :key="row.id"
                    class="space-y-2 p-4 text-sm"
                >
                    <label
                        v-if="tab === 'waiting' && canApprove && !row.mine"
                        class="flex min-h-11 items-center gap-2 text-xs"
                    >
                        <input
                            type="checkbox"
                            class="size-5"
                            :checked="selected.includes(row.id)"
                            :aria-label="`Select ${row.panelName} ${row.chargeName}`"
                            @change="toggleRow(row)"
                        />
                        Select for approval
                    </label>
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <span class="min-w-0 font-medium wrap-anywhere"
                            >{{ row.panelName }} · {{ row.chargeName }}</span
                        >
                        <strong class="tabular-nums"
                            ><template v-if="row.oldAmountSen !== null"
                                >{{ myr(row.oldAmountSen) }} → </template
                            >{{ myr(row.newAmountSen) }}</strong
                        >
                    </div>
                    <p class="text-xs wrap-anywhere text-muted-foreground">
                        Starts {{ row.effectiveLabel }} · Reference:
                        {{ row.reference }} · Proposed by {{ row.proposedBy }},
                        {{ row.proposedAtLabel }}
                    </p>
                    <p class="flex items-center gap-2 text-xs">
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :class="statusDot(row.status)"
                            aria-hidden="true"
                        />
                        <span class="font-medium">{{
                            statusWord(row.status)
                        }}</span>
                    </p>

                    <p
                        v-if="row.decidedBy"
                        class="text-xs wrap-anywhere text-muted-foreground"
                    >
                        By {{ row.decidedBy }}, {{ row.decidedAtLabel
                        }}<template v-if="row.reasonLabel">
                            · {{ row.reasonLabel }}</template
                        ><template
                            v-if="row.status === 'approved' && row.appliedLabel"
                        >
                            · applies from {{ row.appliedLabel }}</template
                        >
                    </p>
                    <template v-if="changeOf(row)">
                        <p
                            class="text-xs text-muted-foreground tabular-nums"
                            data-testid="row-change"
                        >
                            Change: {{ sign(changeOf(row)!.diff)
                            }}{{ myr(Math.abs(changeOf(row)!.diff))
                            }}<template v-if="changeOf(row)!.pct !== null"
                                >, {{ sign(changeOf(row)!.diff)
                                }}{{ pctText(changeOf(row)!.pct!) }}%</template
                            >
                        </p>
                        <p
                            v-if="
                                row.status === 'proposed' &&
                                changeOf(row)!.large
                            "
                            class="flex items-center gap-2 text-xs text-warning"
                        >
                            <span
                                class="size-2 shrink-0 rounded-full bg-warning"
                                aria-hidden="true"
                            />
                            Large change. Check the agreement reference.
                        </p>
                    </template>
                    <p
                        v-if="row.status === 'proposed' && row.effectivePassed"
                        class="text-xs text-muted-foreground"
                    >
                        Start has passed: takes effect at approval.
                    </p>
                    <template v-if="row.status === 'proposed'">
                        <p
                            v-if="row.mine"
                            class="text-xs text-muted-foreground"
                        >
                            You proposed this. Someone else must decide.
                        </p>
                        <div
                            v-if="open?.id !== row.id"
                            class="flex flex-wrap gap-2"
                        >
                            <Button
                                v-if="canApprove && !row.mine"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="max-sm:min-h-11"
                                :id="`start-${row.id}-approve`"
                                @click="begin(row, 'approve')"
                                >Approve</Button
                            >
                            <Button
                                v-if="canApprove && !row.mine"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="max-sm:min-h-11"
                                :id="`start-${row.id}-reject`"
                                @click="begin(row, 'reject')"
                                >Reject</Button
                            >
                            <Button
                                v-if="row.mine"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="max-sm:min-h-11"
                                :id="`start-${row.id}-withdraw`"
                                @click="begin(row, 'withdraw')"
                                >Withdraw</Button
                            >
                        </div>
                        <form
                            v-else
                            class="grid max-w-xl gap-3 rounded-lg border p-3"
                            @submit.prevent="act(row)"
                        >
                            <p v-if="open.kind === 'approve'" class="text-sm">
                                Approve {{ row.panelName }} ·
                                {{ row.chargeName }}:
                                <template v-if="row.oldAmountSen !== null"
                                    >{{ myr(row.oldAmountSen) }} → </template
                                >{{ myr(row.newAmountSen) }}, starting
                                {{ row.effectiveLabel }}.
                                <template v-if="changeOf(row)?.large"
                                    ><strong class="text-warning"
                                        >Large change{{
                                            changeOf(row)!.pct !== null
                                                ? ` (${sign(changeOf(row)!.diff)}${pctText(changeOf(row)!.pct!)}%)`
                                                : ''
                                        }}.</strong
                                    >
                                    Check the agreement reference.
                                </template>
                                <template v-if="row.effectivePassed"
                                    >This will take effect now, at
                                    approval.</template
                                >
                            </p>
                            <template v-else-if="open.kind === 'reject'">
                                <label class="grid gap-1 text-xs">
                                    Reason *
                                    <select
                                        :id="`reason-${row.id}`"
                                        v-model="rejectReason"
                                        class="h-11 w-full rounded-md border bg-background px-2 text-sm lg:h-9"
                                    >
                                        <option value="" disabled>
                                            Choose a reason
                                        </option>
                                        <option
                                            v-for="(
                                                label, code
                                            ) in rejectReasons"
                                            :key="code"
                                            :value="code"
                                        >
                                            {{ label }}
                                        </option>
                                    </select>
                                </label>
                                <label class="grid gap-1 text-xs">
                                    Note (optional)
                                    <textarea
                                        v-model="rejectNote"
                                        maxlength="200"
                                        rows="2"
                                        class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                    />
                                </label>
                            </template>
                            <p v-else class="text-sm">
                                Withdraw this proposal? Nothing changes in the
                                Panel price.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    :id="`confirm-${row.id}`"
                                    type="submit"
                                    size="sm"
                                    class="max-sm:min-h-11"
                                    :variant="
                                        open.kind === 'reject'
                                            ? 'destructive'
                                            : 'default'
                                    "
                                    :disabled="
                                        acting ||
                                        (open.kind === 'reject' &&
                                            rejectReason === '')
                                    "
                                >
                                    {{
                                        open.kind === 'approve'
                                            ? 'Confirm approval'
                                            : open.kind === 'reject'
                                              ? 'Confirm rejection'
                                              : 'Confirm withdrawal'
                                    }}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="max-sm:min-h-11"
                                    :disabled="acting"
                                    @click="cancel(row)"
                                    >Cancel</Button
                                >
                            </div>
                        </form>
                    </template>
                    <Button
                        v-else-if="
                            canPropose &&
                            (row.status === 'rejected' ||
                                row.status === 'withdrawn')
                        "
                        type="button"
                        variant="outline"
                        size="sm"
                        class="max-sm:min-h-11"
                        @click="proposeAgain(row)"
                        >Propose again</Button
                    >
                </li>
            </ul>
            <CompactPagination
                v-if="tab === 'decided' && decidedPagination.total > 0"
                :current-page="decidedPagination.page"
                :last-page="decidedPagination.lastPage"
                :total="decidedPagination.total"
                @change="turnPage"
            />
        </template>
    </main>
</template>
