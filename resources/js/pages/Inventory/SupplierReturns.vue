<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import { formatDate } from '@/lib/presentation';
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
    reason_code: string | null;
    reference: string | null;
    credit_amount_sen: number | null;
    lock_version: number;
    created_at: string;
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
    };
}>();

const q = ref(props.returns.q);
const open = ref(false);
const label = ref('');
const endpoint = ref('');
const operation = ref<'request' | SupplierReturnAction>('request');
const history = ref<ReturnRow | null>(null);
const form = useForm({
    expected_branch_id: props.returns.branchId,
    retry_key: '',
    lock_version: 0,
    receipt_lot_id: 0,
    unit: '',
    quantity: '',
    reason: 'damaged',
    supplier_reference: '',
    reason_code: 'not_agreed_by_supplier',
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
function search(page = 1, lots = 1) {
    router.get(
        '/inventory/supplier-returns',
        { q: q.value, page, lots_page: lots },
        { preserveScroll: true },
    );
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
        <PageHeader title="Supplier returns" :description="returns.branchName">
            <template #actions>
                <ActionLink href="/inventory" variant="ghost" class="min-h-11"
                    ><ArrowLeft class="size-4" aria-hidden="true" />
                    Inventory</ActionLink
                >
            </template>
        </PageHeader>
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
                        by staff #{{ step.actor_user_id }} ·
                        {{ step.created_at }}
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
                            : supplierReturnActionLabels[operation]
                    }}</DialogTitle>
                    <DialogDescription>{{ label }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="submit">
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
                            >
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
                        </template>
                        <p
                            v-if="operation === 'dispatched'"
                            class="text-sm text-amber-700 dark:text-amber-300"
                        >
                            This takes the quantity out of this branch's stock
                            now.
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
                        :disabled="form.processing"
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
