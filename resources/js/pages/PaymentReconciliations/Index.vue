<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type PaymentMethod = {
    id: number;
    name: string;
    code: string;
    isActive: boolean;
    requiresReference: boolean;
};
type PaymentTotals = {
    postedCount: number;
    postedTotalSen: number;
    reversedCount: number;
    reversedTotalSen: number;
};
type Reconciliation = {
    publicId: string;
    method: string;
    revision: number;
    businessDate: string;
    terminalSalesCount: number;
    terminalSalesSen: number;
    terminalRefundsCount: number;
    terminalRefundsSen: number;
    terminalVoidsCount: number;
    terminalVoidsSen: number;
    kponePaymentCount: number;
    kponePaymentTotalSen: number;
    paymentCountVariance: number;
    paymentTotalVarianceSen: number;
    terminalBatchReference: string | null;
    varianceReason: string | null;
    notes: string | null;
    reconciledAt: string;
};

const props = defineProps<{
    branch: { name: string; timezone: string };
    branchId: number;
    businessDate: string;
    methods: PaymentMethod[];
    paymentsByMethod: Record<number, PaymentTotals>;
    reconciliations: Reconciliation[];
}>();
const page = usePage();
const pageErrors = computed(
    () => page.props.errors as Record<string, string> | undefined,
);

const form = useForm({
    expected_branch_id: props.branchId,
    payment_method_id:
        props.methods.find((method) => method.isActive)?.id.toString() ?? '',
    business_date: props.businessDate,
    terminal_sales_count: '0',
    terminal_sales_total: '0.00',
    terminal_refunds_count: '0',
    terminal_refunds_total: '0.00',
    terminal_voids_count: '0',
    terminal_voids_total: '0.00',
    terminal_batch_reference: '',
    variance_reason: '',
    notes: '',
    idempotency_key: crypto.randomUUID(),
});

const methodId = computed(() => Number(form.payment_method_id));
const selectedTotals = computed(
    () =>
        props.paymentsByMethod[methodId.value] ?? {
            postedCount: 0,
            postedTotalSen: 0,
            reversedCount: 0,
            reversedTotalSen: 0,
        },
);
const parseSen = (value: string): number | null => {
    if (!/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/.test(value)) {
        return null;
    }

    const [whole, fraction = ''] = value.split('.');

    return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
};
const estimatedDifference = computed(() => {
    const total = parseSen(form.terminal_sales_total);

    return total === null ? null : total - selectedTotals.value.postedTotalSen;
});
const hasDifference = computed(
    () =>
        Number(form.terminal_sales_count) !==
            selectedTotals.value.postedCount || estimatedDifference.value !== 0,
);
const formatMoney = (sen: number) =>
    `RM ${(sen / 100).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const formatDifference = (sen: number) =>
    `${sen > 0 ? '+' : sen < 0 ? '−' : ''}${formatMoney(Math.abs(sen))}`;
const loadDate = () => {
    router.get(
        '/payment-reconciliations',
        { date: form.business_date },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const recordClose = () => {
    form.transform((data) => ({
        ...data,
        payment_method_id: Number(data.payment_method_id),
        terminal_batch_reference: data.terminal_batch_reference.trim() || null,
        variance_reason: data.variance_reason.trim() || null,
        notes: data.notes.trim() || null,
    })).post('/payment-reconciliations', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset(
                'terminal_sales_count',
                'terminal_sales_total',
                'terminal_refunds_count',
                'terminal_refunds_total',
                'terminal_voids_count',
                'terminal_voids_total',
                'terminal_batch_reference',
                'variance_reason',
                'notes',
            );
            form.idempotency_key = crypto.randomUUID();
        },
    });
};
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Terminal Reconciliation"
            description="Record a terminal closing summary and compare approved sales with KPOne receipts."
        />

        <section class="rounded-xl border bg-card p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="font-semibold">{{ branch.name }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Business day uses {{ branch.timezone }}. This records
                        the terminal report only; it does not create or alter
                        KPOne payments.
                    </p>
                </div>
                <form class="flex items-end gap-2" @submit.prevent="loadDate">
                    <label
                        class="grid gap-1 text-sm font-medium"
                        for="report-date"
                    >
                        Business date
                        <Input
                            id="report-date"
                            v-model="form.business_date"
                            type="date"
                        />
                    </label>
                    <Button type="submit" variant="outline">Load day</Button>
                </form>
            </div>
            <InputError class="mt-2" :message="pageErrors?.date" />
        </section>

        <section
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.8fr)]"
        >
            <form
                class="grid gap-5 rounded-xl border bg-card p-4"
                @submit.prevent="recordClose"
            >
                <div>
                    <h2 class="font-semibold">Record terminal close</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Enter the terminal's approved sales totals and
                        transaction counts for this branch, payment method, and
                        business date.
                    </p>
                </div>

                <label
                    class="grid gap-1 text-sm font-medium"
                    for="payment-method"
                >
                    Payment method
                    <select
                        id="payment-method"
                        v-model="form.payment_method_id"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                        required
                    >
                        <option value="" disabled>
                            Select a payment method
                        </option>
                        <option
                            v-for="method in methods"
                            :key="method.id"
                            :value="String(method.id)"
                        >
                            {{ method.name
                            }}{{ method.isActive ? '' : ' (inactive)' }}
                        </option>
                    </select>
                    <span
                        v-if="methods.length === 0"
                        class="text-xs font-normal text-destructive"
                    >
                        No payment methods are configured. Ask a Director or
                        Finance Officer to set them up first.
                    </span>
                    <InputError :message="form.errors.payment_method_id" />
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        class="grid gap-1 text-sm font-medium"
                        for="sales-count"
                    >
                        Approved sales transactions
                        <Input
                            id="sales-count"
                            v-model="form.terminal_sales_count"
                            type="number"
                            min="0"
                            max="1000000"
                            required
                        />
                        <InputError
                            :message="form.errors.terminal_sales_count"
                        />
                    </label>
                    <label
                        class="grid gap-1 text-sm font-medium"
                        for="sales-total"
                    >
                        Approved sales total (RM)
                        <Input
                            id="sales-total"
                            v-model="form.terminal_sales_total"
                            inputmode="decimal"
                            placeholder="0.00"
                            required
                        />
                        <InputError
                            :message="form.errors.terminal_sales_total"
                        />
                    </label>
                </div>

                <div
                    class="grid gap-3 rounded-lg bg-muted/40 p-3 sm:grid-cols-2"
                >
                    <label
                        class="grid gap-1 text-sm font-medium"
                        for="refund-count"
                    >
                        Terminal refunds (count)
                        <Input
                            id="refund-count"
                            v-model="form.terminal_refunds_count"
                            type="number"
                            min="0"
                            max="1000000"
                            required
                        />
                        <InputError
                            :message="form.errors.terminal_refunds_count"
                        />
                    </label>
                    <label
                        class="grid gap-1 text-sm font-medium"
                        for="refund-total"
                    >
                        Terminal refunds (RM)
                        <Input
                            id="refund-total"
                            v-model="form.terminal_refunds_total"
                            inputmode="decimal"
                            required
                        />
                        <InputError
                            :message="form.errors.terminal_refunds_total"
                        />
                    </label>
                    <label
                        class="grid gap-1 text-sm font-medium"
                        for="void-count"
                    >
                        Voided transactions (count)
                        <Input
                            id="void-count"
                            v-model="form.terminal_voids_count"
                            type="number"
                            min="0"
                            max="1000000"
                            required
                        />
                        <InputError
                            :message="form.errors.terminal_voids_count"
                        />
                    </label>
                    <label
                        class="grid gap-1 text-sm font-medium"
                        for="void-total"
                    >
                        Voided transactions (RM)
                        <Input
                            id="void-total"
                            v-model="form.terminal_voids_total"
                            inputmode="decimal"
                            required
                        />
                        <InputError
                            :message="form.errors.terminal_voids_total"
                        />
                    </label>
                    <p class="text-xs text-muted-foreground sm:col-span-2">
                        Refunds and voids are saved as terminal-reported
                        information. They are not subtracted from approved sales
                        and do not trigger a KPOne refund or reversal.
                    </p>
                </div>

                <label
                    class="grid gap-1 text-sm font-medium"
                    for="batch-reference"
                >
                    Terminal batch / close reference (optional)
                    <span class="text-xs font-normal text-muted-foreground">
                        Do not enter patient names or identifiers, cardholder
                        data, full card numbers, credentials, or secrets.
                    </span>
                    <Input
                        id="batch-reference"
                        v-model="form.terminal_batch_reference"
                        maxlength="100"
                    />
                    <InputError
                        :message="form.errors.terminal_batch_reference"
                    />
                </label>

                <div class="rounded-lg border p-3 text-sm">
                    <h3 class="font-medium">
                        KPOne receipts recorded for this method and day
                    </h3>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1">
                        <dt class="text-muted-foreground">Posted receipts</dt>
                        <dd class="text-right tabular-nums">
                            {{ selectedTotals.postedCount }}
                        </dd>
                        <dt class="text-muted-foreground">Posted total</dt>
                        <dd class="text-right tabular-nums">
                            {{ formatMoney(selectedTotals.postedTotalSen) }}
                        </dd>
                        <dt class="text-muted-foreground">
                            Reversed receipts (excluded)
                        </dt>
                        <dd class="text-right tabular-nums">
                            {{ selectedTotals.reversedCount }} ·
                            {{ formatMoney(selectedTotals.reversedTotalSen) }}
                        </dd>
                    </dl>
                    <p
                        v-if="estimatedDifference !== null"
                        class="mt-2 border-t pt-2 font-medium"
                    >
                        Estimated sales difference:
                        <span
                            :class="
                                estimatedDifference === 0
                                    ? 'text-emerald-700 dark:text-emerald-300'
                                    : 'text-amber-700 dark:text-amber-300'
                            "
                        >
                            {{ formatDifference(estimatedDifference) }}
                        </span>
                        <span
                            v-if="
                                Number(form.terminal_sales_count) !==
                                selectedTotals.postedCount
                            "
                            class="block text-xs font-normal text-amber-700 dark:text-amber-300"
                        >
                            Transaction count also differs.
                        </span>
                    </p>
                </div>
                <InputError :message="form.errors.variance_reason" />
                <label
                    v-if="hasDifference"
                    class="grid gap-1 text-sm font-medium"
                    for="variance-reason"
                >
                    Explain the difference (required)
                    <textarea
                        id="variance-reason"
                        v-model="form.variance_reason"
                        maxlength="1000"
                        rows="3"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        required
                    />
                </label>
                <label class="grid gap-1 text-sm font-medium" for="close-notes">
                    Notes (optional)
                    <textarea
                        id="close-notes"
                        v-model="form.notes"
                        maxlength="1000"
                        rows="2"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.notes" />
                </label>
                <InputError :message="pageErrors?.reconciliation" />
                <Button
                    type="submit"
                    :disabled="
                        form.processing ||
                        methods.length === 0 ||
                        !form.payment_method_id
                    "
                >
                    {{ form.processing ? 'Recording…' : 'Record close' }}
                </Button>
            </form>

            <aside
                class="grid content-start gap-4 rounded-xl border bg-card p-4"
            >
                <div>
                    <h2 class="font-semibold">Closing rules</h2>
                    <ul
                        class="mt-3 list-disc space-y-2 pl-5 text-sm text-muted-foreground"
                    >
                        <li>
                            Close the terminal first, then enter its approved
                            sales count and gross sales total here.
                        </li>
                        <li>
                            KPOne compares those values with posted receipts for
                            the same branch, date, and payment method.
                        </li>
                        <li>
                            A mismatch requires an explanation. The close is
                            retained as evidence and is never silently
                            overwritten.
                        </li>
                        <li>
                            To correct a close or record later-entered receipts,
                            submit a new revision. Earlier revisions remain in
                            the history.
                        </li>
                        <li>
                            Continue recording each real customer payment
                            against its invoice in KPOne. Never enter the
                            terminal's summary as a payment.
                        </li>
                    </ul>
                </div>
                <div
                    class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
                >
                    <h3 class="font-medium">Outside this manual close</h3>
                    <p class="mt-1 text-muted-foreground">
                        This does not connect to or refund the terminal, confirm
                        bank settlement, calculate acquirer fees, or create
                        KPOne receipts. Verify refund/void handling and net
                        settlement in the terminal or acquirer report.
                    </p>
                </div>
            </aside>
        </section>

        <section class="rounded-xl border bg-card p-4">
            <h2 class="font-semibold">Close history — {{ businessDate }}</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Latest revisions are shown first. This is summary-level
                evidence; no patient details are displayed.
            </p>
            <div v-if="reconciliations.length" class="mt-4 grid gap-3">
                <article
                    v-for="close in reconciliations"
                    :key="close.publicId"
                    class="rounded-lg border p-3"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h3 class="font-medium">
                            {{ close.method }} · Revision {{ close.revision }}
                        </h3>
                        <span class="text-xs text-muted-foreground">{{
                            close.reconciledAt
                        }}</span>
                    </div>
                    <dl
                        class="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2"
                    >
                        <dt class="text-muted-foreground">
                            Terminal approved sales
                        </dt>
                        <dd class="tabular-nums">
                            {{ close.terminalSalesCount }} ·
                            {{ formatMoney(close.terminalSalesSen) }}
                        </dd>
                        <dt class="text-muted-foreground">
                            KPOne posted receipts
                        </dt>
                        <dd class="tabular-nums">
                            {{ close.kponePaymentCount }} ·
                            {{ formatMoney(close.kponePaymentTotalSen) }}
                        </dd>
                        <dt class="text-muted-foreground">
                            Difference (terminal − KPOne)
                        </dt>
                        <dd class="font-medium tabular-nums">
                            {{ close.paymentCountVariance }} transactions ·
                            {{
                                formatDifference(close.paymentTotalVarianceSen)
                            }}
                        </dd>
                        <dt class="text-muted-foreground">
                            Terminal refunds / voids
                        </dt>
                        <dd class="tabular-nums">
                            {{ close.terminalRefundsCount }} /
                            {{ formatMoney(close.terminalRefundsSen) }} ·
                            {{ close.terminalVoidsCount }} /
                            {{ formatMoney(close.terminalVoidsSen) }}
                        </dd>
                        <dt
                            v-if="close.terminalBatchReference"
                            class="text-muted-foreground"
                        >
                            Batch reference
                        </dt>
                        <dd v-if="close.terminalBatchReference">
                            {{ close.terminalBatchReference }}
                        </dd>
                    </dl>
                    <p v-if="close.varianceReason" class="mt-2 text-sm">
                        <span class="font-medium">Difference explanation:</span>
                        {{ close.varianceReason }}
                    </p>
                    <p
                        v-if="close.notes"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ close.notes }}
                    </p>
                </article>
            </div>
            <p v-else class="mt-3 text-sm text-muted-foreground">
                No terminal closes have been recorded for this date.
            </p>
        </section>
    </main>
</template>
