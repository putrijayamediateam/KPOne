<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ScanInput from '@/components/inventory/ScanInput.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import type { KeyValueItem } from '@/components/v2/V2KeyValueList.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatInventoryReason } from '@/lib/inventory-picker';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { formatDate } from '@/lib/presentation';
import type { ScannedSku } from '@/lib/scan';
import { trimQuantity } from '@/lib/supplier-terms';
import {
    formatTransferThousandths,
    transferQuantityThousandths,
} from '@/lib/transfer-receipt';

/**
 * Inventory V2 page 3: Stock count and Write-off (STK-5 governed cases, STK-23 cycle counts and blind counts). Shown only
 * in V2; Classic is `pages/Inventory/GovernedStock.vue`, untouched. Same props, routes, payloads and permissions: the
 * script from `type Target` to `caseKindLabel` is a verbatim copy of Classic (a test compares them). Only the layout and
 * the plain sentences differ. Blind counts hold: the server leaves out every quantity while a count or recount waits,
 * and this page never shows one there either (tested).
 */
type Target = {
    id: number;
    /** Left out in count mode (STK-23 blind counts). */
    quantity?: string;
    lock_version: number;
    sku_code: string;
    stock_unit: string;
    batch_number: string;
    expiry_date: string;
    location: string;
};
type Action = {
    id: number;
    action: string;
    state: string;
    actor_user_id: number;
    /** Left out while a count or recount waits (STK-23 blind counts). */
    quantity?: string | null;
    lock_version: number;
    movement_id: number | null;
    created_at: string;
};
type Case = {
    id: number;
    public_id: string;
    balance_id: number;
    /** Left out while a count or recount waits (STK-23 blind counts). */
    expected_quantity?: string;
    expected_balance_version: number;
    current_balance_version: number;
    proposer_user_id: number;
    kind: string;
    reason: string;
    quantity?: string | null;
    disposal_reference: string | null;
    blind: boolean;
    state: string;
    lock_version: number;
    sku_code: string;
    stock_unit: string;
    batch_number: string;
    location: string;
    history: Action[];
    canCount: boolean;
    canApprove: boolean;
    canReverse: boolean;
};
type CycleRow = {
    skuPublicId: string;
    skuCode: string;
    item: string;
    locationPublicId: string;
    location: string;
    class: 'A' | 'B' | 'C';
    lastCounted: string | null;
    dueOn: string | null;
    overdueDays: number | null;
    open: boolean;
};
type Page<T> = { rows: T[]; total: number; page: number; lastPage: number };
const props = defineProps<{
    stock: {
        branchId: number;
        branchName: string;
        mode: 'count' | 'writeoff';
        q: string;
        state: string;
        legacyLimit: string;
        canPropose: boolean;
        cases: Page<Case>;
        targets: Page<Target>;
        cycle?: Page<CycleRow> & {
            intervals: Record<'A' | 'B' | 'C', number>;
            lookBackDays: number;
        };
    };
}>();
const path =
    props.stock.mode === 'count' ? '/inventory/counts' : '/inventory/writeoffs';
const states: Record<string, string> = {
    awaiting_count: 'Counter must enter the first count',
    awaiting_recount: 'A different counter must recount',
    awaiting_approval:
        'Independent CA Supervisor, Finance Officer or Director approval required',
    closed_zero: 'Closed: zero variance, no stock movement',
    posted: 'Posted: immutable movement recorded',
};
const q = ref(props.stock.q);
const state = ref(props.stock.state);
const open = ref(false);
const history = ref<Case | null>(null);
const label = ref('');
const endpoint = ref('');
const operation = ref('');
const form = useForm({
    expected_branch_id: props.stock.branchId,
    lock_version: 0,
    retry_key: '',
    balance_id: 0,
    balance_version: 0,
    unit: '',
    quantity: '',
    reason: 'expired',
    disposal_reference: '',
    reverses_case_id: 0,
    return_to: props.stock.mode,
});
function scanned(sku: ScannedSku) {
    q.value = sku.sku_code;
    search();
}
function search(page = 1, targets = 1, cycle = props.stock.cycle?.page ?? 1) {
    router.get(
        path,
        {
            q: q.value,
            state: state.value,
            page,
            targets_page: targets,
            ...(props.stock.mode === 'count' ? { cycle_page: cycle } : {}),
        },
        { preserveScroll: true },
    );
}
// STK-23: open STK-5 count cases for every batch of a SKU at a location.
const starting = ref<string | null>(null);
function startCount(row: CycleRow) {
    if (starting.value) {
        return;
    }

    starting.value = `${row.skuPublicId}:${row.locationPublicId}`;
    router.post(
        '/inventory/counts/cycle',
        {
            expected_branch_id: props.stock.branchId,
            retry_key: crypto.randomUUID(),
            sku_public_id: row.skuPublicId,
            location_public_id: row.locationPublicId,
        },
        { preserveScroll: true, onFinish: () => (starting.value = null) },
    );
}
const cycleError = computed(
    () => (usePage().props.errors as Record<string, string> | undefined)?.cycle,
);
// Daniel: the list shows the 10 most overdue; "See all" pages through every row.
const dueCap = 10;
const showAllDue = ref((props.stock.cycle?.page ?? 1) > 1);
const visibleCycleRows = computed(() =>
    showAllDue.value
        ? (props.stock.cycle?.rows ?? [])
        : (props.stock.cycle?.rows ?? []).slice(0, dueCap),
);
const dueLabel = (row: CycleRow) =>
    row.overdueDays === null
        ? 'Never counted'
        : row.overdueDays === 0
          ? 'Due today'
          : `${row.overdueDays} ${row.overdueDays === 1 ? 'day' : 'days'} overdue`;
function showTarget(row: Target) {
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.stock.branchId;
    form.retry_key = crypto.randomUUID();
    form.balance_id = row.id;
    form.balance_version = row.lock_version;
    form.unit = row.stock_unit;
    // Blind count: no on-hand quantity in count mode.
    label.value =
        row.quantity === undefined
            ? `${row.sku_code} · ${row.batch_number} · ${row.location}`
            : `${row.sku_code} · ${row.batch_number} · ${row.location} · On hand ${row.quantity} ${row.stock_unit}`;
    operation.value = props.stock.mode;
    endpoint.value = `/inventory/governed/${props.stock.mode}`;
    open.value = true;
}
function showCase(row: Case, action: string) {
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.stock.branchId;
    form.retry_key = crypto.randomUUID();
    form.lock_version = row.lock_version;
    form.unit = row.stock_unit;
    label.value = row.blind
        ? `${row.sku_code} · ${row.batch_number} · Count what is there; the expected quantity is not shown`
        : `${row.sku_code} · ${row.batch_number} · Snapshot ${row.expected_quantity} ${row.stock_unit}`;
    operation.value = action;

    if (action === 'reversal') {
        form.lock_version = 0;
        form.balance_id = row.balance_id;
        form.balance_version = row.current_balance_version;
        form.reverses_case_id = row.id;
        endpoint.value = '/inventory/governed/reversal';
    } else {
        endpoint.value = `/inventory/governed/${row.id}/${action}`;
    }

    open.value = true;
}
function submit() {
    form.transform((data) => {
        const common = {
            expected_branch_id: data.expected_branch_id,
            lock_version: data.lock_version,
            retry_key: data.retry_key,
        };

        if (operation.value === 'count') {
            return {
                ...common,
                balance_id: data.balance_id,
                balance_version: data.balance_version,
                unit: data.unit,
            };
        }

        if (operation.value === 'writeoff') {
            return {
                ...common,
                balance_id: data.balance_id,
                balance_version: data.balance_version,
                unit: data.unit,
                quantity: data.quantity,
                reason: data.reason,
                disposal_reference: data.disposal_reference,
            };
        }

        if (operation.value === 'reversal') {
            return {
                ...common,
                balance_id: data.balance_id,
                balance_version: data.balance_version,
                unit: data.unit,
                reverses_case_id: data.reverses_case_id,
            };
        }

        if (operation.value === 'approved') {
            return { ...common, return_to: props.stock.mode };
        }

        return {
            ...common,
            quantity: data.quantity,
            unit: data.unit,
            return_to: props.stock.mode,
        };
    }).post(endpoint.value, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
const page = usePage();
watch(
    () => page.props.branchContext?.active?.id,
    () => {
        open.value = false;
        history.value = null;
        label.value = '';
        endpoint.value = '';
        q.value = '';
        state.value = '';
        form.reset();
        form.clearErrors();
    },
);
// TIDY-4: the case type in words, not its id or stored code.
const caseKindLabel = (kind: string) =>
    ({ count: 'Stock count', writeoff: 'Write-off', reversal: 'Reversal' })[
        kind
    ] ?? kind;

// ---- V2 only -------------------------------------------------------------------------------------------------------
const heading = props.stock.mode === 'count' ? 'Stock count' : 'Write-off';
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: heading },
]);
/** The item's name when the server sends it, else the SKU code. */
const itemOf = (row: object): string | null => {
    const value = (row as { item?: unknown }).item;

    return typeof value === 'string' && value !== '' ? value : null;
};
const nameOf = (row: { sku_code: string }) => itemOf(row) ?? row.sku_code;

// The five states as chips, in words (Daniel, page 3 answer 2).
const stateChips = [
    { value: '', label: 'All' },
    { value: 'awaiting_count', label: 'Waiting for count' },
    { value: 'awaiting_recount', label: 'Waiting for recount' },
    { value: 'awaiting_approval', label: 'Waiting for approval' },
    { value: 'closed_zero', label: 'Closed, no difference' },
    { value: 'posted', label: 'Posted' },
];
const stateLabel = (value: string) =>
    stateChips.find((chip) => chip.value === value)?.label ?? value;
const stateTone = (value: string) =>
    value === 'posted'
        ? 'success'
        : value === 'closed_zero'
          ? 'neutral'
          : 'warning';
const chooseState = (value: string) => {
    state.value = value;
    search();
};

const reasons = [
    { value: 'expired', label: 'Expired' },
    { value: 'damaged', label: 'Damaged' },
    { value: 'contaminated', label: 'Contaminated' },
    { value: 'other_approved', label: 'Other approved' },
];

/** The row the open sheet is about, kept for its words. */
const activeTarget = ref<Target | null>(null);
const activeCase = ref<Case | null>(null);
const openTarget = (row: Target) => {
    activeCase.value = null;
    activeTarget.value = row;
    showTarget(row);
};
const openCase = (row: Case, action: string) => {
    activeTarget.value = null;
    activeCase.value = row;
    showCase(row, action);
};
const closeSheet = (value: boolean) => {
    open.value = value;
};

const caseMenu = (row: Case): RowMenuGroup[] => {
    const groups: RowMenuGroup[] = [
        {
            label: 'Open',
            items: [
                { id: 'history', label: 'Evidence history', allowed: true },
            ],
        },
    ];
    const act = [
        ...(row.canCount
            ? [
                  {
                      id:
                          row.state === 'awaiting_count'
                              ? 'counted'
                              : 'recounted',
                      label:
                          row.state === 'awaiting_count'
                              ? 'Enter count'
                              : 'Recount',
                      allowed: true,
                  },
              ]
            : []),
        ...(row.canApprove
            ? [{ id: 'approved', label: 'Approve', allowed: true }]
            : []),
    ];

    if (act.length) {
        groups.push({ label: 'Act', items: act });
    }

    if (row.canReverse) {
        groups.push({
            label: 'Danger',
            items: [{ id: 'reversal', label: 'Reverse', allowed: true }],
        });
    }

    return groups;
};
const onCaseMenu = (row: Case, id: string) => {
    if (id === 'history') {
        history.value = row;

        return;
    }

    openCase(row, id);
};

const f = (value: bigint) => trimQuantity(formatTransferThousandths(value));
/** Write-off: the box may not ask for more than is on hand (Daniel, change e). */
const overOnHand = computed(() => {
    const onHand = activeTarget.value?.quantity;

    if (operation.value !== 'writeoff' || onHand === undefined) {
        return null;
    }

    const asked = transferQuantityThousandths(String(form.quantity));
    const have = transferQuantityThousandths(onHand);

    return asked !== null && have !== null && asked > have
        ? `Only ${quantityWithUnit(f(have), form.unit)} ${Number(f(have)) === 1 ? 'is' : 'are'} on hand.`
        : null;
});
const writeoffSentence = computed(() => {
    const row = activeTarget.value;

    if (!row || !String(form.quantity).trim()) {
        return 'Enter the quantity to see what this proposes.';
    }

    return `Proposes writing off ${quantityWithUnit(String(form.quantity).trim(), form.unit)} of ${nameOf(row)}, batch ${row.batch_number}, at ${row.location}. Nothing moves until a CA Supervisor, Finance Officer or Director who did not propose it approves.`;
});

/** The figure the approver decides on: the latest count or recount (Classic's approver sees it in the history). */
const countedOf = (row: Case) =>
    [...row.history]
        .reverse()
        .find(
            (entry) =>
                ['counted', 'recounted'].includes(entry.action) &&
                entry.quantity !== null &&
                entry.quantity !== undefined,
        )?.quantity ?? null;
const differenceOf = (row: Case) => {
    const counted = countedOf(row);

    if (counted === null || row.expected_quantity === undefined) {
        return null;
    }

    const a = transferQuantityThousandths(counted);
    const b = transferQuantityThousandths(row.expected_quantity);

    if (a === null || b === null) {
        return null;
    }

    return a === b
        ? 'None'
        : a < b
          ? `${quantityWithUnit(f(b - a), row.stock_unit)} fewer than the snapshot`
          : `${quantityWithUnit(f(a - b), row.stock_unit)} more than the snapshot`;
};
/** Approve: what is being approved, as label and value rows (Daniel, change a). Never used while a count is blind. */
const approveRows = computed<KeyValueItem[]>(() => {
    const row = activeCase.value;

    if (!row || row.blind) {
        return [];
    }

    const reverses = (row as { reverses_case_id?: number | null })
        .reverses_case_id;

    return [
        { label: 'Case', value: `${caseKindLabel(row.kind)} #${row.id}` },
        ...(reverses
            ? [{ label: 'Reverses', value: `Case #${reverses}` }]
            : []),
        { label: 'Item', value: nameOf(row) },
        { label: 'SKU', value: row.sku_code },
        { label: 'Batch', value: row.batch_number },
        { label: 'Location', value: row.location },
        { label: 'Reason', value: formatInventoryReason(row.reason) },
        ...(row.kind === 'count'
            ? [
                  {
                      label: 'Snapshot',
                      value: quantityWithUnit(
                          row.expected_quantity,
                          row.stock_unit,
                      ),
                  },
                  {
                      label: 'Counted',
                      value: countedOf(row)
                          ? quantityWithUnit(countedOf(row), row.stock_unit)
                          : null,
                  },
                  { label: 'Difference', value: differenceOf(row) },
              ]
            : []),
        ...(row.kind === 'writeoff'
            ? [
                  {
                      label: 'Quantity',
                      value: row.quantity
                          ? quantityWithUnit(row.quantity, row.stock_unit)
                          : null,
                  },
                  {
                      label: 'Disposal reference',
                      value: row.disposal_reference
                          ? `${row.disposal_reference} (referenced, not proven)`
                          : null,
                  },
              ]
            : []),
    ];
});
/** Where the sheet is about: item, SKU, batch, location. Never a quantity (counts are blind). */
const whereRows = computed<KeyValueItem[]>(() => {
    const row = activeCase.value ?? activeTarget.value;

    return row
        ? [
              { label: 'Item', value: nameOf(row) },
              { label: 'SKU', value: row.sku_code },
              { label: 'Batch', value: row.batch_number },
              { label: 'Location', value: row.location },
          ]
        : [];
});

const sheetTitle = computed(
    () =>
        ({
            count: 'Start a count',
            writeoff: 'Write off stock',
            counted: 'Enter count',
            recounted: 'Recount',
            approved: 'Approve',
            reversal: 'Reverse',
        })[operation.value] ?? '',
);
const confirmLabel = computed(
    () =>
        ({
            count: 'Start count',
            writeoff: 'Propose write-off',
            counted: 'Save count',
            recounted: 'Save recount',
            approved: 'Approve and post',
            reversal: 'Propose reversal',
        })[operation.value] ?? 'Save',
);
const hasInput = computed(() =>
    ['writeoff', 'counted', 'recounted'].includes(operation.value),
);
const errorList = computed(() =>
    Object.values(form.errors as Record<string, string>),
);

// At phone width the counts due and the long list of stock stay behind their bars, so the cases needing a person show
// near the top and three lists don't make one long scroll (Daniel, d).
const wide =
    typeof window === 'undefined' ||
    window.matchMedia('(min-width: 640px)').matches;
</script>

<template>
    <Head :title="heading" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout :title="heading" :description="stock.branchName">
            <template #actions>
                <Link
                    :href="
                        stock.mode === 'count'
                            ? '/inventory/writeoffs'
                            : '/inventory/counts'
                    "
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium text-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >{{
                        stock.mode === 'count' ? 'Write-off' : 'Stock count'
                    }}</Link
                >
            </template>
            <template #main>
                <p class="text-sm text-muted-foreground">
                    One snapshot per SKU, batch and location. Any difference
                    needs a recount by a different person and approval by
                    someone who did not count. Held batches can be counted and
                    written off without releasing their hold. A disposal
                    reference is recorded, not proven.
                </p>

                <!-- Counts due (STK-23), count mode only. -->
                <V2DisclosureBar
                    v-if="stock.mode === 'count' && stock.cycle"
                    :title="`Counts due (${stock.cycle.total})`"
                    :default-open="wide"
                    keep-mounted
                >
                    <section
                        class="flex min-w-0 flex-col gap-3"
                        aria-label="Counts due"
                        data-testid="counts-due"
                    >
                        <p class="text-sm text-muted-foreground">
                            Class A is counted every
                            {{ stock.cycle.intervals.A }} days, B every
                            {{ stock.cycle.intervals.B }} and C every
                            {{ stock.cycle.intervals.C }}, from how often the
                            item was dispensed here in the last
                            {{ stock.cycle.lookBackDays }} days; controlled
                            medicines are always A. Amber: due or up to 29 days
                            overdue. Red: 30 days or more.
                        </p>
                        <V2Table
                            label="Counts due"
                            :columns="5"
                            cards="xl"
                            min-width="760px"
                            :empty="stock.cycle.rows.length === 0"
                            empty-message="Nothing is due for counting."
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Item</th>
                                    <th scope="col">Location</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Due</th>
                                    <th scope="col">
                                        <span class="sr-only">Action</span>
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="row in visibleCycleRows"
                                    :key="`${row.skuPublicId}:${row.locationPublicId}`"
                                >
                                    <td data-label="Item" data-primary>
                                        <div class="min-w-0">
                                            <span
                                                class="block text-sm font-medium break-words"
                                                >{{ row.item }}</span
                                            >
                                            <span
                                                class="block text-xs break-words text-muted-foreground"
                                                >{{ row.skuCode }}</span
                                            >
                                        </div>
                                    </td>
                                    <td
                                        data-label="Location"
                                        class="break-words"
                                    >
                                        {{ row.location }}
                                    </td>
                                    <td data-label="Class">
                                        <V2Chip
                                            :tone="
                                                row.class === 'A'
                                                    ? 'info'
                                                    : 'neutral'
                                            "
                                            :label="`Class ${row.class}`"
                                        />
                                    </td>
                                    <td data-stack data-label="Due">
                                        <div class="min-w-0">
                                            <V2Chip
                                                :tone="
                                                    row.overdueDays !== null &&
                                                    row.overdueDays >= 30
                                                        ? 'danger'
                                                        : 'warning'
                                                "
                                                :label="dueLabel(row)"
                                            />
                                            <span
                                                class="mt-1 block text-xs text-muted-foreground"
                                                >{{
                                                    row.lastCounted
                                                        ? `Last counted ${row.lastCounted}`
                                                        : 'No completed count'
                                                }}</span
                                            >
                                        </div>
                                    </td>
                                    <td data-label="Action">
                                        <V2Chip
                                            v-if="row.open"
                                            tone="neutral"
                                            label="Count open"
                                        />
                                        <button
                                            v-else-if="stock.canPropose"
                                            type="button"
                                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9"
                                            :disabled="starting !== null"
                                            :aria-busy="
                                                starting ===
                                                `${row.skuPublicId}:${row.locationPublicId}`
                                            "
                                            :aria-label="`Start count for ${row.item} (${row.skuCode}) at ${row.location}`"
                                            @click="startCount(row)"
                                        >
                                            Start count
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
                        <p
                            v-if="!showAllDue && stock.cycle.total > dueCap"
                            class="text-sm text-muted-foreground"
                            data-testid="due-cap"
                        >
                            Showing {{ visibleCycleRows.length }} of
                            {{ stock.cycle.total }} ·
                            <button
                                type="button"
                                class="inline-flex h-11 items-center px-1 font-medium text-primary underline lg:h-9"
                                @click="showAllDue = true"
                            >
                                See all
                            </button>
                        </p>
                        <V2Pager
                            v-if="showAllDue && stock.cycle.lastPage > 1"
                            :current-page="stock.cycle.page"
                            :last-page="stock.cycle.lastPage"
                            :total="stock.cycle.total"
                            :per-page="25"
                            @change="
                                (next) =>
                                    search(
                                        stock.cases.page,
                                        stock.targets.page,
                                        next,
                                    )
                            "
                        />
                        <p
                            v-if="cycleError"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ cycleError }}
                        </p>
                    </section>
                </V2DisclosureBar>

                <!-- Find: scan or search, and the state chips. -->
                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-label="Find stock and cases"
                >
                    <ScanInput
                        context="search"
                        :refocus-key="`governed-${stock.mode}`"
                        lookup-variant="outline"
                        @found="scanned"
                    />
                    <form
                        class="flex min-w-0 flex-wrap items-end gap-2"
                        role="search"
                        @submit.prevent="search()"
                    >
                        <label
                            class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                            >Search SKU, batch or location
                            <input
                                v-model="q"
                                type="search"
                                maxlength="100"
                                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px] lg:h-9"
                        /></label>
                        <button
                            type="submit"
                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                        >
                            Search
                        </button>
                    </form>
                    <V2OptionGroup
                        :model-value="state"
                        label="Case status"
                        :options="stateChips"
                        @update:model-value="chooseState"
                    />
                </section>

                <!-- Cases first: Recount and Approve are what needs a person now (Daniel, change c). -->
                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-cases"
                >
                    <h2 id="v2-cases" class="v2-section-title">
                        Cases and next action
                    </h2>
                    <V2Table
                        label="Cases and next action"
                        :columns="5"
                        cards="xl"
                        min-width="820px"
                        :empty="stock.cases.rows.length === 0"
                        empty-message="No matching cases."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Case</th>
                                <th scope="col">Item and batch</th>
                                <th scope="col">Location</th>
                                <th scope="col">Status</th>
                                <th scope="col">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in stock.cases.rows" :key="row.id">
                                <td data-label="Case" data-primary>
                                    <div class="min-w-0">
                                        <span class="block text-sm font-medium"
                                            >{{ caseKindLabel(row.kind) }} #{{
                                                row.id
                                            }}</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{
                                                formatInventoryReason(
                                                    row.reason,
                                                )
                                            }}</span
                                        >
                                    </div>
                                </td>
                                <td data-stack data-label="Item and batch">
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm break-words"
                                            >{{ nameOf(row) }}</span
                                        >
                                        <span
                                            class="block text-xs break-words text-muted-foreground"
                                            >{{ row.sku_code }} · batch
                                            {{ row.batch_number }}</span
                                        >
                                        <span
                                            v-if="row.blind"
                                            class="block text-xs text-muted-foreground"
                                            data-testid="blind-count"
                                            >Blind count: the approver sees the
                                            figures once the counts are
                                            in.</span
                                        >
                                    </div>
                                </td>
                                <td data-label="Location" class="break-words">
                                    {{ row.location }}
                                </td>
                                <td data-label="Status">
                                    <V2Chip
                                        :tone="stateTone(row.state)"
                                        :label="stateLabel(row.state)"
                                    />
                                </td>
                                <td data-label="Actions">
                                    <!-- No `busy`: the menu only opens a sheet, which shows the save in progress. V2RowMenu's
                                         busy state left it unable to open again after a save (seen in the preview). -->
                                    <V2RowMenu
                                        :label="`Actions for ${caseKindLabel(row.kind)} #${row.id}`"
                                        :groups="caseMenu(row)"
                                        @select="(id) => onCaseMenu(row, id)"
                                    />
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        :current-page="stock.cases.page"
                        :last-page="stock.cases.lastPage"
                        :total="stock.cases.total"
                        :per-page="25"
                        @change="(next) => search(next, stock.targets.page)"
                    />
                </section>

                <!-- The stock to count or write off: long, so it sits behind its bar on a phone (Daniel, change d). -->
                <V2DisclosureBar
                    :title="
                        stock.mode === 'count'
                            ? 'Find stock to count'
                            : 'Find stock to write off'
                    "
                    :default-open="wide"
                    keep-mounted
                >
                    <V2Table
                        :label="
                            stock.mode === 'count'
                                ? 'Stock to count'
                                : 'Stock to write off'
                        "
                        :columns="stock.mode === 'count' ? 4 : 5"
                        cards="xl"
                        min-width="760px"
                        :empty="stock.targets.rows.length === 0"
                        empty-message="No matching stock. Search finds stock beyond this page."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">SKU</th>
                                <th scope="col">Batch and expiry</th>
                                <th scope="col">Location</th>
                                <th
                                    v-if="stock.mode === 'writeoff'"
                                    scope="col"
                                    class="text-right"
                                >
                                    On hand
                                </th>
                                <th scope="col">
                                    <span class="sr-only">Action</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in stock.targets.rows" :key="row.id">
                                <td data-label="SKU" data-primary>
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm font-medium break-words"
                                            >{{ nameOf(row) }}</span
                                        >
                                        <span
                                            v-if="itemOf(row)"
                                            class="block text-xs break-words text-muted-foreground"
                                            >{{ row.sku_code }}</span
                                        >
                                    </div>
                                </td>
                                <td data-stack data-label="Batch and expiry">
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm break-words"
                                            >{{ row.batch_number }}</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >Expires
                                            {{
                                                formatDate(row.expiry_date)
                                            }}</span
                                        >
                                    </div>
                                </td>
                                <td data-label="Location" class="break-words">
                                    {{ row.location }}
                                </td>
                                <td
                                    v-if="stock.mode === 'writeoff'"
                                    data-label="On hand"
                                    class="text-right tabular-nums"
                                >
                                    {{
                                        quantityWithUnit(
                                            row.quantity,
                                            row.stock_unit,
                                        )
                                    }}
                                </td>
                                <td data-label="Action">
                                    <button
                                        v-if="stock.canPropose"
                                        type="button"
                                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                        :aria-label="`${stock.mode === 'count' ? 'Start a count of' : 'Write off'} ${nameOf(row)}, batch ${row.batch_number}, at ${row.location}`"
                                        @click="openTarget(row)"
                                    >
                                        {{
                                            stock.mode === 'count'
                                                ? 'Start count'
                                                : 'Write off'
                                        }}
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        :current-page="stock.targets.page"
                        :last-page="stock.targets.lastPage"
                        :total="stock.targets.total"
                        :per-page="25"
                        @change="(next) => search(stock.cases.page, next)"
                    />
                </V2DisclosureBar>
            </template>
        </V2WorkLayout>

        <!-- One sheet for every action; one filled button; it asks before closing with input typed. -->
        <V2Dialog
            :open="open"
            :title="sheetTitle"
            :confirm-label="confirmLabel"
            :tone="operation === 'reversal' ? 'danger' : 'default'"
            :busy="form.processing"
            :dirty="hasInput && form.isDirty"
            :confirm-disabled="overOnHand !== null"
            :confirm-disabled-reason="
                overOnHand
                    ? 'Enter no more than is on hand to continue.'
                    : undefined
            "
            @update:open="closeSheet"
            @confirm="submit"
        >
            <div
                v-if="errorList.length"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                <p class="font-medium">This was not saved.</p>
                <p v-for="message in errorList" :key="message">
                    {{ message }}
                </p>
            </div>

            <!-- Start a count, enter a count, recount: never a quantity (blind counts). -->
            <template
                v-if="['count', 'counted', 'recounted'].includes(operation)"
            >
                <V2KeyValueList :items="whereRows" />
                <p
                    v-if="operation === 'count'"
                    class="text-sm text-muted-foreground"
                >
                    Starting a count freezes what KPOne holds now for this batch
                    here. The figure is not shown: you count what is there next.
                </p>
                <p
                    v-else-if="operation === 'counted'"
                    class="text-sm text-muted-foreground"
                >
                    Count what is physically there. The expected quantity is not
                    shown.
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    Someone else counted first. Count again yourself; their
                    figure and the expected quantity are not shown.
                </p>
            </template>

            <!-- Write off. -->
            <template v-if="operation === 'writeoff'">
                <V2KeyValueList :items="whereRows" />
            </template>
            <label
                v-if="hasInput"
                class="flex flex-col gap-1.5 text-sm font-medium"
                >{{
                    operation === 'writeoff'
                        ? 'Quantity to write off'
                        : 'Quantity counted'
                }}
                ({{ form.unit }})
                <input
                    v-model="form.quantity"
                    inputmode="decimal"
                    maxlength="16"
                    required
                    :aria-invalid="overOnHand !== null"
                    class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                />
                <span
                    v-if="overOnHand"
                    class="text-sm font-normal text-destructive"
                    >{{ overOnHand }}</span
                >
            </label>
            <template v-if="operation === 'writeoff'">
                <V2OptionGroup
                    :model-value="form.reason"
                    label="Reason"
                    required
                    :options="reasons"
                    @update:model-value="(value) => (form.reason = value)"
                />
                <label class="flex flex-col gap-1.5 text-sm font-medium"
                    >Disposal document or log reference
                    <input
                        v-model="form.disposal_reference"
                        maxlength="200"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                    <span class="text-xs font-normal text-muted-foreground"
                        >Recorded only. KPOne does not prove physical
                        disposal.</span
                    >
                </label>
                <p
                    class="rounded-[14px] border bg-muted/40 px-4 py-3 text-sm"
                    role="status"
                >
                    {{ writeoffSentence }}
                </p>
            </template>

            <!-- Approve: what approving does first, so it shows with the button on a phone (Daniel), then what is approved. -->
            <template v-if="operation === 'approved'">
                <p class="rounded-[14px] border bg-muted/40 px-4 py-3 text-sm">
                    Approving records the stock movement now. It cannot be
                    edited; a mistake is undone by a reversal, which needs its
                    own independent approval.
                </p>
                <V2KeyValueList :items="approveRows" empty-text="Not shown" />
            </template>

            <!-- Reverse: names the case it reverses. -->
            <template v-if="operation === 'reversal' && activeCase">
                <V2KeyValueList :items="whereRows" />
                <p
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm"
                >
                    Proposes reversing {{ caseKindLabel(activeCase.kind) }} #{{
                        activeCase.id
                    }}. The reversal is a new case: stock moves back only after
                    a CA Supervisor, Finance Officer or Director who was not
                    involved approves it. The original evidence stays.
                </p>
            </template>
        </V2Dialog>

        <!-- Evidence history: read only. -->
        <V2Dialog
            :open="history !== null"
            title="Evidence history"
            :description="
                history
                    ? `${caseKindLabel(history.kind)} #${history.id} · ${nameOf(history)}`
                    : undefined
            "
            confirm-label="Close"
            single-action
            @update:open="(value) => !value && (history = null)"
            @confirm="history = null"
        >
            <p class="text-sm text-muted-foreground">
                Counts and recounts are kept as they were entered. Nothing is
                edited or deleted.
            </p>
            <ol class="flex flex-col gap-2">
                <li
                    v-for="entry in history?.history"
                    :key="entry.id"
                    class="rounded-[10px] border px-3 py-2 text-sm"
                >
                    <p class="font-medium">
                        {{ entry.action.replaceAll('_', ' ') }} · Staff #{{
                            entry.actor_user_id
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ states[entry.state] ?? entry.state }}
                    </p>
                    <p
                        v-if="
                            entry.quantity !== null &&
                            entry.quantity !== undefined
                        "
                    >
                        Quantity
                        {{
                            quantityWithUnit(
                                entry.quantity,
                                history?.stock_unit,
                            )
                        }}
                    </p>
                    <p v-if="entry.movement_id" class="text-muted-foreground">
                        Movement #{{ entry.movement_id }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <time :datetime="entry.created_at">{{
                            formatDate(entry.created_at)
                        }}</time>
                        · version {{ entry.lock_version }}
                    </p>
                </li>
            </ol>
        </V2Dialog>
    </PageFrame>
</template>

<style scoped>
/* Phone and tablet cards: a cell with several lines puts its label on its own line and uses the full width (Daniel, page 3). */
@media (max-width: 1279.98px) {
    :deep(.v2-cards-xl td[data-stack]) {
        flex-direction: column;
        align-items: stretch;
        gap: 0.25rem;
        text-align: left;
    }
}
</style>
