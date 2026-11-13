<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ScanInput from '@/components/inventory/ScanInput.vue';
import { ActionLink } from '@/components/ui/action-link';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageHeader } from '@/components/ui/page-header';
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
const groupHistory = ref<number | null>(null);
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
</script>

<template>
    <Head title="Supplier returns" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Supplier returns"
            :description="returns.branchName"
        />
        <p class="text-sm text-muted-foreground">
            A CA Supervisor requests a return from a verified delivery. A
            Finance Officer or Director who did not request it approves or
            rejects it. Stock leaves this branch only when the approved return
            is dispatched, and a credit note is recorded as a reference only:
            KPOne moves no money and restocks nothing automatically.
        </p>
        <ScanInput
            context="search"
            refocus-key="supplier-returns"
            @found="scanned"
        />
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="search()">
            <label class="min-w-[min(100%,18rem)] flex-1 basis-72"
                >Search SKU, batch, supplier, return or delivery reference<input
                    v-model="q"
                    maxlength="100"
                    type="search"
                    class="mt-1 min-h-11 w-full rounded border bg-background px-3"
            /></label>
            <button
                type="submit"
                class="min-h-11 rounded bg-primary px-4 text-primary-foreground"
            >
                Search
            </button>
        </form>

        <section
            v-if="returns.groups.total > 0"
            class="space-y-3"
            aria-labelledby="groups-heading"
        >
            <h2 id="groups-heading" class="text-lg font-semibold">
                Multi-lot returns ({{ returns.groups.total }})
            </h2>
            <article
                v-for="group in returns.groups.rows"
                :key="group.id"
                class="space-y-2 rounded-lg border p-3"
                :data-testid="`return-group-${group.id}`"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-mono text-sm">{{
                        group.groupNumber
                    }}</span>
                    <span
                        class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium"
                        >{{
                            supplierReturnStateLabels[group.state] ??
                            group.state
                        }}</span
                    >
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ group.supplier }} · delivery
                    {{ group.deliveryReference }} ·
                    {{ group.lines.length }}
                    {{ group.lines.length === 1 ? 'lot' : 'lots' }}, moved
                    together
                </p>
                <ul
                    class="space-y-1 text-sm"
                    :aria-label="`Lots in ${group.groupNumber}`"
                >
                    <li
                        v-for="line in group.lines"
                        :key="line.id"
                        class="rounded border px-2 py-1"
                    >
                        {{ line.quantity }} {{ line.base_unit }} ·
                        {{ line.sku_code }} · batch
                        {{ unbroken(line.batch_number) }}
                        (expires
                        <span class="whitespace-nowrap">{{
                            formatDate(line.expiry_date)
                        }}</span
                        >) ·
                        {{
                            supplierReturnReasonLabels[line.reason] ??
                            line.reason
                        }}
                    </li>
                </ul>
                <p
                    v-if="
                        group.state === 'awaiting_approval' &&
                        group.requestedByYou
                    "
                    class="text-xs text-muted-foreground"
                >
                    You requested this return, so a Finance Officer or Director
                    must approve it.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="group.canDecide"
                        class="min-h-11 rounded bg-primary px-3 text-primary-foreground"
                        @click="showGroupAction(group, 'approved')"
                    >
                        Approve all lots
                    </button>
                    <button
                        v-if="group.canDecide"
                        class="min-h-11 rounded border px-3"
                        @click="showGroupAction(group, 'rejected')"
                    >
                        Reject all lots
                    </button>
                    <button
                        v-if="group.canDispatch"
                        class="min-h-11 rounded bg-primary px-3 text-primary-foreground"
                        @click="showGroupAction(group, 'dispatched')"
                    >
                        Dispatch all lots
                    </button>
                    <button
                        v-if="group.canCredit"
                        class="min-h-11 rounded bg-primary px-3 text-primary-foreground"
                        @click="showGroupAction(group, 'credit_recorded')"
                    >
                        Record credit note
                    </button>
                    <button
                        v-if="group.canCancel"
                        class="min-h-11 rounded border px-3"
                        @click="showGroupAction(group, 'cancelled')"
                    >
                        Cancel
                    </button>
                    <button
                        class="min-h-11 rounded border px-3"
                        @click="
                            groupHistory =
                                groupHistory === group.id ? null : group.id
                        "
                    >
                        {{
                            groupHistory === group.id
                                ? 'Hide history'
                                : 'History'
                        }}
                    </button>
                </div>
                <ol
                    v-if="groupHistory === group.id"
                    class="space-y-1 border-l-2 pl-3 text-xs"
                >
                    <li v-for="(step, index) in group.history" :key="index">
                        {{
                            supplierReturnHistoryLabels[step.action] ??
                            step.action
                        }}
                        · {{ formatDateTime(step.createdAt) }}
                        <span v-if="step.reasonCode">
                            ·
                            {{
                                supplierReturnRejectLabels[step.reasonCode] ??
                                step.reasonCode
                            }}</span
                        >
                        <span v-if="step.reference">
                            · ref {{ step.reference }}</span
                        >
                        <span v-if="step.creditAmountSen !== null">
                            · credit RM
                            {{ (step.creditAmountSen / 100).toFixed(2) }}</span
                        >
                    </li>
                </ol>
            </article>
            <div
                v-if="returns.groups.lastPage > 1"
                class="flex flex-wrap gap-2"
            >
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="returns.groups.page <= 1"
                    @click="
                        search(
                            returns.returns.page,
                            returns.lots.page,
                            returns.groups.page - 1,
                        )
                    "
                >
                    Previous
                </button>
                <span class="self-center text-sm"
                    >Page {{ returns.groups.page }} of
                    {{ returns.groups.lastPage }}</span
                >
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="returns.groups.page >= returns.groups.lastPage"
                    @click="
                        search(
                            returns.returns.page,
                            returns.lots.page,
                            returns.groups.page + 1,
                        )
                    "
                >
                    Next
                </button>
            </div>
        </section>

        <section class="space-y-3" aria-labelledby="returns-heading">
            <h2 id="returns-heading" class="text-lg font-semibold">
                Returns ({{ returns.returns.total }})
            </h2>
            <p
                v-if="returns.returns.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                No supplier returns yet.
            </p>
            <article
                v-for="row in returns.returns.rows"
                :key="row.id"
                class="space-y-2 rounded-lg border p-3"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-mono text-sm">{{
                        row.return_number
                    }}</span>
                    <span
                        class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium"
                        >{{
                            supplierReturnStateLabels[row.state] ?? row.state
                        }}</span
                    >
                </div>
                <p class="text-sm">
                    {{ row.quantity }} {{ row.base_unit }} ·
                    {{ row.sku_code }} · batch {{ row.batch_number }} (expires
                    <span class="whitespace-nowrap">{{
                        formatDate(row.expiry_date)
                    }}</span
                    >) · {{ row.location }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ row.supplier }} · delivery {{ row.delivery_reference }} ·
                    {{ supplierReturnReasonLabels[row.reason] ?? row.reason }}
                    <span v-if="row.supplier_reference">
                        · supplier ref {{ row.supplier_reference }}</span
                    >
                </p>
                <p
                    v-if="
                        row.state === 'awaiting_approval' && row.requestedByYou
                    "
                    class="text-xs text-muted-foreground"
                >
                    You requested this return, so a Finance Officer or Director
                    must approve it.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="row.canDecide"
                        class="min-h-11 rounded bg-primary px-3 text-primary-foreground"
                        @click="showAction(row, 'approved')"
                    >
                        Approve
                    </button>
                    <button
                        v-if="row.canDecide"
                        class="min-h-11 rounded border px-3"
                        @click="showAction(row, 'rejected')"
                    >
                        Reject
                    </button>
                    <button
                        v-if="row.canDispatch"
                        class="min-h-11 rounded bg-primary px-3 text-primary-foreground"
                        @click="showAction(row, 'dispatched')"
                    >
                        Dispatch to supplier
                    </button>
                    <button
                        v-if="row.canCredit"
                        class="min-h-11 rounded bg-primary px-3 text-primary-foreground"
                        @click="showAction(row, 'credit_recorded')"
                    >
                        Record credit note
                    </button>
                    <button
                        v-if="row.canCancel"
                        class="min-h-11 rounded border px-3"
                        @click="showAction(row, 'cancelled')"
                    >
                        Cancel
                    </button>
                    <button
                        class="min-h-11 rounded border px-3"
                        @click="history = history?.id === row.id ? null : row"
                    >
                        {{
                            history?.id === row.id ? 'Hide history' : 'History'
                        }}
                    </button>
                </div>
                <ol
                    v-if="history?.id === row.id"
                    class="space-y-1 border-l-2 pl-3 text-xs"
                >
                    <li v-for="step in row.history" :key="step.id">
                        {{
                            supplierReturnHistoryLabels[step.action] ??
                            step.action
                        }}
                        by {{ step.actor_name ?? 'Staff member' }} ·
                        {{ step.at_local ?? step.created_at }}
                        <span v-if="step.reason_code">
                            ·
                            {{
                                supplierReturnRejectLabels[step.reason_code] ??
                                step.reason_code
                            }}</span
                        >
                        <span v-if="step.reference">
                            · ref {{ step.reference }}</span
                        >
                        <span v-if="step.credit_amount_sen !== null">
                            · credit RM
                            {{
                                (step.credit_amount_sen / 100).toFixed(2)
                            }}</span
                        >
                    </li>
                </ol>
            </article>
            <div
                v-if="returns.returns.lastPage > 1"
                class="flex flex-wrap gap-2"
            >
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="returns.returns.page <= 1"
                    @click="search(returns.returns.page - 1, returns.lots.page)"
                >
                    Previous
                </button>
                <span class="self-center text-sm"
                    >Page {{ returns.returns.page }} of
                    {{ returns.returns.lastPage }}</span
                >
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="returns.returns.page >= returns.returns.lastPage"
                    @click="search(returns.returns.page + 1, returns.lots.page)"
                >
                    Next
                </button>
            </div>
        </section>

        <section
            v-if="returns.canRequest"
            class="space-y-3 rounded-lg border p-3"
            aria-labelledby="multi-heading"
        >
            <h2 id="multi-heading" class="text-lg font-semibold">
                Return several lots from one delivery
            </h2>
            <p class="text-sm text-muted-foreground">
                Choose a delivery, tick the lots to send back and set each
                quantity. The lots are approved, dispatched and closed with one
                credit note together. Up to {{ returns.maxGroupLines }} lots.
            </p>
            <div class="flex flex-wrap items-end gap-2">
                <label
                    class="grid min-w-0 basis-full gap-1 text-sm sm:flex-1 sm:basis-auto"
                    >Delivery<select
                        v-model="selectedDelivery"
                        class="min-h-11 w-full rounded border bg-background px-2"
                        data-testid="group-delivery"
                    >
                        <option value="">Choose a delivery</option>
                        <option
                            v-for="delivery in returns.deliveries"
                            :key="delivery.id"
                            :value="delivery.id"
                        >
                            {{ delivery.supplier }} · delivery
                            {{ delivery.delivery_reference }} ·
                            {{ formatDate(delivery.received_at) }} ·
                            {{ delivery.lots }}
                            {{ delivery.lots === 1 ? 'lot' : 'lots' }}
                        </option>
                    </select></label
                >
                <button
                    type="button"
                    class="min-h-11 rounded border px-4"
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
                class="space-y-3"
                @submit.prevent="submitGroup"
            >
                <fieldset
                    v-for="lot in returns.deliveryLots"
                    :key="lot.id"
                    class="grid items-start gap-2 rounded border p-2 sm:grid-cols-[minmax(0,1fr)_10rem_14rem]"
                >
                    <legend class="sr-only">
                        {{ lot.sku_code }} batch {{ lot.batch_number }}
                    </legend>
                    <label
                        class="-m-2 flex min-h-11 w-[calc(100%+1rem)] cursor-pointer items-center gap-3 p-2 text-sm sm:w-auto"
                        :data-testid="`lot-pick-${lot.id}`"
                        ><input
                            v-model="drafts[lot.id].include"
                            type="checkbox"
                            class="size-5 shrink-0"
                        /><span
                            >{{ lot.sku_code }} · batch
                            {{ unbroken(lot.batch_number) }} (expires
                            <span class="whitespace-nowrap">{{
                                formatDate(lot.expiry_date)
                            }}</span
                            >) · can return {{ lot.returnable }}
                            {{ lot.stock_unit }}</span
                        ></label
                    >
                    <label class="grid gap-1 text-sm"
                        >Quantity ({{ lot.stock_unit }})<input
                            v-model="drafts[lot.id].quantity"
                            inputmode="decimal"
                            :disabled="!drafts[lot.id].include"
                            :aria-invalid="
                                lotError(lot.id) ? 'true' : undefined
                            "
                            :aria-describedby="
                                lotError(lot.id)
                                    ? `lot-error-${lot.id}`
                                    : undefined
                            "
                            :class="[
                                'min-h-11 w-full rounded border bg-background px-3 disabled:opacity-50',
                                lotError(lot.id)
                                    ? 'border-destructive ring-1 ring-destructive'
                                    : '',
                            ]"
                    /></label>
                    <label class="grid gap-1 text-sm"
                        >Reason<select
                            v-model="drafts[lot.id].reason"
                            :disabled="!drafts[lot.id].include"
                            class="min-h-11 w-full rounded border bg-background px-2 disabled:opacity-50"
                        >
                            <option
                                v-for="reason in returns.reasons"
                                :key="reason"
                                :value="reason"
                            >
                                {{
                                    supplierReturnReasonLabels[reason] ?? reason
                                }}
                            </option>
                        </select></label
                    >
                    <InputError
                        :id="`lot-error-${lot.id}`"
                        class="sm:col-span-3"
                        :message="lotError(lot.id)"
                    />
                </fieldset>
                <label class="block text-sm"
                    >Supplier return authorisation (optional)<input
                        v-model="groupForm.supplier_reference"
                        maxlength="100"
                        class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                /></label>
                <InputError
                    :message="
                        groupErrors.supplier_reference ??
                        groupErrors.lines ??
                        groupErrors.retry_key ??
                        groupErrors.goods_receipt_id
                    "
                />
                <button
                    type="submit"
                    class="min-h-11 rounded bg-primary px-4 text-primary-foreground"
                    :disabled="
                        groupForm.processing ||
                        chosenCount === 0 ||
                        chosenCount > returns.maxGroupLines
                    "
                >
                    Send {{ chosenCount }}
                    {{ chosenCount === 1 ? 'lot' : 'lots' }} for approval
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
        </section>

        <section
            v-if="returns.canRequest"
            class="space-y-3"
            aria-labelledby="lots-heading"
        >
            <h2 id="lots-heading" class="text-lg font-semibold">
                Received stock you can return ({{ returns.lots.total }})
            </h2>
            <p
                v-if="returns.lots.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                No returnable stock from verified deliveries at this branch.
            </p>
            <div
                v-for="lot in returns.lots.rows"
                :key="lot.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"
            >
                <span class="min-w-0 flex-1"
                    >{{ lot.sku_code }} · batch {{ lot.batch_number }} (expires
                    <span class="whitespace-nowrap">{{
                        formatDate(lot.expiry_date)
                    }}</span
                    >) · {{ lot.supplier }} · delivery
                    {{ lot.delivery_reference }} · received
                    {{ lot.received_quantity }} {{ lot.stock_unit }}
                    <span v-if="lot.outcome === 'quarantined'">
                        (quarantined)</span
                    >
                    · on hand {{ lot.on_hand }} · can return
                    {{ lot.returnable }}</span
                >
                <button
                    class="min-h-11 rounded bg-primary px-3 text-primary-foreground"
                    :disabled="lot.returnable === '0.000'"
                    @click="showRequest(lot)"
                >
                    Request return
                </button>
            </div>
            <div v-if="returns.lots.lastPage > 1" class="flex flex-wrap gap-2">
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="returns.lots.page <= 1"
                    @click="search(returns.returns.page, returns.lots.page - 1)"
                >
                    Previous
                </button>
                <span class="self-center text-sm"
                    >Page {{ returns.lots.page }} of
                    {{ returns.lots.lastPage }}</span
                >
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="returns.lots.page >= returns.lots.lastPage"
                    @click="search(returns.returns.page, returns.lots.page + 1)"
                >
                    Next
                </button>
            </div>
        </section>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{
                        operation === 'request'
                            ? 'Request supplier return'
                            : isGroup && activeGroup
                              ? groupTitle(operation, activeGroup.lines.length)
                              : supplierReturnActionLabels[operation]
                    }}</DialogTitle>
                    <DialogDescription>{{ label }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="submit">
                    <div
                        v-if="isGroup && activeGroup"
                        class="space-y-1 rounded border p-2 text-sm"
                        data-testid="group-confirm-lots"
                    >
                        <p class="font-medium">
                            This applies to all
                            {{ activeGroup.lines.length }} lots:
                        </p>
                        <ul class="space-y-0.5">
                            <li
                                v-for="line in activeGroup.lines"
                                :key="line.id"
                            >
                                {{ line.quantity }} {{ line.base_unit }} ·
                                {{ line.sku_code }} · batch
                                {{ unbroken(line.batch_number) }}
                            </li>
                        </ul>
                        <p class="font-medium">
                            Total: {{ groupTotals.join(' + ') }}
                        </p>
                    </div>
                    <template v-if="operation === 'request'">
                        <label class="block text-sm"
                            >Quantity ({{ form.unit }})<input
                                v-model="form.quantity"
                                inputmode="decimal"
                                required
                                class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                        /></label>
                        <InputError :message="form.errors.quantity" />
                        <label class="block text-sm"
                            >Reason<select
                                v-model="form.reason"
                                class="mt-1 min-h-11 w-full rounded border bg-background px-2"
                            >
                                <option
                                    v-for="reason in returns.reasons"
                                    :key="reason"
                                    :value="reason"
                                >
                                    {{
                                        supplierReturnReasonLabels[reason] ??
                                        reason
                                    }}
                                </option>
                            </select></label
                        >
                        <InputError :message="form.errors.reason" />
                        <label class="block text-sm"
                            >Supplier return authorisation (optional)<input
                                v-model="form.supplier_reference"
                                maxlength="100"
                                class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                        /></label>
                        <InputError :message="form.errors.supplier_reference" />
                    </template>
                    <template v-else-if="operation === 'rejected'">
                        <label class="block text-sm"
                            >Why are you rejecting it?<select
                                v-model="form.reason_code"
                                class="mt-1 min-h-11 w-full rounded border bg-background px-2"
                                aria-describedby="reject-reason-hint"
                            >
                                <option value="" disabled>
                                    Choose a reason
                                </option>
                                <option
                                    v-for="reason in returns.rejectReasons"
                                    :key="reason"
                                    :value="reason"
                                >
                                    {{
                                        supplierReturnRejectLabels[reason] ??
                                        reason
                                    }}
                                </option>
                            </select></label
                        >
                        <p
                            v-if="form.reason_code === ''"
                            id="reject-reason-hint"
                            class="text-sm text-muted-foreground"
                        >
                            Pick a reason to continue.
                        </p>
                        <InputError :message="form.errors.reason_code" />
                    </template>
                    <template
                        v-else-if="
                            operation === 'dispatched' ||
                            operation === 'credit_recorded'
                        "
                    >
                        <label class="block text-sm"
                            >{{
                                operation === 'dispatched'
                                    ? 'Return delivery note or courier reference'
                                    : 'Supplier credit-note reference'
                            }}<input
                                v-model="form.reference"
                                maxlength="200"
                                required
                                class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                        /></label>
                        <InputError :message="form.errors.reference" />
                        <template v-if="operation === 'credit_recorded'">
                            <label class="block text-sm"
                                >Credit amount in RM (optional, reference
                                only)<input
                                    v-model="form.credit_amount_rm"
                                    inputmode="decimal"
                                    class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                            /></label>
                            <InputError :message="errors.credit_amount_sen" />
                            <p
                                v-if="isGroup"
                                class="text-sm text-muted-foreground"
                            >
                                One credit note for every lot in this return:
                                enter the total amount.
                            </p>
                        </template>
                        <p
                            v-if="operation === 'dispatched'"
                            class="text-sm text-amber-700 dark:text-amber-300"
                        >
                            This takes the quantity out of this branch's stock
                            now. It can't be undone.
                        </p>
                    </template>
                    <p v-else-if="operation === 'cancelled'" class="text-sm">
                        The return is closed without any stock change.
                    </p>
                    <InputError
                        :message="
                            form.errors.lock_version ??
                            errors.state ??
                            form.errors.retry_key ??
                            form.errors.receipt_lot_id ??
                            form.errors.expected_branch_id
                        "
                    />
                    <button
                        type="submit"
                        class="min-h-11 rounded bg-primary px-4 text-primary-foreground"
                        :disabled="
                            form.processing ||
                            (operation === 'rejected' &&
                                form.reason_code === '')
                        "
                    >
                        {{
                            operation === 'request'
                                ? 'Send for approval'
                                : supplierReturnActionLabels[operation]
                        }}
                    </button>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
