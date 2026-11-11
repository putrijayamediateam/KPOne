<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status';
import { trimQuantity } from '@/lib/supplier-terms';

type OrderLine = {
    publicId: string;
    item: string;
    sku: string;
    stockUnit: string;
    orderedQuantity: string;
    receivedQuantity: string;
};
type Order = {
    publicId: string;
    number: string;
    status: string;
    supplier: string;
    lines: OrderLine[];
};
type InvoiceLine = {
    item: string;
    sku: string;
    stockUnit: string;
    result:
        | 'matches'
        | 'quantity_differs'
        | 'price_differs'
        | 'both_differ'
        | 'price_unknown';
    orderedQuantity: string;
    receivedQuantity: string;
    invoicedQuantity: string;
    invoicedSen?: number;
    expectedSen?: number | null;
    priceBasis?: 'receipt' | 'estimate' | 'none';
};
type Invoice = {
    publicId: string;
    number: string;
    invoiceDate: string;
    orderNumber: string;
    supplier: string;
    status: Tab;
    lockVersion: number;
    recordedBy: string;
    recordedOn: string;
    recordedByYou: boolean;
    decidedBy: string | null;
    decidedOn: string | null;
    decisionReason: string | null;
    decisionNote: string | null;
    totalSen?: number;
    lines: InvoiceLine[];
};
type Tab = 'waiting' | 'matched' | 'accepted' | 'rejected';

const props = defineProps<{
    invoices: {
        expectedBranchId: number;
        branchName: string;
        tab: Tab;
        showAmounts: boolean;
        counts: Record<Tab, number>;
        can: { record: boolean; approve: boolean };
        invoices: {
            page: number;
            lastPage: number;
            total: number;
            rows: Invoice[];
        };
        orders: Order[];
    };
}>();

const tabs: { value: Tab; label: string }[] = [
    { value: 'waiting', label: 'Waiting for approval' },
    { value: 'matched', label: 'Matched' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'rejected', label: 'Rejected' },
];
const statusLabel: Record<Tab, string> = {
    waiting: 'Waiting for approval',
    matched: 'Matched',
    accepted: 'Accepted',
    rejected: 'Rejected',
};
const statusTone: Record<Tab, 'warning' | 'success' | 'neutral' | 'danger'> = {
    waiting: 'warning',
    matched: 'success',
    accepted: 'success',
    rejected: 'danger',
};
const resultLabel: Record<InvoiceLine['result'], string> = {
    matches: 'Matches',
    quantity_differs: 'Quantity differs',
    price_differs: 'Price differs',
    both_differ: 'Quantity and price differ',
    price_unknown: 'No cost to compare',
};
const basisLabel = {
    receipt: 'cost recorded at receipt',
    estimate: 'order estimate',
    none: 'no cost recorded',
};
const acceptReasons = [
    { value: 'price_agreed', label: 'Price agreed with supplier' },
    { value: 'credit_note_expected', label: 'Credit note expected' },
    { value: 'rounding', label: 'Rounding' },
    { value: 'other', label: 'Other' },
];
const rejectReasons = [
    { value: 'wrong_quantity', label: 'Wrong quantity' },
    { value: 'wrong_price', label: 'Wrong price' },
    { value: 'not_our_order', label: 'Not our order' },
    { value: 'other', label: 'Other' },
];
const reasonLabel = (value: string | null) =>
    [...acceptReasons, ...rejectReasons].find((r) => r.value === value)
        ?.label ?? '';

/** Whole sen to "1,234.50". */
const rm = (sen: number | null | undefined) => {
    if (sen === null || sen === undefined) {
        return '—';
    }

    const ringgit = String(Math.trunc(sen / 100)).replace(
        /\B(?=(\d{3})+(?!\d))/g,
        ',',
    );

    return `${ringgit}.${String(sen % 100).padStart(2, '0')}`;
};
const quantity = (value: string, unit: string) =>
    `${trimQuantity(value)} ${unit}`;

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const busy = ref(false);

const visit = (tab: Tab, pageNumber = 1) =>
    router.get(
        '/inventory/supplier-invoices',
        { tab, page: pageNumber },
        { preserveScroll: true, preserveState: true },
    );

// Recording an invoice.
const form = reactive({
    order: '',
    number: '',
    date: '',
    total: '',
    key: crypto.randomUUID(),
    lines: {} as Record<string, { quantity: string; total: string }>,
});
const selectedOrder = computed(() =>
    props.invoices.orders.find((order) => order.publicId === form.order),
);
watch(selectedOrder, (order) => {
    form.lines = Object.fromEntries(
        (order?.lines ?? []).map((line) => [
            line.publicId,
            { quantity: trimQuantity(line.receivedQuantity), total: '' },
        ]),
    );
});
const record = () => {
    const order = selectedOrder.value;

    if (!order || busy.value) {
        return;
    }

    router.post(
        '/inventory/supplier-invoices',
        {
            expected_branch_id: props.invoices.expectedBranchId,
            idempotency_key: form.key,
            purchase_order_public_id: order.publicId,
            invoice_number: form.number,
            invoice_date: form.date,
            stated_total: form.total,
            lines: order.lines.map((line) => ({
                line_public_id: line.publicId,
                quantity: form.lines[line.publicId]?.quantity ?? '',
                total: form.lines[line.publicId]?.total ?? '',
            })),
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () =>
                Object.assign(form, {
                    order: '',
                    number: '',
                    date: '',
                    total: '',
                    key: crypto.randomUUID(),
                }),
        },
    );
};

// Accepting or rejecting a mismatch.
const decision = reactive<{
    invoice: Invoice | null;
    kind: 'accepted' | 'rejected';
    reason: string;
    note: string;
}>({ invoice: null, kind: 'accepted', reason: '', note: '' });
const reasonError = ref('');
const noteError = ref('');
const openDecision = (invoice: Invoice, kind: 'accepted' | 'rejected') => {
    Object.assign(decision, { invoice, kind, reason: '', note: '' });
    reasonError.value = '';
    noteError.value = '';
};
const submitDecision = () => {
    const invoice = decision.invoice;

    if (!invoice) {
        return;
    }

    reasonError.value = decision.reason ? '' : 'Choose a reason.';
    noteError.value =
        decision.reason === 'other' && decision.note.trim() === ''
            ? 'Add a note to explain why.'
            : '';

    if (reasonError.value || noteError.value) {
        void nextTick(() =>
            document
                .getElementById(
                    reasonError.value ? 'decision-reason' : 'decision-note',
                )
                ?.focus(),
        );

        return;
    }

    router.post(
        `/inventory/supplier-invoices/${invoice.publicId}/${decision.kind}`,
        {
            expected_branch_id: props.invoices.expectedBranchId,
            lock_version: invoice.lockVersion,
            reason: decision.reason,
            note: decision.note.trim(),
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (decision.invoice = null),
        },
    );
};
const decisionError = computed(() =>
    decision.invoice
        ? (errors.value.status ??
          errors.value.lock_version ??
          errors.value.reason ??
          errors.value.note ??
          '')
        : '',
);
</script>

<template>
    <Head title="Supplier invoices" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Supplier invoices"
            :description="invoices.branchName"
        />
        <p class="text-sm text-muted-foreground">
            Record the supplier's invoice against its purchase order. KPOne
            compares each line with what was received and what it cost, and any
            difference waits for a Finance Officer or Director who did not
            record it. KPOne pays nothing: a matched or accepted invoice is
            ready to pay outside KPOne.
        </p>

        <p
            v-if="!invoices.can.record && !invoices.can.approve"
            class="rounded-lg border bg-muted/40 p-3 text-sm"
            data-testid="no-invoice-access"
        >
            Supplier invoices are recorded by a CA Supervisor or Finance Officer
            and approved by a Finance Officer or Director.
        </p>

        <template v-else>
            <section
                v-if="invoices.can.record"
                class="space-y-3 rounded-lg border bg-card p-4"
                aria-labelledby="record-heading"
            >
                <h2 id="record-heading" class="text-lg font-semibold">
                    Record an invoice
                </h2>
                <p
                    v-if="invoices.orders.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No order at this branch has received stock waiting for an
                    invoice.
                </p>
                <form
                    v-else
                    class="space-y-3"
                    data-testid="record-invoice"
                    @submit.prevent="record"
                >
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="grid gap-1 text-sm" for="invoice-order"
                            >Purchase order (required)
                            <select
                                id="invoice-order"
                                v-model="form.order"
                                required
                                class="h-11 rounded-md border bg-background px-3 lg:h-9"
                                :aria-invalid="
                                    !!errors.purchase_order_public_id
                                "
                                aria-describedby="invoice-order-error"
                            >
                                <option value="" disabled>
                                    Choose an order
                                </option>
                                <option
                                    v-for="order in invoices.orders"
                                    :key="order.publicId"
                                    :value="order.publicId"
                                >
                                    {{ order.number }} · {{ order.supplier }}
                                </option>
                            </select>
                            <InputError
                                id="invoice-order-error"
                                :message="errors.purchase_order_public_id"
                            />
                        </label>
                        <label class="grid gap-1 text-sm" for="invoice-number"
                            >Invoice number (required)
                            <Input
                                id="invoice-number"
                                v-model="form.number"
                                required
                                maxlength="60"
                                class="h-11 lg:h-9"
                                :aria-invalid="!!errors.invoice_number"
                                aria-describedby="invoice-number-error"
                            />
                            <InputError
                                id="invoice-number-error"
                                :message="errors.invoice_number"
                            />
                        </label>
                        <div class="grid gap-1 text-sm">
                            <span id="invoice-date-label"
                                >Invoice date (required)</span
                            >
                            <DateField
                                v-model="form.date"
                                aria-labelledby="invoice-date-label"
                                calendar-label="Invoice date"
                            />
                            <InputError :message="errors.invoice_date" />
                        </div>
                        <label class="grid gap-1 text-sm" for="invoice-total"
                            >Invoice total, RM (optional)
                            <Input
                                id="invoice-total"
                                v-model="form.total"
                                inputmode="decimal"
                                maxlength="20"
                                class="h-11 lg:h-9"
                                :aria-invalid="!!errors.stated_total"
                                aria-describedby="invoice-total-help invoice-total-error"
                            />
                            <span
                                id="invoice-total-help"
                                class="text-xs text-muted-foreground"
                                >Checked against the sum of the lines.</span
                            >
                            <InputError
                                id="invoice-total-error"
                                :message="errors.stated_total"
                            />
                        </label>
                    </div>
                    <fieldset
                        v-if="selectedOrder"
                        class="space-y-2"
                        data-testid="invoice-lines"
                    >
                        <legend class="text-sm font-medium">
                            Each line as on the invoice. Use 0 for a line that
                            is not on it.
                        </legend>
                        <div
                            v-for="(line, index) in selectedOrder.lines"
                            :key="line.publicId"
                            class="grid gap-2 rounded-lg border p-3 md:grid-cols-[1fr_10rem_10rem]"
                        >
                            <p class="text-sm">
                                <span class="font-medium">{{ line.item }}</span>
                                <span class="text-muted-foreground">
                                    · {{ line.sku }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >Received
                                    {{
                                        quantity(
                                            line.receivedQuantity,
                                            line.stockUnit,
                                        )
                                    }}
                                    of
                                    {{
                                        quantity(
                                            line.orderedQuantity,
                                            line.stockUnit,
                                        )
                                    }}</span
                                >
                            </p>
                            <label class="grid gap-1 text-sm"
                                >Invoiced quantity ({{ line.stockUnit }})
                                <Input
                                    v-model="form.lines[line.publicId].quantity"
                                    inputmode="decimal"
                                    required
                                    class="h-11 lg:h-9"
                                    :aria-invalid="
                                        !!errors[`lines.${index}.quantity`]
                                    "
                                />
                                <InputError
                                    :message="errors[`lines.${index}.quantity`]"
                                />
                            </label>
                            <label class="grid gap-1 text-sm"
                                >Line total, RM
                                <Input
                                    v-model="form.lines[line.publicId].total"
                                    inputmode="decimal"
                                    required
                                    class="h-11 lg:h-9"
                                    :aria-invalid="
                                        !!errors[`lines.${index}.total`]
                                    "
                                />
                                <InputError
                                    :message="errors[`lines.${index}.total`]"
                                />
                            </label>
                        </div>
                        <InputError :message="errors.lines" />
                    </fieldset>
                    <InputError
                        :message="errors.idempotency_key ?? errors.quantity"
                    />
                    <Button
                        type="submit"
                        class="h-11 lg:h-9"
                        :disabled="busy || !selectedOrder"
                        >Record invoice</Button
                    >
                </form>
            </section>

            <nav
                aria-label="Invoice status"
                class="flex flex-wrap gap-2"
                data-testid="invoice-tabs"
            >
                <Button
                    v-for="tab in tabs"
                    :key="tab.value"
                    :variant="
                        tab.value === invoices.tab ? 'default' : 'outline'
                    "
                    class="h-11 lg:h-9"
                    :aria-current="
                        tab.value === invoices.tab ? 'page' : undefined
                    "
                    @click="visit(tab.value)"
                    >{{ tab.label }} ({{ invoices.counts[tab.value] }})</Button
                >
            </nav>
            <p
                v-if="!invoices.showAmounts"
                class="text-sm text-muted-foreground"
                data-testid="amounts-hidden-note"
            >
                Invoice amounts are shown only to a Director or Finance Officer.
            </p>

            <p
                v-if="invoices.invoices.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                No invoices here.
            </p>
            <ul v-else class="space-y-3" aria-label="Supplier invoices">
                <li
                    v-for="invoice in invoices.invoices.rows"
                    :key="invoice.publicId"
                    class="space-y-3 rounded-lg border bg-card p-4"
                    :data-testid="`invoice-${invoice.number}`"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <p class="font-medium break-words">
                                Invoice {{ invoice.number }} ·
                                {{ invoice.supplier }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ invoice.invoiceDate }} · Order
                                {{ invoice.orderNumber }}
                                <template v-if="invoices.showAmounts">
                                    · RM {{ rm(invoice.totalSen) }}</template
                                >
                            </p>
                        </div>
                        <StatusBadge
                            :status="invoice.status"
                            :label="statusLabel[invoice.status]"
                            :tone="statusTone[invoice.status]"
                        />
                    </div>
                    <ul
                        class="space-y-2"
                        :aria-label="`Lines of invoice ${invoice.number}`"
                    >
                        <li
                            v-for="(line, index) in invoice.lines"
                            :key="index"
                            class="rounded-md border p-2 text-sm"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <p class="min-w-0">
                                    <span class="font-medium">{{
                                        line.item
                                    }}</span>
                                    <span class="text-muted-foreground">
                                        · {{ line.sku }}</span
                                    >
                                </p>
                                <StatusBadge
                                    :status="line.result"
                                    :label="resultLabel[line.result]"
                                    :tone="
                                        line.result === 'matches'
                                            ? 'success'
                                            : 'warning'
                                    "
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Ordered
                                {{
                                    quantity(
                                        line.orderedQuantity,
                                        line.stockUnit,
                                    )
                                }}
                                · Received
                                {{
                                    quantity(
                                        line.receivedQuantity,
                                        line.stockUnit,
                                    )
                                }}
                                · Invoiced
                                {{
                                    quantity(
                                        line.invoicedQuantity,
                                        line.stockUnit,
                                    )
                                }}
                            </p>
                            <p
                                v-if="invoices.showAmounts"
                                class="text-xs text-muted-foreground"
                            >
                                Invoiced RM {{ rm(line.invoicedSen) }} ·
                                Expected RM {{ rm(line.expectedSen) }} ({{
                                    basisLabel[line.priceBasis ?? 'none']
                                }})
                            </p>
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Recorded by {{ invoice.recordedBy }} on
                        {{ invoice.recordedOn }}
                        <template v-if="invoice.decidedBy">
                            · {{ statusLabel[invoice.status] }} by
                            {{ invoice.decidedBy }} on {{ invoice.decidedOn }}:
                            {{ reasonLabel(invoice.decisionReason)
                            }}<template v-if="invoice.decisionNote">
                                · {{ invoice.decisionNote }}</template
                            ></template
                        >
                    </p>
                    <div
                        v-if="
                            invoice.status === 'waiting' && invoices.can.approve
                        "
                        class="flex flex-wrap gap-2"
                    >
                        <template v-if="!invoice.recordedByYou">
                            <Button
                                class="min-h-11 lg:min-h-9"
                                @click="openDecision(invoice, 'accepted')"
                                >Accept</Button
                            >
                            <Button
                                variant="outline"
                                class="min-h-11 lg:min-h-9"
                                @click="openDecision(invoice, 'rejected')"
                                >Reject</Button
                            >
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            You recorded this invoice, so a different Finance
                            Officer or Director must decide it.
                        </p>
                    </div>
                </li>
            </ul>
            <CompactPagination
                v-if="invoices.invoices.total > 0"
                :current-page="invoices.invoices.page"
                :last-page="invoices.invoices.lastPage"
                :total="invoices.invoices.total"
                @change="(n) => visit(invoices.tab, n)"
            />
        </template>

        <OperationalConfirmDialog
            :open="decision.invoice !== null"
            :title="`${decision.kind === 'accepted' ? 'Accept' : 'Reject'} invoice ${decision.invoice?.number ?? ''}?`"
            :description="
                decision.kind === 'accepted'
                    ? 'The difference is accepted and the invoice is ready to pay outside KPOne. Stock does not change.'
                    : 'The invoice goes back to the supplier. A corrected copy can be recorded under the same number. Stock does not change.'
            "
            :confirm-label="
                decision.kind === 'accepted'
                    ? 'Accept invoice'
                    : 'Reject invoice'
            "
            :destructive="decision.kind === 'rejected'"
            :processing="busy"
            :error="decisionError"
            @update:open="(open) => !open && (decision.invoice = null)"
            @confirm="submitDecision"
        >
            <div class="space-y-3" data-testid="decision-form">
                <fieldset
                    class="space-y-1"
                    :aria-invalid="reasonError !== ''"
                    :aria-describedby="
                        reasonError ? 'decision-reason-error' : undefined
                    "
                >
                    <legend class="mb-1 text-sm font-medium">
                        Reason (required)
                    </legend>
                    <label
                        v-for="(reason, index) in decision.kind === 'accepted'
                            ? acceptReasons
                            : rejectReasons"
                        :key="reason.value"
                        class="flex min-h-11 items-center gap-2 text-sm lg:min-h-9"
                    >
                        <input
                            :id="index === 0 ? 'decision-reason' : undefined"
                            v-model="decision.reason"
                            type="radio"
                            name="decision-reason"
                            :value="reason.value"
                            required
                            class="size-4"
                            @change="reasonError = ''"
                        />
                        {{ reason.label }}
                    </label>
                    <p
                        v-if="reasonError"
                        id="decision-reason-error"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ reasonError }}
                    </p>
                </fieldset>
                <label class="grid gap-1 text-sm" for="decision-note">
                    <span
                        >Note{{
                            decision.reason === 'other'
                                ? ' (required)'
                                : ' (optional)'
                        }}</span
                    >
                    <Input
                        id="decision-note"
                        v-model="decision.note"
                        maxlength="200"
                        :required="decision.reason === 'other'"
                        :aria-invalid="noteError !== ''"
                        :aria-describedby="
                            noteError
                                ? 'decision-note-error decision-note-help'
                                : 'decision-note-help'
                        "
                        class="h-11 lg:h-9"
                        @input="noteError = ''"
                    />
                    <span
                        id="decision-note-help"
                        class="text-xs text-muted-foreground"
                        >Up to 200 characters.</span
                    >
                    <span
                        v-if="noteError"
                        id="decision-note-error"
                        role="alert"
                        class="text-sm text-destructive"
                        >{{ noteError }}</span
                    >
                </label>
            </div>
        </OperationalConfirmDialog>
    </main>
</template>
