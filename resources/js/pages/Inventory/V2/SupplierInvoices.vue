<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import type { KeyValueItem } from '@/components/v2/V2KeyValueList.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2MoneyInput from '@/components/v2/V2MoneyInput.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import type { PickerOption } from '@/components/v2/V2Picker.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { trimQuantity } from '@/lib/supplier-terms';

/**
 * Version 2 Supplier invoices (inventory V2 page 6). Everything from `type OrderLine` to `decisionError` is a verbatim
 * copy of Classic (a test compares them). Only the layout and the plain sentences are new: the record form behind a bar,
 * order lines as cards, one table with a row menu, a line-by-line sheet and one Accept or Reject sheet. Amounts appear
 * only when the server sends `showAmounts` (Director, Finance Officer); otherwise no figure reaches the page at all.
 */
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
    quantityMatches: boolean;
    result?:
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
const resultLabel: Record<NonNullable<InvoiceLine['result']>, string> = {
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

/** Whole sen to "RM 1,234.50", with a non-breaking space so RM never wraps from its number. */
const money = (sen: number | null | undefined) => `RM\u00a0${rm(sen)}`;
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
/** "RM 0.99 higher" or "RM 0.50 lower" than expected, for cost holders. */
const difference = (line: InvoiceLine) => {
    if (
        line.invoicedSen === undefined ||
        line.expectedSen === null ||
        line.expectedSen === undefined ||
        line.invoicedSen === line.expectedSen
    ) {
        return '';
    }

    const gap = line.invoicedSen - line.expectedSen;

    return `${money(Math.abs(gap))} ${gap > 0 ? 'higher' : 'lower'}`;
};

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
            // Blank: the recorder copies the quantity from the paper invoice.
            { quantity: '', total: '' },
        ]),
    );
});
// Checked when Record invoice is pressed: messages under each field, focus on the first one (no browser bubbles).
const clientErrors = reactive<Record<string, string>>({});
const fieldErrors = computed<Record<string, string>>(() => ({
    ...errors.value,
    ...Object.fromEntries(
        Object.entries(clientErrors).filter(([, message]) => message),
    ),
}));
const checkRecordForm = () => {
    for (const key of Object.keys(clientErrors)) {
        delete clientErrors[key];
    }

    if (!form.order) {
        clientErrors.purchase_order_public_id = 'Choose the purchase order.';
    }

    if (!form.number.trim()) {
        clientErrors.invoice_number = 'Enter the invoice number.';
    }

    if (!form.date) {
        clientErrors.invoice_date = 'Enter the invoice date.';
    }

    selectedOrder.value?.lines.forEach((line, index) => {
        const entered = form.lines[line.publicId];

        if (!entered?.quantity.trim()) {
            clientErrors[`lines.${index}.quantity`] = 'Enter the quantity.';
        }

        if (!entered?.total.trim()) {
            clientErrors[`lines.${index}.total`] = 'Enter the line total.';
        }
    });

    return Object.keys(clientErrors).length === 0;
};
const focusFirstError = () =>
    void nextTick(() =>
        document
            .querySelector<HTMLElement>(
                '[data-testid="record-invoice"] [aria-invalid="true"]',
            )
            ?.focus(),
    );
const record = () => {
    if (busy.value) {
        return;
    }

    const order = selectedOrder.value;

    if (!checkRecordForm() || !order) {
        focusFirstError();

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
            onError: () => focusFirstError(),
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

    reasonError.value = decision.reason ? '' : 'Pick a reason to continue.';
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

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Supplier invoices' },
]);
/** A code never breaks across lines ("PO-2026-0003" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '‑');
const wide =
    typeof window === 'undefined' ||
    window.matchMedia('(min-width: 640px)').matches;

const tabItems = computed(() =>
    tabs.map((tab) => ({
        value: tab.value,
        label: tab.label,
        count: props.invoices.counts[tab.value],
    })),
);

// Record form.
const orderOptions = computed<PickerOption[]>(() =>
    props.invoices.orders.map((order) => ({
        id: order.publicId,
        label: `${order.number} · ${order.supplier}`,
        detail: `${order.lines.length} ${order.lines.length === 1 ? 'line' : 'lines'}`,
    })),
);
/** Why Record invoice is off, in words; null when it can be pressed. */
const missing = computed<string | null>(() => {
    if (!form.order) {
        return 'Choose the purchase order to continue.';
    }

    if (!form.number.trim()) {
        return 'Enter the invoice number to continue.';
    }

    if (!form.date) {
        return 'Enter the invoice date to continue.';
    }

    for (const line of selectedOrder.value?.lines ?? []) {
        const entered = form.lines[line.publicId];

        if (!entered?.quantity.trim()) {
            return `Enter the invoiced quantity for ${line.item} to continue (0 if it is not on the invoice).`;
        }

        if (!entered?.total.trim()) {
            return `Enter the line total for ${line.item} to continue.`;
        }
    }

    return null;
});
const recordSentence = computed(() => {
    const order = selectedOrder.value;

    if (!order || !form.number.trim()) {
        return missing.value ?? '';
    }

    return `Records invoice ${unbroken(form.number.trim())} for ${unbroken(order.number)} from ${order.supplier}. A line that differs from what was received or what it cost waits for a Finance Officer or Director who didn't record it. KPOne pays nothing.`;
});
const recordDirty = computed(
    () =>
        Boolean(form.order || form.number || form.date || form.total) ||
        Object.values(form.lines).some((line) => line.quantity || line.total),
);

// Invoice rows.
const differing = (invoice: Invoice) =>
    invoice.lines.filter((line) =>
        line.result ? line.result !== 'matches' : !line.quantityMatches,
    ).length;
const lineResult = (line: InvoiceLine) =>
    line.result
        ? resultLabel[line.result]
        : line.quantityMatches
          ? 'Quantity matches'
          : 'Quantity differs';
const lineTone = (line: InvoiceLine) =>
    (line.result ? line.result === 'matches' : line.quantityMatches)
        ? 'success'
        : 'warning';
const canDecide = (invoice: Invoice) =>
    invoice.status === 'waiting' &&
    props.invoices.can.approve &&
    !invoice.recordedByYou;
const ownRecord = (invoice: Invoice) =>
    invoice.status === 'waiting' &&
    props.invoices.can.approve &&
    invoice.recordedByYou;
/** Open first, then Check, then Danger (page 2 rule); only what this person may do. */
const menuFor = (invoice: Invoice): RowMenuGroup[] => [
    {
        label: 'Open',
        items: [{ id: 'lines', label: 'View lines', allowed: true }],
    },
    ...(canDecide(invoice)
        ? [
              {
                  label: 'Check',
                  items: [{ id: 'accepted', label: 'Accept', allowed: true }],
              },
              {
                  label: 'Danger',
                  items: [{ id: 'rejected', label: 'Reject', allowed: true }],
              },
          ]
        : []),
];
const viewing = ref<Invoice | null>(null);
const onMenu = (invoice: Invoice, id: string) => {
    if (id === 'lines') {
        viewing.value = invoice;
    } else if (id === 'accepted' || id === 'rejected') {
        openDecision(invoice, id);
    }
};

// Accept or reject.
const reasonOptions = computed(() =>
    decision.kind === 'accepted' ? acceptReasons : rejectReasons,
);
const decisionRows = computed<KeyValueItem[]>(() => {
    const invoice = decision.invoice;

    if (!invoice) {
        return [];
    }

    const count = differing(invoice);

    return [
        { label: 'Invoice', value: unbroken(invoice.number) },
        { label: 'Supplier', value: invoice.supplier },
        {
            label: 'Order',
            value: `${unbroken(invoice.orderNumber)} · invoice dated ${invoice.invoiceDate}`,
        },
        ...(props.invoices.showAmounts
            ? [{ label: 'Invoice total', value: money(invoice.totalSen) }]
            : []),
        {
            label: 'Lines that differ',
            value: `${count} of ${invoice.lines.length}`,
        },
    ];
});
const decisionSentence = computed(() =>
    decision.kind === 'accepted'
        ? 'The difference is accepted and the invoice is ready to pay outside KPOne. Stock does not change. This cannot be undone.'
        : 'The invoice goes back to the supplier. A corrected copy can be recorded under the same number. Stock does not change.',
);
const decisionBlocked = computed<string | null>(() => {
    if (!decision.reason) {
        return 'Pick a reason to continue.';
    }

    if (decision.reason === 'other' && decision.note.trim() === '') {
        return 'Add a note to explain why.';
    }

    return null;
});
</script>

<template>
    <Head title="Supplier invoices" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2LeaveGuard :dirty="recordDirty" />
        <V2WorkLayout
            title="Supplier invoices"
            :description="invoices.branchName"
        >
            <template #main>
                <p class="text-sm text-muted-foreground">
                    Record the supplier's invoice against its purchase order.
                    KPOne compares each line with what was received and what it
                    cost, and any difference waits for a Finance Officer or
                    Director who did not record it. KPOne pays nothing: a
                    matched or accepted invoice is ready to pay outside KPOne.
                </p>
                <p
                    v-if="!invoices.can.record && !invoices.can.approve"
                    class="rounded-[14px] border p-4 text-sm"
                    data-testid="no-invoice-access"
                >
                    Supplier invoices are recorded by a CA Supervisor or Finance
                    Officer and approved by a Finance Officer or Director.
                </p>
                <div v-else class="flex min-w-0 flex-col gap-6">
                    <!-- Record an invoice: open on wide screens; on a phone it sits closed after the invoices. -->
                    <V2DisclosureBar
                        v-if="invoices.can.record"
                        class="max-sm:order-last"
                        title="Record an invoice"
                        :default-open="wide"
                        keep-mounted
                    >
                        <p
                            v-if="invoices.orders.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No order at this branch has received stock waiting
                            for an invoice.
                        </p>
                        <form
                            v-else
                            class="flex min-w-0 flex-col gap-4"
                            data-testid="record-invoice"
                            novalidate
                            @submit.prevent="record"
                        >
                            <div
                                class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2"
                            >
                                <div data-testid="invoice-order">
                                    <V2Picker
                                        :model-value="
                                            form.order === ''
                                                ? null
                                                : form.order
                                        "
                                        label="Purchase order"
                                        required
                                        placeholder="Choose an order"
                                        :options="orderOptions"
                                        :selected-label="
                                            orderOptions.find(
                                                (o) => o.id === form.order,
                                            )?.label
                                        "
                                        :error="
                                            fieldErrors.purchase_order_public_id
                                        "
                                        @update:model-value="
                                            (value) =>
                                                (form.order = value ?? '')
                                        "
                                    />
                                </div>
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Invoice number"
                                    required
                                    :error="fieldErrors.invoice_number"
                                >
                                    <input
                                        :id="id"
                                        v-model="form.number"
                                        maxlength="60"
                                        :aria-invalid="invalid"
                                        :aria-describedby="describedBy"
                                        class="h-11 w-full min-w-0 rounded-[10px] border bg-card px-3 text-base md:text-[15px] lg:h-9"
                                        :class="
                                            invalid
                                                ? 'border-destructive'
                                                : 'border-input'
                                        "
                                    />
                                </V2Field>
                                <V2DateField
                                    v-model="form.date"
                                    label="Invoice date"
                                    required
                                    :error="fieldErrors.invoice_date"
                                />
                                <V2MoneyInput
                                    v-model="form.total"
                                    label="Invoice total (optional)"
                                    hint="Checked against the sum of the lines."
                                    :error="fieldErrors.stated_total"
                                />
                            </div>
                            <fieldset
                                v-if="selectedOrder"
                                class="flex min-w-0 flex-col gap-3"
                                data-testid="invoice-lines"
                            >
                                <legend class="mb-2 text-sm font-medium">
                                    Each line as on the invoice. Use 0 for a
                                    line that is not on it.
                                </legend>
                                <div
                                    v-for="(line, index) in selectedOrder.lines"
                                    :key="line.publicId"
                                    class="grid min-w-0 gap-3 rounded-[14px] border p-3 md:grid-cols-[minmax(0,1fr)_10rem_12rem] md:items-start"
                                    :class="
                                        fieldErrors[
                                            `lines.${index}.quantity`
                                        ] || fieldErrors[`lines.${index}.total`]
                                            ? 'border-destructive'
                                            : ''
                                    "
                                    :data-testid="`invoice-line-${index}`"
                                >
                                    <p class="min-w-0 text-sm break-words">
                                        <span class="block font-medium">{{
                                            line.item
                                        }}</span>
                                        <span
                                            class="block text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >{{ unbroken(line.sku) }}</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >Received
                                            {{
                                                trimQuantity(
                                                    line.receivedQuantity,
                                                )
                                            }}
                                            of
                                            {{
                                                quantityWithUnit(
                                                    line.orderedQuantity,
                                                    line.stockUnit,
                                                )
                                            }}</span
                                        >
                                    </p>
                                    <V2Field
                                        v-slot="{ id, describedBy, invalid }"
                                        :label="`Invoiced quantity (${line.stockUnit})`"
                                        required
                                        :error="
                                            fieldErrors[
                                                `lines.${index}.quantity`
                                            ]
                                        "
                                    >
                                        <input
                                            :id="id"
                                            v-model="
                                                form.lines[line.publicId]
                                                    .quantity
                                            "
                                            inputmode="decimal"
                                            :aria-invalid="invalid"
                                            :aria-describedby="describedBy"
                                            class="h-11 w-full min-w-0 rounded-[10px] border bg-card px-3 text-right text-base tabular-nums md:text-[15px] lg:h-9"
                                            :class="
                                                invalid
                                                    ? 'border-destructive'
                                                    : 'border-input'
                                            "
                                        />
                                    </V2Field>
                                    <V2MoneyInput
                                        v-model="
                                            form.lines[line.publicId].total
                                        "
                                        label="Line total"
                                        required
                                        :error="
                                            fieldErrors[`lines.${index}.total`]
                                        "
                                    />
                                </div>
                                <p
                                    v-if="fieldErrors.lines"
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    {{ fieldErrors.lines }}
                                </p>
                            </fieldset>
                            <p
                                v-if="
                                    fieldErrors.idempotency_key ??
                                    fieldErrors.expected_branch_id ??
                                    fieldErrors.price
                                "
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{
                                    fieldErrors.idempotency_key ??
                                    fieldErrors.expected_branch_id ??
                                    fieldErrors.price
                                }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Amounts you enter are visible to a Director or
                                Finance Officer only.
                            </p>
                            <p
                                class="text-sm"
                                role="status"
                                data-testid="record-sentence"
                            >
                                {{ recordSentence }}
                            </p>
                            <p
                                v-if="
                                    missing &&
                                    selectedOrder &&
                                    form.number.trim()
                                "
                                class="text-sm text-muted-foreground"
                                data-testid="record-blocked"
                            >
                                {{ missing }}
                            </p>
                            <button
                                type="submit"
                                class="inline-flex h-11 w-full items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 sm:w-fit lg:h-9"
                                :disabled="busy || missing !== null"
                                :aria-describedby="
                                    missing ? undefined : undefined
                                "
                            >
                                {{ busy ? 'Recording…' : 'Record invoice' }}
                            </button>
                        </form>
                    </V2DisclosureBar>

                    <section
                        class="flex min-w-0 flex-col gap-3"
                        aria-labelledby="v2-invoices"
                    >
                        <h2
                            id="v2-invoices"
                            class="v2-section-title text-lg font-semibold"
                        >
                            Invoices
                        </h2>
                        <V2Tabs
                            :model-value="invoices.tab"
                            :tabs="tabItems"
                            ariaLabel="Invoice status"
                            @update:model-value="(value) => visit(value as Tab)"
                        />
                        <p
                            v-if="!invoices.showAmounts"
                            class="text-sm text-muted-foreground"
                            data-testid="amounts-hidden-note"
                        >
                            Invoice amounts are shown only to a Director or
                            Finance Officer.
                        </p>
                        <V2Table
                            label="Supplier invoices"
                            :columns="invoices.showAmounts ? 6 : 5"
                            cards="xl"
                            min-width="880px"
                            :empty="invoices.invoices.rows.length === 0"
                            empty-message="No invoices here."
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Invoice</th>
                                    <th scope="col">Order</th>
                                    <th
                                        v-if="invoices.showAmounts"
                                        scope="col"
                                        class="text-right"
                                    >
                                        Total
                                    </th>
                                    <th scope="col">Lines</th>
                                    <th scope="col" class="w-56">Status</th>
                                    <th scope="col">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="invoice in invoices.invoices.rows"
                                    :key="invoice.publicId"
                                    :data-testid="`invoice-${invoice.number}`"
                                >
                                    <td
                                        data-stack
                                        data-label="Invoice"
                                        data-primary
                                    >
                                        <div class="min-w-0">
                                            <span
                                                class="block font-mono text-sm [overflow-wrap:anywhere]"
                                                >{{
                                                    unbroken(invoice.number)
                                                }}</span
                                            >
                                            <span
                                                class="block text-sm break-words"
                                                >{{ invoice.supplier }}</span
                                            >
                                        </div>
                                    </td>
                                    <td data-stack data-label="Order">
                                        <div class="min-w-0 text-sm">
                                            <span
                                                class="block [overflow-wrap:anywhere]"
                                                >{{
                                                    unbroken(
                                                        invoice.orderNumber,
                                                    )
                                                }}</span
                                            >
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >Invoice dated
                                                {{ invoice.invoiceDate }}</span
                                            >
                                        </div>
                                    </td>
                                    <td
                                        v-if="invoices.showAmounts"
                                        data-label="Total"
                                        class="text-right text-sm whitespace-nowrap tabular-nums"
                                    >
                                        {{ money(invoice.totalSen) }}
                                    </td>
                                    <td data-label="Lines">
                                        <span
                                            class="inline-flex flex-wrap items-center gap-2 text-sm"
                                        >
                                            {{ invoice.lines.length }}
                                            {{
                                                invoice.lines.length === 1
                                                    ? 'line'
                                                    : 'lines'
                                            }}
                                            <V2Chip
                                                class="whitespace-nowrap"
                                                :tone="
                                                    differing(invoice)
                                                        ? 'warning'
                                                        : 'success'
                                                "
                                                :label="
                                                    differing(invoice)
                                                        ? `${differing(invoice)} differs`
                                                        : 'All match'
                                                "
                                            />
                                        </span>
                                    </td>
                                    <td data-stack data-label="Status">
                                        <div class="min-w-0">
                                            <V2Chip
                                                class="whitespace-nowrap"
                                                :tone="
                                                    statusTone[invoice.status]
                                                "
                                                :label="
                                                    statusLabel[invoice.status]
                                                "
                                            />
                                            <span
                                                class="mt-1 block text-xs text-muted-foreground"
                                                >Recorded by
                                                {{ invoice.recordedBy }} on
                                                {{ invoice.recordedOn }}</span
                                            >
                                            <span
                                                v-if="invoice.decidedBy"
                                                class="block text-xs text-muted-foreground"
                                                >{{
                                                    statusLabel[invoice.status]
                                                }}
                                                by {{ invoice.decidedBy }} on
                                                {{ invoice.decidedOn }}:
                                                {{
                                                    reasonLabel(
                                                        invoice.decisionReason,
                                                    )
                                                }}<template
                                                    v-if="invoice.decisionNote"
                                                >
                                                    ·
                                                    {{
                                                        invoice.decisionNote
                                                    }}</template
                                                ></span
                                            >
                                            <span
                                                v-if="ownRecord(invoice)"
                                                class="mt-1 block text-xs text-muted-foreground"
                                                data-testid="own-record"
                                                >You recorded this invoice, so a
                                                different Finance Officer or
                                                Director must decide it.</span
                                            >
                                        </div>
                                    </td>
                                    <td data-actions>
                                        <V2RowMenu
                                            :label="`Actions for invoice ${invoice.number}`"
                                            :groups="menuFor(invoice)"
                                            @select="
                                                (id) => onMenu(invoice, id)
                                            "
                                        />
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
                        <V2Pager
                            v-if="invoices.invoices.total > 0"
                            :current-page="invoices.invoices.page"
                            :last-page="invoices.invoices.lastPage"
                            :total="invoices.invoices.total"
                            :per-page="25"
                            @change="(n) => visit(invoices.tab, n)"
                        />
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <!-- Line by line: read only. -->
        <V2Dialog
            :open="viewing !== null"
            :title="`Lines of invoice ${viewing ? unbroken(viewing.number) : ''}`"
            confirm-label="Close"
            single-action
            single-action-style="outline"
            size="tall"
            @update:open="(value) => !value && (viewing = null)"
            @confirm="viewing = null"
        >
            <ul
                class="flex flex-col gap-2"
                :aria-label="`Lines of invoice ${viewing?.number ?? ''}`"
            >
                <li
                    v-for="(line, index) in viewing?.lines"
                    :key="index"
                    class="flex flex-col gap-1 rounded-[14px] border p-3 text-sm"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <p class="min-w-0 break-words">
                            <span class="block font-medium">{{
                                line.item
                            }}</span>
                            <span
                                class="block text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                >{{ unbroken(line.sku) }}</span
                            >
                        </p>
                        <V2Chip
                            class="whitespace-nowrap"
                            :tone="lineTone(line)"
                            :label="lineResult(line)"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Ordered
                        {{
                            quantityWithUnit(
                                line.orderedQuantity,
                                line.stockUnit,
                            )
                        }}
                        · Received
                        {{
                            quantityWithUnit(
                                line.receivedQuantity,
                                line.stockUnit,
                            )
                        }}
                        · Invoiced
                        {{
                            quantityWithUnit(
                                line.invoicedQuantity,
                                line.stockUnit,
                            )
                        }}
                    </p>
                    <p v-if="invoices.showAmounts" class="text-xs tabular-nums">
                        Invoiced {{ money(line.invoicedSen) }} · Expected
                        {{ money(line.expectedSen) }} ({{
                            basisLabel[line.priceBasis ?? 'none']
                        }})<template v-if="difference(line)">
                            · {{ difference(line) }}</template
                        >
                    </p>
                </li>
            </ul>
        </V2Dialog>

        <!-- Accept or reject: summary first, then the reason; what the press does sits beside the button. -->
        <V2Dialog
            :open="decision.invoice !== null"
            :title="`${decision.kind === 'accepted' ? 'Accept' : 'Reject'} invoice ${decision.invoice ? unbroken(decision.invoice.number) : ''}?`"
            :confirm-label="`${decision.kind === 'accepted' ? 'Accept' : 'Reject'} ${decision.invoice ? unbroken(decision.invoice.number) : ''}`"
            :tone="decision.kind === 'rejected' ? 'danger' : 'default'"
            :busy="busy"
            :dirty="decision.note.trim() !== ''"
            :confirm-disabled="decisionBlocked !== null"
            :confirm-disabled-reason="decisionBlocked ?? undefined"
            @update:open="(value) => !value && (decision.invoice = null)"
            @confirm="submitDecision"
        >
            <div data-testid="decision-form" class="flex flex-col gap-4">
                <p
                    v-if="decisionError"
                    role="alert"
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    {{ decisionError }}
                </p>
                <V2KeyValueList :items="decisionRows" />
                <V2OptionGroup
                    :model-value="decision.reason || null"
                    label="Reason"
                    required
                    :options="reasonOptions"
                    :error="reasonError || undefined"
                    @update:model-value="
                        (value) => {
                            decision.reason = value ?? '';
                            reasonError = '';
                        }
                    "
                />
                <label
                    class="flex flex-col gap-1.5 text-sm font-medium"
                    for="decision-note"
                    >Note{{
                        decision.reason === 'other'
                            ? ' (required)'
                            : ' (optional)'
                    }}
                    <input
                        id="decision-note"
                        v-model="decision.note"
                        maxlength="200"
                        :aria-invalid="noteError !== ''"
                        aria-describedby="decision-note-help"
                        class="h-11 w-full min-w-0 rounded-[10px] border bg-card px-3 text-base md:text-[15px]"
                        :class="
                            noteError ? 'border-destructive' : 'border-input'
                        "
                        @input="noteError = ''"
                    />
                    <span
                        id="decision-note-help"
                        class="text-xs font-normal text-muted-foreground"
                        >Up to 200 characters.</span
                    >
                    <span
                        v-if="noteError"
                        role="alert"
                        class="text-sm font-normal text-destructive"
                        >{{ noteError }}</span
                    >
                </label>
            </div>
            <template #footer-note>
                <p class="text-sm" role="status">{{ decisionSentence }}</p>
            </template>
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
