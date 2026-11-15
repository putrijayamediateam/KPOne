<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import ScanInput from '@/components/inventory/ScanInput.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import type { KeyValueItem } from '@/components/v2/V2KeyValueList.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import type { PickerOption } from '@/components/v2/V2Picker.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { formatDate, formatDateTime } from '@/lib/presentation';
import type { ScannedSku } from '@/lib/scan';
import {
    rmToSen,
    supplierReturnActionLabels,
    supplierReturnHistoryLabels,
    supplierReturnReasonLabels,
    supplierReturnRejectLabels,
    supplierReturnStateLabels,
} from '@/lib/supplier-returns';
import type { SupplierReturnAction } from '@/lib/supplier-returns';
import { trimQuantity } from '@/lib/supplier-terms';
import {
    formatTransferThousandths,
    transferQuantityThousandths,
} from '@/lib/transfer-receipt';

/**
 * Inventory V2 page 5: Supplier returns (STK-7 single returns, STK-17 multi-lot returns). Shown only in V2; Classic is
 * `pages/Inventory/SupplierReturns.vue`, untouched. Same props, routes, payloads and permissions: the script from
 * `type Lot` to the branch watch is a verbatim copy of Classic (a test compares them). Only the layout and the plain
 * sentences differ.
 */
type Lot = {
    id: number;
    received_quantity: string;
    committed_quantity: string;
    on_hand: string;
    returnable: string;
    outcome: string;
    sku_code: string;
    stock_unit: string;
    batch_number: string;
    expiry_date: string;
    location: string;
    supplier: string;
    delivery_reference: string;
};
type HistoryRow = {
    id: number;
    action: string;
    state: string;
    actor_user_id: number;
    actor_name?: string | null;
    reason_code: string | null;
    reference: string | null;
    credit_amount_sen: number | null;
    lock_version: number;
    created_at: string;
    at_local?: string;
};
type ReturnRow = {
    id: number;
    return_number: string;
    quantity: string;
    base_unit: string;
    reason: string;
    supplier_reference: string | null;
    requester_user_id: number;
    state: string;
    lock_version: number;
    sku_code: string;
    batch_number: string;
    expiry_date: string;
    location: string;
    supplier: string;
    delivery_reference: string;
    history: HistoryRow[];
    canDecide: boolean;
    canDispatch: boolean;
    canCredit: boolean;
    canCancel: boolean;
    requestedByYou: boolean;
};
type Page<T> = { rows: T[]; total: number; page: number; lastPage: number };
type GroupLine = {
    id: number;
    return_number: string;
    quantity: string;
    base_unit: string;
    reason: string;
    sku_code: string;
    batch_number: string;
    expiry_date: string;
};
type GroupRow = {
    id: number;
    groupNumber: string;
    supplier: string;
    deliveryReference: string;
    createdAt: string;
    state: string;
    lockVersion: number;
    lines: GroupLine[];
    history: {
        action: string;
        state: string;
        reasonCode: string | null;
        reference: string | null;
        creditAmountSen: number | null;
        createdAt: string;
    }[];
    canDecide: boolean;
    canDispatch: boolean;
    canCredit: boolean;
    canCancel: boolean;
    requestedByYou: boolean;
};
type Delivery = {
    id: number;
    delivery_reference: string;
    received_at: string;
    supplier: string;
    lots: number;
};
type DeliveryLot = {
    id: number;
    sku_code: string;
    stock_unit: string;
    batch_number: string;
    expiry_date: string;
    returnable: string;
};
const props = defineProps<{
    returns: {
        branchId: number;
        branchName: string;
        q: string;
        canRequest: boolean;
        canApprove: boolean;
        reasons: string[];
        rejectReasons: string[];
        returns: Page<ReturnRow>;
        lots: Page<Lot>;
        groups: Page<GroupRow>;
        deliveries: Delivery[];
        delivery: number | null;
        deliveryLots: DeliveryLot[];
        maxGroupLines: number;
    };
}>();

const q = ref(props.returns.q);
const open = ref(false);
const label = ref('');
const endpoint = ref('');
const operation = ref<'request' | SupplierReturnAction>('request');
const history = ref<ReturnRow | null>(null);
const isGroup = ref(false);
const activeGroup = ref<GroupRow | null>(null);
const groupTitle = (action: SupplierReturnAction, lots: number): string =>
    ({
        approved: `Approve all ${lots} lots?`,
        rejected: `Reject all ${lots} lots?`,
        dispatched: `Dispatch all ${lots} lots?`,
        credit_recorded: `Record one credit note for ${lots} lots`,
        cancelled: `Cancel this return of ${lots} lots?`,
    })[action];
// Totals per stock unit, so lots of different SKUs are never added across units.
const groupTotals = computed(() => {
    const totals = new Map<string, number>();

    for (const line of activeGroup.value?.lines ?? []) {
        totals.set(
            line.base_unit,
            (totals.get(line.base_unit) ?? 0) +
                Math.round(Number(line.quantity) * 1000),
        );
    }

    return [...totals].map(
        ([unit, milli]) => `${(milli / 1000).toFixed(3)} ${unit}`,
    );
});
const form = useForm({
    expected_branch_id: props.returns.branchId,
    retry_key: '',
    lock_version: 0,
    receipt_lot_id: 0,
    unit: '',
    quantity: '',
    reason: 'damaged',
    supplier_reference: '',
    reason_code: '',
    reference: '',
    credit_amount_rm: '',
});

// Server field names (credit_amount_sen, state) differ from the form's own fields.
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
function scanned(sku: ScannedSku) {
    q.value = sku.sku_code;
    search();
}
function search(
    page = 1,
    lots = 1,
    groups = 1,
    delivery = selectedDelivery.value,
) {
    router.get(
        '/inventory/supplier-returns',
        {
            q: q.value,
            page,
            lots_page: lots,
            groups_page: groups,
            delivery: delivery || undefined,
        },
        { preserveScroll: true },
    );
}

// STK-17: several lots from one delivery, sent for approval together.
const selectedDelivery = ref<number | ''>(props.returns.delivery ?? '');
type GroupDraft = { include: boolean; quantity: string; reason: string };
const blankDrafts = () =>
    Object.fromEntries(
        props.returns.deliveryLots.map((lot) => [
            lot.id,
            { include: false, quantity: lot.returnable, reason: 'damaged' },
        ]),
    ) as Record<number, GroupDraft>;
const drafts = ref<Record<number, GroupDraft>>(blankDrafts());
// Keep what was ticked and typed when the same delivery comes back (for example after a refusal), so each
// line's error shows beside it; start again only when another delivery is chosen.
watch(
    () => props.returns.deliveryLots,
    (lots, previous) => {
        const sameDelivery =
            props.returns.delivery !== null &&
            lots.length > 0 &&
            previous?.some((lot) => lot.id === lots[0].id);
        const fresh = blankDrafts();
        drafts.value = sameDelivery
            ? Object.fromEntries(
                  lots.map((lot) => [
                      lot.id,
                      drafts.value[lot.id] ?? fresh[lot.id],
                  ]),
              )
            : fresh;
    },
);
const chosenCount = computed(
    () => Object.values(drafts.value).filter((draft) => draft.include).length,
);
const groupForm = useForm({
    expected_branch_id: props.returns.branchId,
    retry_key: crypto.randomUUID(),
    goods_receipt_id: 0,
    supplier_reference: '',
});
const groupErrors = computed(
    () => groupForm.errors as Record<string, string | undefined>,
);
function chooseDelivery() {
    search(
        props.returns.returns.page,
        props.returns.lots.page,
        props.returns.groups.page,
        selectedDelivery.value,
    );
}
function lineError(index: number): string | undefined {
    return (
        groupErrors.value[`lines.${index}.quantity`] ??
        groupErrors.value[`lines.${index}.receipt_lot_id`] ??
        groupErrors.value[`lines.${index}.reason`]
    );
}
// Keep batch codes such as SYN-MULTI-ABC on one line on a phone (non-breaking hyphens).
const unbroken = (code: string) => code.replaceAll('-', '\u2011');
const lotError = (lotId: number): string | undefined =>
    drafts.value[lotId]?.include ? lineError(chosenIndex(lotId)) : undefined;
const chosenIndex = (lotId: number) =>
    props.returns.deliveryLots
        .filter((lot) => drafts.value[lot.id]?.include)
        .findIndex((lot) => lot.id === lotId);
function submitGroup() {
    groupForm
        .transform((data) => ({
            expected_branch_id: data.expected_branch_id,
            retry_key: data.retry_key,
            goods_receipt_id: Number(selectedDelivery.value),
            supplier_reference: data.supplier_reference,
            lines: props.returns.deliveryLots
                .filter((lot) => drafts.value[lot.id]?.include)
                .map((lot) => ({
                    receipt_lot_id: lot.id,
                    unit: lot.stock_unit,
                    quantity: drafts.value[lot.id].quantity,
                    reason: drafts.value[lot.id].reason,
                })),
        }))
        .post('/inventory/supplier-return-groups', {
            preserveScroll: true,
            preserveState: true,
            onError: () =>
                nextTick(() =>
                    document
                        .querySelector<HTMLElement>(
                            '#multi-heading ~ form [aria-invalid="true"], #multi-heading ~ form [role="alert"]',
                        )
                        ?.scrollIntoView({ block: 'center' }),
                ),
            onSuccess: () => {
                groupForm.reset();
                groupForm.retry_key = crypto.randomUUID();
                selectedDelivery.value = '';
            },
        });
}
function showGroupAction(group: GroupRow, action: SupplierReturnAction) {
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.returns.branchId;
    form.retry_key = crypto.randomUUID();
    form.lock_version = group.lockVersion;
    label.value = `${group.groupNumber} · ${group.lines.length} lots · ${group.supplier} · delivery ${group.deliveryReference}`;
    operation.value = action;
    endpoint.value = `/inventory/supplier-return-groups/${group.id}/${action}`;
    isGroup.value = true;
    activeGroup.value = group;
    open.value = true;
}
function showRequest(lot: Lot) {
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.returns.branchId;
    form.retry_key = crypto.randomUUID();
    form.receipt_lot_id = lot.id;
    form.unit = lot.stock_unit;
    form.quantity = lot.returnable;
    label.value = `${lot.sku_code} · batch ${lot.batch_number} · ${lot.supplier} · delivery ${lot.delivery_reference} · up to ${lot.returnable} ${lot.stock_unit}`;
    operation.value = 'request';
    endpoint.value = '/inventory/supplier-returns';
    open.value = true;
}
function showAction(row: ReturnRow, action: SupplierReturnAction) {
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.returns.branchId;
    form.retry_key = crypto.randomUUID();
    form.lock_version = row.lock_version;
    label.value = `${row.return_number} · ${row.sku_code} · batch ${row.batch_number} · ${row.quantity} ${row.base_unit} · ${row.supplier}`;
    operation.value = action;
    endpoint.value = `/inventory/supplier-returns/${row.id}/${action}`;
    isGroup.value = false;
    activeGroup.value = null;
    open.value = true;
}
function submit() {
    form.transform((data) => {
        if (operation.value === 'request') {
            return {
                expected_branch_id: data.expected_branch_id,
                retry_key: data.retry_key,
                receipt_lot_id: data.receipt_lot_id,
                unit: data.unit,
                quantity: data.quantity,
                reason: data.reason,
                supplier_reference: data.supplier_reference,
            };
        }

        const common = {
            expected_branch_id: data.expected_branch_id,
            retry_key: data.retry_key,
            lock_version: data.lock_version,
        };

        if (operation.value === 'rejected') {
            return { ...common, reason_code: data.reason_code };
        }

        if (operation.value === 'dispatched') {
            return { ...common, reference: data.reference };
        }

        if (operation.value === 'credit_recorded') {
            return {
                ...common,
                reference: data.reference,
                credit_amount_sen: rmToSen(data.credit_amount_rm),
            };
        }

        return common;
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
        form.reset();
        form.clearErrors();
    },
);

// ---- V2 only -------------------------------------------------------------------------------------------------------
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Supplier returns' },
]);

/** The item's name when the server sends it, else the SKU code. */
const itemOf = (row: { sku_code: string }): string => {
    const value = (row as { item?: unknown }).item;

    return typeof value === 'string' && value !== '' ? value : row.sku_code;
};
const reasonLabel = (reason: string) =>
    supplierReturnReasonLabels[reason] ?? reason;
const stateTone = (state: string) =>
    state === 'credited'
        ? 'success'
        : state === 'rejected' || state === 'cancelled'
          ? 'neutral'
          : state === 'dispatched'
            ? 'info'
            : 'warning';

/** The row the open sheet is about, kept for its words. */
const activeLot = ref<Lot | null>(null);
const activeRow = ref<ReturnRow | null>(null);
const openRequest = (lot: Lot) => {
    activeRow.value = null;
    activeLot.value = lot;
    showRequest(lot);
};
const openAction = (row: ReturnRow, action: SupplierReturnAction) => {
    activeLot.value = null;
    activeRow.value = row;
    showAction(row, action);
};
const openGroupAction = (group: GroupRow, action: SupplierReturnAction) => {
    activeLot.value = null;
    activeRow.value = null;
    showGroupAction(group, action);
};
const closeSheet = (value: boolean) => {
    open.value = value;
};

/** Open first, then Check, then Move, then Danger (page 2 rule); only what the server allows. */
const menuFor = (row: {
    canDecide: boolean;
    canDispatch: boolean;
    canCredit: boolean;
    canCancel: boolean;
}): RowMenuGroup[] => {
    const groups: RowMenuGroup[] = [
        {
            label: 'Open',
            items: [{ id: 'history', label: 'History', allowed: true }],
        },
    ];

    if (row.canDecide) {
        groups.push({
            label: 'Check',
            items: [{ id: 'approved', label: 'Approve', allowed: true }],
        });
    }

    const move = [
        ...(row.canDispatch
            ? [
                  {
                      id: 'dispatched',
                      label: 'Dispatch to supplier',
                      allowed: true,
                  },
              ]
            : []),
        ...(row.canCredit
            ? [
                  {
                      id: 'credit_recorded',
                      label: 'Record credit note',
                      allowed: true,
                  },
              ]
            : []),
    ];

    if (move.length) {
        groups.push({ label: 'Move', items: move });
    }

    const danger = [
        ...(row.canDecide
            ? [{ id: 'rejected', label: 'Reject', allowed: true }]
            : []),
        ...(row.canCancel
            ? [{ id: 'cancelled', label: 'Cancel return', allowed: true }]
            : []),
    ];

    if (danger.length) {
        groups.push({ label: 'Danger', items: danger });
    }

    return groups;
};
const historyGroup = ref<GroupRow | null>(null);
const onRowMenu = (row: ReturnRow, id: string) => {
    if (id === 'history') {
        history.value = row;

        return;
    }

    openAction(row, id as SupplierReturnAction);
};
const onGroupMenu = (group: GroupRow, id: string) => {
    if (id === 'history') {
        historyGroup.value = group;

        return;
    }

    openGroupAction(group, id as SupplierReturnAction);
};

const f = (value: bigint) => trimQuantity(formatTransferThousandths(value));
/** Request: never more than the lot can return (Daniel, page 5). */
const overReturnable = computed(() => {
    const lot = activeLot.value;

    if (operation.value !== 'request' || !lot) {
        return null;
    }

    const asked = transferQuantityThousandths(String(form.quantity));
    const can = transferQuantityThousandths(lot.returnable);

    return asked !== null && can !== null && asked > can
        ? `Only ${quantityWithUnit(f(can), lot.stock_unit)} can be returned from this lot.`
        : null;
});
const reasonOptions = computed(() =>
    props.returns.reasons.map((reason) => ({
        value: reason,
        label: reasonLabel(reason),
    })),
);
const rejectOptions = computed(() =>
    props.returns.rejectReasons.map((reason) => ({
        value: reason,
        label: supplierReturnRejectLabels[reason] ?? reason,
    })),
);

/** What the open sheet is about, in one phrase ("5 tablets of X, batch B" or "3 lots: 2 tablets + 1 bottle"). */
/** The Classic totals ("5.000 unit") in plain words ("5 units"). */
const groupTotalWords = computed(() =>
    groupTotals.value.map((total) => {
        const [value, unit] = total.split(' ');

        return quantityWithUnit(value, unit);
    }),
);
const subject = computed(() => {
    if (isGroup.value && activeGroup.value) {
        return `${activeGroup.value.lines.length} lots (${groupTotalWords.value.join(' + ')})`;
    }

    const row = activeRow.value;

    return row
        ? `${quantityWithUnit(row.quantity, row.base_unit)} of ${itemOf(row)}, batch ${row.batch_number}`
        : '';
});
/** The subject followed by a comma after a batch number, so the sentence reads "batch X, to …". */
const subjectThen = computed(() =>
    isGroup.value ? subject.value : `${subject.value},`,
);
const supplierOf = computed(() =>
    isGroup.value && activeGroup.value
        ? activeGroup.value.supplier
        : (activeRow.value?.supplier ?? activeLot.value?.supplier ?? ''),
);
const requestSentence = computed(() => {
    const lot = activeLot.value;

    if (!lot || !String(form.quantity).trim()) {
        return 'Enter the quantity to see what this asks for.';
    }

    return `Asks a Finance Officer or Director who didn't request it to approve returning ${quantityWithUnit(String(form.quantity).trim(), lot.stock_unit)} of ${itemOf(lot)}, batch ${lot.batch_number}, to ${lot.supplier}. Nothing leaves stock until it is dispatched.`;
});
/** The line by the button: what the press does (Daniel: next to the button, in the footer note). */
const footerNote = computed(() => {
    switch (operation.value) {
        case 'request':
            return requestSentence.value;
        case 'approved':
            return 'Approving lets it be dispatched; stock leaves only then.';
        case 'dispatched':
            return isGroup.value
                ? `Takes ${subjectThen.value} out of this branch's stock now, to ${supplierOf.value}. It can't be undone.`
                : `Takes ${subjectThen.value} out of ${activeRow.value?.location ?? 'this branch'} now, to ${supplierOf.value}. It can't be undone.`;
        case 'credit_recorded':
            return 'Recorded as a reference only. KPOne moves no money and restocks nothing.';
        case 'rejected':
            return `Rejects returning ${subjectThen.value} to ${supplierOf.value}. Nothing leaves stock, and the quantity can be requested again.`;
        case 'cancelled':
            return `Cancels returning ${subjectThen.value} to ${supplierOf.value}. The return is closed without any stock change.`;
        default:
            return '';
    }
});
/** What blocks the press, in words (confirmDisabled). */
const blockedBy = computed(() => {
    if (overReturnable.value) {
        return 'Enter no more than can be returned to continue.';
    }

    if (operation.value === 'request' && !String(form.quantity).trim()) {
        return 'Enter the quantity to continue.';
    }

    if (operation.value === 'rejected' && form.reason_code === '') {
        return 'Choose why you are rejecting it to continue.';
    }

    if (
        (operation.value === 'dispatched' ||
            operation.value === 'credit_recorded') &&
        !form.reference.trim()
    ) {
        return 'Enter the reference to continue.';
    }

    return null;
});
const sheetTitle = computed(() =>
    operation.value === 'request'
        ? 'Request a supplier return'
        : isGroup.value && activeGroup.value
          ? groupTitle(operation.value, activeGroup.value.lines.length)
          : `${supplierReturnActionLabels[operation.value]} ${activeRow.value?.return_number ?? ''}`.trim(),
);
const confirmLabel = computed(() =>
    operation.value === 'request'
        ? 'Send for approval'
        : supplierReturnActionLabels[operation.value],
);

/** Approve and the other actions: what the press is about, as label and value rows. */
const lotRows = computed<KeyValueItem[]>(() => {
    const lot = activeLot.value;

    return lot
        ? [
              { label: 'Item', value: `${itemOf(lot)} · ${lot.sku_code}` },
              {
                  label: 'Batch',
                  value: `${lot.batch_number} (expires ${formatDate(lot.expiry_date)})`,
              },
              { label: 'Location', value: lot.location },
              {
                  label: 'Supplier',
                  value: `${lot.supplier} · delivery ${lot.delivery_reference}`,
              },
              {
                  label: 'Can return',
                  value: quantityWithUnit(lot.returnable, lot.stock_unit),
              },
          ]
        : [];
});
const returnRows = computed<KeyValueItem[]>(() => {
    const row = activeRow.value;

    return row
        ? [
              { label: 'Return', value: row.return_number },
              { label: 'Item', value: `${itemOf(row)} · ${row.sku_code}` },
              {
                  label: 'Batch',
                  value: `${row.batch_number} (expires ${formatDate(row.expiry_date)})`,
              },
              {
                  label: 'Quantity',
                  value: quantityWithUnit(row.quantity, row.base_unit),
              },
              { label: 'Location', value: row.location },
              {
                  label: 'Supplier',
                  value: `${row.supplier} · delivery ${row.delivery_reference}`,
              },
              { label: 'Reason', value: reasonLabel(row.reason) },
              { label: 'Supplier reference', value: row.supplier_reference },
          ]
        : [];
});
const groupRows = computed<KeyValueItem[]>(() => {
    const group = activeGroup.value;

    return isGroup.value && group
        ? [
              {
                  label: 'Return',
                  value: `${group.groupNumber} · ${group.lines.length} lots`,
              },
              {
                  label: 'Supplier',
                  value: `${group.supplier} · delivery ${group.deliveryReference}`,
              },
              { label: 'Total', value: groupTotalWords.value.join(' + ') },
          ]
        : [];
});
const errorList = computed(() =>
    [
        form.errors.quantity,
        form.errors.reason,
        form.errors.supplier_reference,
        errors.value.reason_code,
        form.errors.reference,
        errors.value.credit_amount_sen,
        form.errors.lock_version,
        errors.value.state,
        form.errors.retry_key,
        form.errors.receipt_lot_id,
        form.errors.expected_branch_id,
    ].filter((message): message is string => Boolean(message)),
);
const hasInput = computed(() =>
    ['request', 'dispatched', 'credit_recorded'].includes(operation.value),
);

// Multi-lot: the deliveries as a picker; the chosen delivery's lots show after "Show lots".
const deliveryOptions = computed<PickerOption[]>(() =>
    props.returns.deliveries.map((delivery) => ({
        id: String(delivery.id),
        label: `${delivery.supplier} · delivery ${delivery.delivery_reference}`,
        detail: `${formatDate(delivery.received_at)} · ${delivery.lots} ${delivery.lots === 1 ? 'lot' : 'lots'}`,
    })),
);
const chooseDeliveryValue = (value: string | null) => {
    selectedDelivery.value = value ? Number(value) : '';
};

// At phone width the two ways to request a return stay behind their bars, so the returns needing a person come first.
const wide =
    typeof window === 'undefined' ||
    window.matchMedia('(min-width: 640px)').matches;
</script>

<template>
    <Head title="Supplier returns" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Supplier returns"
            :description="returns.branchName"
        >
            <template #main>
                <p class="text-sm text-muted-foreground">
                    A CA Supervisor requests a return from a verified delivery.
                    A Finance Officer or Director who did not request it
                    approves or rejects it. Stock leaves this branch only when
                    the approved return is dispatched. The credit note is
                    recorded as a reference only: KPOne moves no money and
                    restocks nothing.
                </p>

                <!-- Find: scan or search across every list on this page. -->
                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-label="Find returns and stock"
                >
                    <ScanInput
                        context="search"
                        refocus-key="supplier-returns"
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
                            >Search SKU, batch, supplier, return or delivery
                            reference
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
                </section>

                <!-- Returns and next action: what needs a person comes first. -->
                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-returns"
                >
                    <h2 id="v2-returns" class="v2-section-title">
                        Returns and next action
                    </h2>

                    <template v-if="returns.groups.total > 0">
                        <h3 class="text-sm font-semibold">
                            Multi-lot returns ({{ returns.groups.total }})
                        </h3>
                        <V2Table
                            label="Multi-lot returns"
                            :columns="5"
                            cards="xl"
                            min-width="880px"
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Return</th>
                                    <th scope="col">Lots</th>
                                    <th scope="col">Supplier</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="group in returns.groups.rows"
                                    :key="group.id"
                                    :data-testid="`return-group-${group.id}`"
                                >
                                    <td data-label="Return" data-primary>
                                        <span
                                            class="inline-flex flex-wrap items-center gap-2"
                                        >
                                            <span
                                                class="font-mono text-sm whitespace-nowrap"
                                                >{{ group.groupNumber }}</span
                                            >
                                            <V2Chip
                                                tone="neutral"
                                                :label="`${group.lines.length} ${group.lines.length === 1 ? 'lot' : 'lots'}`"
                                            />
                                        </span>
                                    </td>
                                    <td data-stack data-label="Lots">
                                        <ul
                                            class="grid min-w-0 gap-1 text-sm"
                                            :aria-label="`Lots in ${group.groupNumber}`"
                                        >
                                            <li
                                                v-for="line in group.lines"
                                                :key="line.id"
                                                class="break-words"
                                            >
                                                {{
                                                    quantityWithUnit(
                                                        line.quantity,
                                                        line.base_unit,
                                                    )
                                                }}
                                                of {{ itemOf(line) }}
                                                <span
                                                    class="block text-xs text-muted-foreground"
                                                    >{{ line.sku_code }} · batch
                                                    {{
                                                        unbroken(
                                                            line.batch_number,
                                                        )
                                                    }}
                                                    ·
                                                    {{
                                                        reasonLabel(line.reason)
                                                    }}</span
                                                >
                                            </li>
                                        </ul>
                                    </td>
                                    <td data-stack data-label="Supplier">
                                        <div class="min-w-0 text-sm">
                                            <span class="block break-words">{{
                                                group.supplier
                                            }}</span>
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >Delivery
                                                {{
                                                    group.deliveryReference
                                                }}</span
                                            >
                                        </div>
                                    </td>
                                    <td data-stack data-label="Status">
                                        <div class="min-w-0">
                                            <V2Chip
                                                :tone="stateTone(group.state)"
                                                :label="
                                                    supplierReturnStateLabels[
                                                        group.state
                                                    ] ?? group.state
                                                "
                                            />
                                            <span
                                                v-if="
                                                    group.state ===
                                                        'awaiting_approval' &&
                                                    group.requestedByYou &&
                                                    returns.canApprove
                                                "
                                                class="mt-1 block text-xs text-muted-foreground"
                                                data-testid="own-request"
                                                >You requested this, so someone
                                                else must approve it.</span
                                            >
                                        </div>
                                    </td>
                                    <td data-actions>
                                        <V2RowMenu
                                            :label="`Actions for ${group.groupNumber}`"
                                            :groups="menuFor(group)"
                                            @select="
                                                (id) => onGroupMenu(group, id)
                                            "
                                        />
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
                        <V2Pager
                            :current-page="returns.groups.page"
                            :last-page="returns.groups.lastPage"
                            :total="returns.groups.total"
                            :per-page="10"
                            @change="
                                (next) =>
                                    search(
                                        returns.returns.page,
                                        returns.lots.page,
                                        next,
                                    )
                            "
                        />
                        <h3 class="text-sm font-semibold">
                            Single-lot returns ({{ returns.returns.total }})
                        </h3>
                    </template>

                    <V2Table
                        label="Single-lot returns"
                        :columns="5"
                        cards="xl"
                        min-width="880px"
                        :empty="returns.returns.rows.length === 0"
                        empty-message="No supplier returns yet."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Return</th>
                                <th scope="col">Quantity and item</th>
                                <th scope="col">Supplier</th>
                                <th scope="col">Status</th>
                                <th scope="col">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in returns.returns.rows"
                                :key="row.id"
                            >
                                <td data-label="Return" data-primary>
                                    <span
                                        class="font-mono text-sm whitespace-nowrap"
                                        >{{ row.return_number }}</span
                                    >
                                </td>
                                <td data-stack data-label="Quantity and item">
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm font-medium break-words"
                                            >{{
                                                quantityWithUnit(
                                                    row.quantity,
                                                    row.base_unit,
                                                )
                                            }}
                                            of {{ itemOf(row) }}</span
                                        >
                                        <span
                                            class="block text-xs break-words text-muted-foreground"
                                            >{{ row.sku_code }} · batch
                                            {{ unbroken(row.batch_number) }} ·
                                            {{ row.location }} ·
                                            {{ reasonLabel(row.reason) }}</span
                                        >
                                    </div>
                                </td>
                                <td data-stack data-label="Supplier">
                                    <div class="min-w-0 text-sm">
                                        <span class="block break-words">{{
                                            row.supplier
                                        }}</span>
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >Delivery {{ row.delivery_reference
                                            }}<template
                                                v-if="row.supplier_reference"
                                            >
                                                · supplier ref
                                                {{
                                                    row.supplier_reference
                                                }}</template
                                            ></span
                                        >
                                    </div>
                                </td>
                                <td data-stack data-label="Status">
                                    <div class="min-w-0">
                                        <V2Chip
                                            :tone="stateTone(row.state)"
                                            :label="
                                                supplierReturnStateLabels[
                                                    row.state
                                                ] ?? row.state
                                            "
                                        />
                                        <span
                                            v-if="
                                                row.state ===
                                                    'awaiting_approval' &&
                                                row.requestedByYou &&
                                                returns.canApprove
                                            "
                                            class="mt-1 block text-xs text-muted-foreground"
                                            data-testid="own-request"
                                            >You requested this, so someone else
                                            must approve it.</span
                                        >
                                    </div>
                                </td>
                                <td data-actions>
                                    <V2RowMenu
                                        :label="`Actions for ${row.return_number}`"
                                        :groups="menuFor(row)"
                                        @select="(id) => onRowMenu(row, id)"
                                    />
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="returns.returns.total > 0"
                        :current-page="returns.returns.page"
                        :last-page="returns.returns.lastPage"
                        :total="returns.returns.total"
                        :per-page="25"
                        @change="(next) => search(next, returns.lots.page)"
                    />
                </section>

                <!-- Return one lot. -->
                <V2DisclosureBar
                    v-if="returns.canRequest"
                    :title="`Return one lot (${returns.lots.total})`"
                    :default-open="wide"
                    keep-mounted
                >
                    <div class="flex min-w-0 flex-col gap-3">
                        <V2Table
                            label="Received stock you can return"
                            :columns="5"
                            cards="xl"
                            min-width="880px"
                            :empty="returns.lots.rows.length === 0"
                            empty-message="No returnable stock from verified deliveries at this branch."
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Item</th>
                                    <th scope="col">Batch and expiry</th>
                                    <th scope="col">Supplier</th>
                                    <th scope="col" class="text-right">
                                        Can return
                                    </th>
                                    <th scope="col">
                                        <span class="sr-only">Action</span>
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="lot in returns.lots.rows"
                                    :key="lot.id"
                                >
                                    <td
                                        data-stack
                                        data-label="Item"
                                        data-primary
                                    >
                                        <div class="min-w-0">
                                            <span
                                                class="block text-sm font-medium break-words"
                                                >{{ itemOf(lot) }}</span
                                            >
                                            <span
                                                class="block text-xs break-words text-muted-foreground"
                                                >{{ lot.sku_code }} ·
                                                {{ lot.location }}</span
                                            >
                                        </div>
                                    </td>
                                    <td
                                        data-stack
                                        data-label="Batch and expiry"
                                    >
                                        <div class="min-w-0">
                                            <span
                                                class="block text-sm break-words"
                                                >{{
                                                    unbroken(lot.batch_number)
                                                }}</span
                                            >
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >Expires
                                                {{
                                                    formatDate(lot.expiry_date)
                                                }}</span
                                            >
                                            <V2Chip
                                                v-if="
                                                    lot.outcome ===
                                                    'quarantined'
                                                "
                                                class="mt-1"
                                                tone="warning"
                                                label="Quarantined"
                                            />
                                        </div>
                                    </td>
                                    <td data-stack data-label="Supplier">
                                        <div class="min-w-0 text-sm">
                                            <span class="block break-words">{{
                                                lot.supplier
                                            }}</span>
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >Delivery
                                                {{ lot.delivery_reference }} ·
                                                received
                                                {{
                                                    quantityWithUnit(
                                                        lot.received_quantity,
                                                        lot.stock_unit,
                                                    )
                                                }}</span
                                            >
                                        </div>
                                    </td>
                                    <td
                                        data-stack
                                        data-label="Can return"
                                        class="text-right"
                                    >
                                        <div class="min-w-0 tabular-nums">
                                            <span
                                                class="block text-sm font-medium whitespace-nowrap"
                                                >Up to
                                                {{
                                                    quantityWithUnit(
                                                        lot.returnable,
                                                        lot.stock_unit,
                                                    )
                                                }}</span
                                            >
                                            <span
                                                class="block text-xs whitespace-nowrap text-muted-foreground"
                                                >On hand
                                                {{
                                                    quantityWithUnit(
                                                        lot.on_hand,
                                                        lot.stock_unit,
                                                    )
                                                }}</span
                                            >
                                        </div>
                                    </td>
                                    <td data-actions>
                                        <button
                                            type="button"
                                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9"
                                            :disabled="
                                                lot.returnable === '0.000'
                                            "
                                            :aria-label="`Request return of ${itemOf(lot)}, batch ${lot.batch_number}, to ${lot.supplier}`"
                                            @click="openRequest(lot)"
                                        >
                                            Request return
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
                        <V2Pager
                            :current-page="returns.lots.page"
                            :last-page="returns.lots.lastPage"
                            :total="returns.lots.total"
                            :per-page="25"
                            @change="
                                (next) => search(returns.returns.page, next)
                            "
                        />
                    </div>
                </V2DisclosureBar>

                <!-- Return several lots from one delivery (STK-17). -->
                <V2DisclosureBar
                    v-if="returns.canRequest"
                    title="Return several lots from one delivery"
                    :default-open="wide || returns.delivery !== null"
                    keep-mounted
                >
                    <div class="flex min-w-0 flex-col gap-3">
                        <p class="text-sm text-muted-foreground">
                            Choose a delivery, tick the lots to send back and
                            set each quantity. The lots are approved, dispatched
                            and closed with one credit note together. Up to
                            {{ returns.maxGroupLines }} lots.
                        </p>
                        <div class="flex min-w-0 flex-wrap items-end gap-2">
                            <div
                                class="min-w-0 flex-1 basis-72"
                                data-testid="group-delivery"
                            >
                                <V2Picker
                                    :model-value="
                                        selectedDelivery === ''
                                            ? null
                                            : String(selectedDelivery)
                                    "
                                    label="Delivery"
                                    placeholder="Choose a delivery"
                                    :options="deliveryOptions"
                                    :selected-label="
                                        deliveryOptions.find(
                                            (o) =>
                                                o.id ===
                                                String(selectedDelivery),
                                        )?.label
                                    "
                                    @update:model-value="chooseDeliveryValue"
                                />
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9"
                                :disabled="selectedDelivery === ''"
                                @click="chooseDelivery"
                            >
                                Show lots
                            </button>
                        </div>
                        <form
                            v-if="
                                returns.delivery !== null &&
                                returns.delivery === selectedDelivery &&
                                returns.deliveryLots.length > 0
                            "
                            class="flex min-w-0 flex-col gap-3"
                            aria-labelledby="multi-heading"
                            @submit.prevent="submitGroup"
                        >
                            <h3 id="multi-heading" class="sr-only">
                                Lots from this delivery
                            </h3>
                            <fieldset
                                v-for="lot in returns.deliveryLots"
                                :key="lot.id"
                                class="grid min-w-0 gap-3 rounded-[14px] border p-3 sm:grid-cols-[minmax(0,1fr)_10rem_14rem] sm:items-start"
                                :class="
                                    lotError(lot.id) ? 'border-destructive' : ''
                                "
                            >
                                <legend class="sr-only">
                                    {{ itemOf(lot) }} batch
                                    {{ lot.batch_number }}
                                </legend>
                                <label
                                    class="flex min-h-11 min-w-0 items-start gap-3 text-sm"
                                    :data-testid="`lot-pick-${lot.id}`"
                                    ><input
                                        v-model="drafts[lot.id].include"
                                        type="checkbox"
                                        class="mt-1 size-5 shrink-0"
                                    /><span class="min-w-0 break-words"
                                        ><span class="block font-medium">{{
                                            itemOf(lot)
                                        }}</span>
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{ lot.sku_code }} · batch
                                            {{ unbroken(lot.batch_number) }} ·
                                            expires
                                            {{ formatDate(lot.expiry_date) }} ·
                                            can return
                                            {{
                                                quantityWithUnit(
                                                    lot.returnable,
                                                    lot.stock_unit,
                                                )
                                            }}</span
                                        ></span
                                    ></label
                                >
                                <label
                                    class="flex min-w-0 flex-col gap-1.5 text-sm font-medium"
                                    >Quantity ({{ lot.stock_unit }})
                                    <input
                                        v-model="drafts[lot.id].quantity"
                                        inputmode="decimal"
                                        :disabled="!drafts[lot.id].include"
                                        :aria-invalid="
                                            lotError(lot.id)
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="
                                            lotError(lot.id)
                                                ? `lot-error-${lot.id}`
                                                : undefined
                                        "
                                        class="h-11 w-full min-w-0 rounded-[10px] border bg-card px-3 text-base disabled:opacity-50 md:text-[15px]"
                                        :class="
                                            lotError(lot.id)
                                                ? 'border-destructive'
                                                : 'border-input'
                                        "
                                /></label>
                                <label
                                    class="flex min-w-0 flex-col gap-1.5 text-sm font-medium"
                                    >Reason
                                    <select
                                        v-model="drafts[lot.id].reason"
                                        :disabled="!drafts[lot.id].include"
                                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base disabled:opacity-50 md:text-[15px]"
                                    >
                                        <option
                                            v-for="reason in returns.reasons"
                                            :key="reason"
                                            :value="reason"
                                        >
                                            {{ reasonLabel(reason) }}
                                        </option>
                                    </select>
                                </label>
                                <p
                                    v-if="lotError(lot.id)"
                                    :id="`lot-error-${lot.id}`"
                                    role="alert"
                                    class="text-sm text-destructive sm:col-span-3"
                                >
                                    {{ lotError(lot.id) }}
                                </p>
                            </fieldset>
                            <label
                                class="flex min-w-0 flex-col gap-1.5 text-sm font-medium"
                                >Supplier return authorisation (optional)
                                <input
                                    v-model="groupForm.supplier_reference"
                                    maxlength="100"
                                    class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                            /></label>
                            <p
                                v-if="
                                    groupErrors.supplier_reference ??
                                    groupErrors.lines ??
                                    groupErrors.retry_key ??
                                    groupErrors.goods_receipt_id
                                "
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{
                                    groupErrors.supplier_reference ??
                                    groupErrors.lines ??
                                    groupErrors.retry_key ??
                                    groupErrors.goods_receipt_id
                                }}
                            </p>
                            <p
                                class="text-sm text-muted-foreground"
                                role="status"
                            >
                                {{
                                    chosenCount === 0
                                        ? 'Tick at least one lot to continue.'
                                        : chosenCount > returns.maxGroupLines
                                          ? `Tick no more than ${returns.maxGroupLines} lots to continue.`
                                          : `Asks a Finance Officer or Director who didn't request it to approve returning ${chosenCount} ${chosenCount === 1 ? 'lot' : 'lots'} to the supplier of this delivery. Nothing leaves stock until it is dispatched.`
                                }}
                            </p>
                            <button
                                type="submit"
                                class="inline-flex h-11 w-full items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 sm:w-fit lg:h-9"
                                :disabled="
                                    groupForm.processing ||
                                    chosenCount === 0 ||
                                    chosenCount > returns.maxGroupLines
                                "
                            >
                                Send {{ chosenCount }}
                                {{ chosenCount === 1 ? 'lot' : 'lots' }} for
                                approval
                            </button>
                        </form>
                        <p
                            v-else-if="
                                returns.delivery !== null &&
                                returns.deliveryLots.length === 0
                            "
                            class="text-sm text-muted-foreground"
                        >
                            Nothing from this delivery can be returned now.
                        </p>
                    </div>
                </V2DisclosureBar>
            </template>
        </V2WorkLayout>

        <!-- One sheet for every action; one filled button; what the press does sits in the footer note. -->
        <V2Dialog
            :open="open"
            :title="sheetTitle"
            :confirm-label="confirmLabel"
            :tone="
                operation === 'rejected' || operation === 'cancelled'
                    ? 'danger'
                    : 'default'
            "
            :busy="form.processing"
            :dirty="hasInput && form.isDirty"
            :confirm-disabled="blockedBy !== null"
            :confirm-disabled-reason="blockedBy ?? undefined"
            @update:open="closeSheet"
            @confirm="submit"
        >
            <div
                v-if="errorList.length"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                <p class="font-medium">This was not saved.</p>
                <p v-for="message in errorList" :key="message">{{ message }}</p>
            </div>

            <!-- What the press is about. -->
            <V2KeyValueList v-if="operation === 'request'" :items="lotRows" />
            <template v-else-if="isGroup && activeGroup">
                <V2KeyValueList :items="groupRows" />
                <ul
                    class="grid gap-1 rounded-[14px] border p-3 text-sm"
                    data-testid="group-confirm-lots"
                >
                    <li
                        v-for="line in activeGroup.lines"
                        :key="line.id"
                        class="break-words"
                    >
                        {{ quantityWithUnit(line.quantity, line.base_unit) }} of
                        {{ itemOf(line) }}
                        <span class="block text-xs text-muted-foreground"
                            >{{ line.sku_code }} · batch
                            {{ unbroken(line.batch_number) }} ·
                            {{ reasonLabel(line.reason) }}</span
                        >
                    </li>
                </ul>
            </template>
            <V2KeyValueList v-else :items="returnRows" empty-text="None" />

            <!-- Request. -->
            <template v-if="operation === 'request'">
                <label class="flex flex-col gap-1.5 text-sm font-medium"
                    >Quantity ({{ form.unit }})
                    <input
                        v-model="form.quantity"
                        inputmode="decimal"
                        required
                        :aria-invalid="overReturnable !== null"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                    <span
                        v-if="overReturnable"
                        class="text-sm font-normal text-destructive"
                        >{{ overReturnable }}</span
                    >
                </label>
                <V2OptionGroup
                    :model-value="form.reason"
                    label="Reason"
                    required
                    :options="reasonOptions"
                    @update:model-value="(value) => (form.reason = value)"
                />
                <label class="flex flex-col gap-1.5 text-sm font-medium"
                    >Supplier return authorisation (optional)
                    <input
                        v-model="form.supplier_reference"
                        maxlength="100"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                </label>
            </template>

            <!-- Reject. -->
            <V2OptionGroup
                v-else-if="operation === 'rejected'"
                :model-value="form.reason_code || null"
                label="Why are you rejecting it?"
                required
                :options="rejectOptions"
                @update:model-value="(value) => (form.reason_code = value)"
            />

            <!-- Dispatch and credit note: a reference. -->
            <template
                v-else-if="
                    operation === 'dispatched' ||
                    operation === 'credit_recorded'
                "
            >
                <label class="flex flex-col gap-1.5 text-sm font-medium"
                    >{{
                        operation === 'dispatched'
                            ? 'Return delivery note or courier reference'
                            : 'Supplier credit-note reference'
                    }}
                    <input
                        v-model="form.reference"
                        maxlength="200"
                        required
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                </label>
                <label
                    v-if="operation === 'credit_recorded'"
                    class="flex flex-col gap-1.5 text-sm font-medium"
                    >Credit amount in RM (optional, reference only)
                    <input
                        v-model="form.credit_amount_rm"
                        inputmode="decimal"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                    <span
                        v-if="isGroup"
                        class="text-xs font-normal text-muted-foreground"
                        >One credit note for every lot in this return: enter the
                        total amount.</span
                    >
                </label>
            </template>

            <template #footer-note>
                <p class="text-sm" role="status">{{ footerNote }}</p>
            </template>
        </V2Dialog>

        <!-- History: read only. -->
        <V2Dialog
            :open="history !== null"
            :title="`History of ${history?.return_number ?? ''}`"
            confirm-label="Close"
            single-action
            single-action-style="outline"
            @update:open="(value) => !value && (history = null)"
            @confirm="history = null"
        >
            <ol class="flex flex-col gap-2">
                <li
                    v-for="step in history?.history"
                    :key="step.id"
                    class="rounded-[10px] border px-3 py-2 text-sm"
                >
                    <p class="font-medium">
                        {{
                            supplierReturnHistoryLabels[step.action] ??
                            step.action
                        }}
                        by
                        {{ step.actor_name ?? 'Staff member' }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ step.at_local ?? step.created_at }}
                    </p>
                    <p v-if="step.reason_code" class="text-muted-foreground">
                        {{
                            supplierReturnRejectLabels[step.reason_code] ??
                            step.reason_code
                        }}
                    </p>
                    <p v-if="step.reference" class="text-muted-foreground">
                        Reference {{ step.reference }}
                    </p>
                    <p
                        v-if="step.credit_amount_sen !== null"
                        class="text-muted-foreground"
                    >
                        Credit RM
                        {{ (step.credit_amount_sen / 100).toFixed(2) }}
                        (reference only)
                    </p>
                </li>
            </ol>
        </V2Dialog>
        <V2Dialog
            :open="historyGroup !== null"
            :title="`History of ${historyGroup?.groupNumber ?? ''}`"
            confirm-label="Close"
            single-action
            single-action-style="outline"
            @update:open="(value) => !value && (historyGroup = null)"
            @confirm="historyGroup = null"
        >
            <ol class="flex flex-col gap-2">
                <li
                    v-for="(step, index) in historyGroup?.history"
                    :key="index"
                    class="rounded-[10px] border px-3 py-2 text-sm"
                >
                    <p class="font-medium">
                        {{
                            supplierReturnHistoryLabels[step.action] ??
                            step.action
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatDateTime(step.createdAt) }}
                    </p>
                    <p v-if="step.reasonCode" class="text-muted-foreground">
                        {{
                            supplierReturnRejectLabels[step.reasonCode] ??
                            step.reasonCode
                        }}
                    </p>
                    <p v-if="step.reference" class="text-muted-foreground">
                        Reference {{ step.reference }}
                    </p>
                    <p
                        v-if="step.creditAmountSen !== null"
                        class="text-muted-foreground"
                    >
                        Credit RM
                        {{ (step.creditAmountSen / 100).toFixed(2) }} (reference
                        only)
                    </p>
                </li>
            </ol>
        </V2Dialog>
    </PageFrame>
</template>

<style scoped>
/* Phone and tablet cards: a cell with several lines puts its label on its own line and uses the full width. */
@media (max-width: 1279.98px) {
    :deep(.v2-cards-xl td[data-stack]) {
        flex-direction: column;
        align-items: stretch;
        gap: 0.25rem;
        text-align: left;
    }
}
</style>
