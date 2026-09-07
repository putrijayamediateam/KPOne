<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';
import { formatDate } from '@/lib/presentation';
import type {
    BillingPage,
    BillingState,
    Receipt,
    Responsibility,
} from '@/types/billing';
import { myr, outstandingSen, toSen } from '@/types/billing';

const props = defineProps<{ billing: BillingPage }>();
const responsibilityTotals: Array<[keyof BillingState, string]> = [
    ['self_pay', 'Self-pay received'],
    ['panel', 'Panel responsibility'],
    ['deferred', 'Approved pay later'],
];
const invoiceTabs = [
    { value: 'all', label: 'All' },
    { value: 'items', label: 'Items' },
    { value: 'services', label: 'Services' },
    { value: 'documents', label: 'Documents' },
];
const page = usePage();
const busy = ref(false);
const contextCleared = ref(false);
const error = ref('');
const tab = ref('all');
const form = reactive({
    amount: '',
    method: '',
    reference: '',
    reason: '',
    due_date: '',
    panel_id: '',
    member_reference: '',
    recording_error_only: false,
});
let paymentKey = crypto.randomUUID();
const clearEntry = () => {
    Object.assign(form, {
        amount: '',
        method: '',
        reference: '',
        reason: '',
        due_date: '',
        panel_id: '',
        member_reference: '',
        recording_error_only: false,
    });
    error.value = '';
    paymentKey = crypto.randomUUID();
};
const stopStart = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        contextCleared.value = true;
        clearEntry();
    }
});
const stopSuccess = router.on('success', (event) => {
    if (event.detail.page.component === 'Billing/Show') {
        contextCleared.value = false;
    }
});
watch(
    () => [
        props.billing.visit.visitNumber,
        page.props.branchContext?.active?.id,
    ],
    clearEntry,
);
onUnmounted(() => {
    stopStart();
    stopSuccess();
});
const base = computed(
    () =>
        `/visits/${encodeURIComponent(props.billing.visit.visitNumber)}/billing`,
);
const invoiceBase = computed(
    () => `${base.value}/${props.billing.invoice?.publicId}`,
);
const isCompleted = computed(() => props.billing.visit.status === 'completed');
const methodOptions = computed(() => [
    { value: '', label: 'Select method' },
    ...props.billing.methods.map((method) => ({
        value: method.code,
        label: method.name,
    })),
]);
const panelOptions = computed(() => [
    { value: '', label: 'Select Panel' },
    ...props.billing.panels.map((panel) => ({
        value: panel.id,
        label: panel.name,
    })),
]);
const lines = computed(
    () =>
        props.billing.invoice?.lines.filter(
            (l) =>
                tab.value === 'all' ||
                (tab.value === 'items'
                    ? l.type === 'medicine'
                    : l.type !== 'medicine'),
        ) ?? [],
);
const post = (
    url: string,
    extra: Record<string, string | number | boolean | null> = {},
) => {
    busy.value = true;
    error.value = '';
    router.post(
        url,
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            lock_version: props.billing.invoice?.lockVersion ?? null,
            ...extra,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The request could not be completed.';
            },
            onSuccess: () => {
                paymentKey = crypto.randomUUID();
                form.amount = '';
                form.reference = '';
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
const payment = () => {
    const sen = toSen(form.amount);

    if (sen === null) {
        error.value =
            'Enter an exact MYR amount with at most two decimal places.';

        return;
    }

    post(`${invoiceBase.value}/payments`, {
        amount_sen: sen,
        method: form.method,
        reference: form.reference || null,
        idempotency_key: paymentKey,
    });
};
const propose = (kind: string) => {
    const sen = toSen(form.amount);

    if (sen === null) {
        error.value = 'Enter an exact MYR amount.';

        return;
    }

    post(`${invoiceBase.value}/responsibility/${kind}`, {
        amount_sen: sen,
        reason: form.reason,
        due_date: form.due_date || null,
        panel_id: form.panel_id || null,
        member_reference: form.member_reference || null,
    });
};
const approve = (kind: string, proposal: Responsibility) =>
    post(
        `${invoiceBase.value}/responsibility/${kind}/${proposal.publicId}/approve`,
        { proposal_lock_version: proposal.lockVersion },
    );
const reverse = (receipt: Receipt) =>
    post(`${invoiceBase.value}/payments/${receipt.publicId}/reverse`, {
        payment_lock_version: receipt.lockVersion,
        reason: form.reason,
        recording_error_only: form.recording_error_only,
    });
const complete = () => {
    if (
        window.confirm(
            'Complete Visitation? This preserves the finalized financial evidence and closes ordinary Visit editing.',
        )
    ) {
        post(`${base.value}/complete`, {
            visit_lock_version: props.billing.visit.lockVersion,
        });
    }
};
</script>

<template>
    <Head title="Patient Billing" />
    <main
        v-if="contextCleared"
        class="mx-auto max-w-3xl space-y-3 p-6 text-sm"
        role="status"
    >
        <p>Billing context cleared.</p>
        <ActionLink href="/workspace" variant="secondary">
            Return to Workspace
        </ActionLink>
    </main>
    <main
        v-else
        class="mx-auto w-full max-w-[1600px] space-y-4 px-4 py-6 text-sm"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <p class="text-xs font-medium text-muted-foreground uppercase">
                    {{ billing.branch }}
                </p>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ isCompleted ? 'Completed Visit' : 'Patient Billing' }}
                </h1>
                <div class="flex flex-wrap items-center gap-2">
                    <StatusBadge :status="billing.visit.status" />
                    <span
                        v-if="isCompleted"
                        class="text-xs text-muted-foreground"
                    >
                        Read-only Visit record
                    </span>
                </div>
            </div>
            <ActionLink href="/registration" variant="secondary">
                Registration
            </ActionLink>
        </div>
        <p
            v-if="error"
            role="alert"
            class="mb-3 rounded border border-red-200 bg-red-50 p-2 text-red-800"
        >
            {{ error }}
        </p>
        <div
            class="grid min-w-0 gap-4 lg:grid-cols-[minmax(220px,0.8fr)_minmax(0,2.2fr)] xl:grid-cols-[minmax(220px,0.8fr)_minmax(0,2.5fr)_minmax(260px,1.4fr)]"
        >
            <aside class="self-start rounded-xl border bg-card p-4">
                <p class="text-xs font-medium text-muted-foreground uppercase">
                    Patient / Visit
                </p>
                <h2 class="mt-2 text-lg font-semibold">
                    {{ billing.patient.name }}
                </h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Patient No.
                        </dt>
                        <dd class="font-mono">
                            {{ billing.patient.patientNumber }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Visit No.</dt>
                        <dd class="font-mono">
                            {{ billing.visit.visitNumber }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Branch</dt>
                        <dd>{{ billing.branch }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Coverage</dt>
                        <dd>{{ billing.visit.coverage }}</dd>
                    </div>
                    <div v-if="billing.visit.completedAt">
                        <dt class="text-xs text-muted-foreground">Completed</dt>
                        <dd>{{ billing.visit.completedAt }}</dd>
                    </div>
                </dl>
                <p class="text-xs text-muted-foreground">
                    Registration coverage is not financial approval.
                </p>
                <div
                    v-if="billing.oldOutstanding.length"
                    class="mt-4 border-t pt-4"
                >
                    <h3 class="font-semibold">Earlier outstanding</h3>
                    <div
                        v-for="debt in billing.oldOutstanding"
                        :key="debt.invoiceNumber"
                        class="mt-3 space-y-1 rounded-lg border bg-muted/20 p-3"
                    >
                        <p class="font-medium tabular-nums">
                            {{ myr(debt.amountSen) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ debt.invoiceNumber }} · Due
                            {{ formatDate(debt.dueDate) }}
                        </p>
                        <ActionLink :href="debt.url" variant="ghost" size="sm">
                            Settle Outstanding Balance
                        </ActionLink>
                    </div>
                </div>
            </aside>
            <section class="min-w-0">
                <OperationalTabs
                    v-model="tab"
                    :tabs="invoiceTabs"
                    label="Invoice content"
                    class="mb-3"
                />
                <template v-if="tab !== 'documents'"
                    ><p
                        v-if="!billing.invoice"
                        class="rounded-xl border border-dashed bg-card px-4 py-8 text-center text-muted-foreground"
                    >
                        Awaiting Billing. Build the draft from completed
                        fulfilment and confirmed services. No stock is moved
                        here.
                    </p>
                    <OperationalTable
                        v-else
                        label="Invoice charges"
                        :columns="4"
                        :empty="lines.length === 0"
                        empty-message="No itemized lines in this authorized view."
                        min-width="640px"
                    >
                        <template #head>
                            <tr>
                                <th class="py-2">Charge</th>
                                <th>Quantity</th>
                                <th class="text-right">Unit price</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="(line, index) in lines" :key="index">
                                <td>
                                    {{ line.name
                                    }}<span
                                        class="block text-xs text-muted-foreground"
                                        >{{ line.type }}</span
                                    >
                                </td>
                                <td>{{ line.quantity }} {{ line.unit }}</td>
                                <td class="text-right tabular-nums">
                                    {{ myr(line.unitPriceSen) }}
                                </td>
                                <td class="text-right font-medium tabular-nums">
                                    {{ myr(line.totalSen) }}
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                </template>
                <div v-else class="space-y-3 rounded-xl border bg-card p-4">
                    <Button
                        v-if="billing.can.print"
                        as-child
                        variant="secondary"
                        size="sm"
                    >
                        <a
                            :href="`${invoiceBase}/print`"
                            target="_blank"
                            rel="noopener"
                        >
                            Print Invoice
                        </a>
                    </Button>
                    <div
                        v-for="receipt in billing.payments"
                        :key="receipt.publicId"
                        class="flex flex-wrap items-center justify-between gap-2 border-t pt-3"
                    >
                        <div>
                            <p class="font-medium tabular-nums">
                                {{ receipt.number }} ·
                                {{ myr(receipt.amountSen) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ receipt.method }} · {{ receipt.receivedAt }}
                            </p>
                            <StatusBadge
                                class="mt-1"
                                :status="receipt.status"
                            />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="billing.can.print"
                                as-child
                                variant="ghost"
                                size="sm"
                            >
                                <a
                                    :href="`${invoiceBase}/receipts/${receipt.publicId}/print`"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    Print Receipt
                                </a>
                            </Button>
                            <Button
                                v-if="
                                    billing.can.reverse &&
                                    receipt.status === 'posted'
                                "
                                variant="secondary"
                                size="sm"
                                :disabled="
                                    busy ||
                                    !form.recording_error_only ||
                                    !form.reason
                                "
                                @click="reverse(receipt)"
                            >
                                Reverse recording error
                            </Button>
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Button
                        v-if="billing.can.build"
                        variant="secondary"
                        size="sm"
                        :disabled="busy"
                        @click="post(`${base}/build`)"
                        >Build / refresh draft</Button
                    ><Button
                        v-if="billing.can.finalize"
                        size="sm"
                        :disabled="busy"
                        @click="post(`${invoiceBase}/finalize`)"
                        >Finalize Invoice</Button
                    >
                </div>
            </section>
            <aside
                class="self-start rounded-xl border bg-card p-4 lg:col-span-2 xl:col-span-1"
            >
                <template v-if="billing.invoice"
                    ><div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Financial summary
                            </p>
                            <h2 class="font-semibold">
                                {{ billing.invoice.number ?? 'Draft Invoice' }}
                            </h2>
                        </div>
                        <StatusBadge :status="billing.invoice.status" />
                    </div>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div
                            class="flex items-center justify-between border-b pb-3"
                        >
                            <dt class="font-medium">Invoice Total</dt>
                            <dd class="text-base font-semibold tabular-nums">
                                {{ myr(billing.invoice.state.total) }}
                            </dd>
                        </div>
                        <div
                            v-for="[key, label] in responsibilityTotals"
                            :key="key"
                            class="flex items-center justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">{{ label }}</dt>
                            <dd class="text-right tabular-nums">
                                {{ myr(billing.invoice.state[key]) }}
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between gap-4 border-t pt-3"
                        >
                            <dt class="font-medium">Outstanding</dt>
                            <dd class="text-right font-medium tabular-nums">
                                {{ myr(outstandingSen(billing.invoice.state)) }}
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between gap-4 rounded-lg bg-muted px-3 py-2"
                        >
                            <dt class="font-semibold">Due Now</dt>
                            <dd class="text-lg font-semibold tabular-nums">
                                {{ myr(billing.invoice.state.due_now) }}
                            </dd>
                        </div>
                    </dl>
                    <p v-if="billing.invoice.correctionHold" role="status">
                        Correction hold — review required.
                    </p></template
                >
                <div
                    v-if="
                        billing.can.pay ||
                        billing.can.panelPropose ||
                        billing.can.deferPropose
                    "
                    class="space-y-2 border-t pt-3"
                >
                    <div v-if="billing.can.pay">
                        <h3 class="text-sm font-semibold">
                            {{
                                isCompleted
                                    ? 'Outstanding Receivable'
                                    : 'Record Payment'
                            }}
                        </h3>
                        <p
                            v-if="isCompleted"
                            class="text-xs text-muted-foreground"
                        >
                            This governed settlement is separate from the
                            completed Visit record.
                        </p>
                    </div>
                    <label class="block"
                        >Amount (MYR)<input
                            v-model="form.amount"
                            inputmode="decimal"
                            class="billing-input"
                            autocomplete="off"
                    /></label>
                    <template v-if="billing.can.pay"
                        ><label
                            id="payment-method-label"
                            class="block"
                            for="payment-method"
                            >Payment method</label
                        >
                        <OperationalSelect
                            id="payment-method"
                            labelledby="payment-method-label"
                            v-model="form.method"
                            :options="methodOptions"
                            label="Payment method"
                            placeholder="Select method"
                        />
                        <label class="block"
                            >Transaction reference (no card details)<input
                                v-model="form.reference"
                                class="billing-input"
                                maxlength="100"
                                autocomplete="off" /></label
                        ><Button
                            size="sm"
                            :variant="
                                !isCompleted && billing.can.complete
                                    ? 'secondary'
                                    : 'default'
                            "
                            :disabled="busy || !form.method"
                            @click="payment"
                            >{{
                                isCompleted
                                    ? 'Settle Outstanding Balance'
                                    : 'Add Payment'
                            }}</Button
                        ></template
                    >
                    <details
                        v-if="
                            billing.can.panelPropose || billing.can.deferPropose
                        "
                        class="border-t pt-2"
                    >
                        <summary class="cursor-pointer py-1">
                            Panel / pay later request
                        </summary>
                        <label class="block"
                            >Reason / verification evidence<textarea
                                v-model="form.reason"
                                maxlength="500"
                                class="billing-input"
                            /></label
                        ><template v-if="billing.can.panelPropose"
                            ><label
                                id="verified-panel-label"
                                class="block"
                                for="verified-panel"
                                >Verified Panel</label
                            >
                            <OperationalSelect
                                id="verified-panel"
                                labelledby="verified-panel-label"
                                v-model="form.panel_id"
                                :options="panelOptions"
                                label="Verified Panel"
                                placeholder="Select Panel"
                            />
                            <label class="block"
                                >Member reference (if required)<input
                                    v-model="form.member_reference"
                                    class="billing-input"
                                    maxlength="100" /></label
                            ><Button
                                variant="secondary"
                                size="sm"
                                :disabled="busy"
                                @click="propose('panel')"
                                >Propose Panel responsibility</Button
                            ></template
                        ><template v-if="billing.can.deferPropose"
                            ><label class="mt-2 block"
                                >Pay later due date<input
                                    v-model="form.due_date"
                                    type="date"
                                    class="billing-input" /></label
                            ><Button
                                variant="secondary"
                                size="sm"
                                :disabled="busy"
                                @click="propose('deferment')"
                                >Request pay later</Button
                            ></template
                        >
                    </details>
                </div>
                <div
                    v-for="kind in ['panel', 'deferment'] as const"
                    :key="kind"
                >
                    <template v-if="billing[kind]"
                        ><div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-medium">
                                {{ kind === 'panel' ? 'Panel' : 'Pay later' }}
                                · {{ myr(billing[kind]!.amountSen) }}
                            </p>
                            <StatusBadge :status="billing[kind]!.status" />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ billing[kind]!.reason }}
                        </p>
                        <p v-if="billing[kind]!.panelName" class="text-xs">
                            {{ billing[kind]!.panelName
                            }}<span v-if="billing[kind]!.memberReference">
                                · {{ billing[kind]!.memberReference }}</span
                            >
                        </p>
                        <p v-if="billing[kind]!.dueDate" class="text-xs">
                            Due {{ formatDate(billing[kind]!.dueDate) }}
                        </p>
                        <Button
                            v-if="
                                billing[kind]!.status === 'proposed' &&
                                (kind === 'panel'
                                    ? billing.can.panelApprove
                                    : billing.can.deferApprove)
                            "
                            variant="secondary"
                            size="sm"
                            :disabled="busy"
                            @click="approve(kind, billing[kind]!)"
                            >Independently approve</Button
                        ></template
                    >
                </div>
                <details v-if="billing.can.reverse || billing.can.void">
                    <summary class="cursor-pointer">
                        Governed correction
                    </summary>
                    <p class="text-xs">
                        Recording errors only. No refunds or post-completion
                        accounting correction.
                    </p>
                    <label class="block"
                        >Reason<textarea
                            v-model="form.reason"
                            class="billing-input"
                            maxlength="500"
                        /></label
                    ><label class="flex gap-2"
                        ><input
                            v-model="form.recording_error_only"
                            type="checkbox"
                        />No real refund: recording error only</label
                    ><Button
                        v-if="billing.can.void"
                        variant="destructive"
                        size="sm"
                        :disabled="busy || !form.reason"
                        @click="
                            post(`${invoiceBase}/void`, { reason: form.reason })
                        "
                        >Void for reissue</Button
                    >
                </details>
                <Button
                    v-if="billing.can.complete"
                    class="w-full"
                    :disabled="busy"
                    @click="complete"
                    >Complete Visitation</Button
                >
                <p class="text-xs text-muted-foreground">
                    Billing never deducts stock. Panel and approved pay later
                    are not cash receipts.
                </p>
            </aside>
        </div>
    </main>
</template>

<style scoped>
.billing-input {
    display: block;
    width: 100%;
    margin-block: 0.25rem 0.5rem;
    padding: 0.4rem 0.5rem;
    min-height: 2.25rem;
    border: 1px solid var(--input);
    border-radius: var(--radius-control);
    background: var(--card);
    font: inherit;
}
.billing-input:hover:not(:disabled) {
    border-color: color-mix(in oklab, var(--foreground) 30%, var(--input));
}
.billing-input:disabled,
.billing-input[readonly] {
    cursor: not-allowed;
    background: var(--muted);
    color: var(--muted-foreground);
}
.billing-input:is(textarea) {
    min-height: 5rem;
}
.billing-input:focus-visible {
    border-color: var(--brand);
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
</style>
