<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, UserRound } from '@lucide/vue';
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import BillingChargeRows from '@/components/billing/BillingChargeRows.vue';
import BillingCompletionDialog from '@/components/billing/BillingCompletionDialog.vue';
import InvoiceActivityDrawer from '@/components/billing/InvoiceActivityDrawer.vue';
import InvoiceAdjustmentDialog from '@/components/billing/InvoiceAdjustmentDialog.vue';
import OutstandingBills from '@/components/billing/OutstandingBills.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';
import { formatDate } from '@/lib/presentation';
import type {
    BillingPage,
    BillingState,
    Receipt,
    Responsibility,
} from '@/types/billing';
import { myr, outstandingSen, toSen } from '@/types/billing';

const props = withDefaults(
    defineProps<{ billing: BillingPage; manage?: boolean }>(),
    { manage: false },
);
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
const completionOpen = ref(false);
const paymentOpen = ref(false);
const responsibilityOpen = ref(false);
const responsibilityKind = ref<'panel' | 'deferment'>('panel');
const reversalOpen = ref(false);
const reversalReceipt = ref<Receipt | null>(null);
const closeDialogs = () => {
    paymentOpen.value = false;
    responsibilityOpen.value = false;
    reversalOpen.value = false;
};
const updateDialog = (
    kind: 'payment' | 'responsibility' | 'reversal',
    open: boolean,
) => {
    if (busy.value) {
        return;
    }

    if (kind === 'payment') {
        paymentOpen.value = open;
    }

    if (kind === 'responsibility') {
        responsibilityOpen.value = open;
    }

    if (kind === 'reversal') {
        reversalOpen.value = open;
    }
};
const openEntry = (kind: 'payment' | 'panel' | 'deferment') => {
    if (busy.value || contextCleared.value) {
        return;
    }

    if (
        kind === 'payment'
            ? !props.billing.can.pay
            : kind === 'panel'
              ? !props.billing.can.panelPropose
              : !props.billing.can.deferPropose
    ) {
        return;
    }

    error.value = '';

    if (kind === 'payment') {
        paymentOpen.value = true;
    } else {
        responsibilityKind.value = kind;
        responsibilityOpen.value = true;
    }
};
const openReversal = (receipt: Receipt) => {
    if (
        busy.value ||
        !props.billing.can.reverse ||
        receipt.status !== 'posted'
    ) {
        return;
    }

    error.value = '';
    reversalReceipt.value = receipt;
    reversalOpen.value = true;
};
const error = ref('');
const tab = ref('all');
const form = reactive({
    amount: '',
    responsibility_amount: '',
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
        responsibility_amount: '',
        method: '',
        reference: '',
        reason: '',
        due_date: '',
        panel_id: '',
        member_reference: '',
        recording_error_only: false,
    });
    error.value = '';
    closeDialogs();
    reversalReceipt.value = null;
    paymentKey = crypto.randomUUID();
};
const stopStart = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        contextCleared.value = true;
        clearEntry();
    }
});
const stopSuccess = router.on('success', (event) => {
    if (
        ['Billing/Show', 'Billing/Payments'].includes(
            event.detail.page.component,
        )
    ) {
        contextCleared.value = false;
    }
});
watch(
    [
        () => props.billing.visit.visitNumber,
        () => page.props.branchContext?.active?.id,
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
// The exact amount due now as a plain MYR figure, for the "Use amount due" shortcuts.
const dueNowText = computed(() =>
    props.billing.invoice
        ? (props.billing.invoice.state.due_now / 100).toFixed(2)
        : '',
);
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
    if (busy.value || contextCleared.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    router.post(
        url,
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            lock_version: props.billing.invoice?.lockVersion ?? null,
            return_to: props.manage ? 'payments' : 'billing',
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
                clearEntry();
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
    const sen = toSen(form.responsibility_amount);

    if (sen === null) {
        error.value =
            'Enter the amount the Panel or pay later will cover, in MYR with at most two decimal places.';

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
</script>

<template>
    <Head :title="manage ? 'Payments' : 'Patient Billing'" />
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
        class="billing-workspace flex w-full flex-1 flex-col bg-muted/40 text-[13px]"
        data-testid="active-billing"
    >
        <header
            class="flex flex-wrap items-center gap-2 border-b bg-card px-4 py-3"
        >
            <ActionLink v-if="manage" :href="base" variant="outline"
                ><ArrowLeft class="size-3.5" />Billing</ActionLink
            >
            <ActionLink
                v-else-if="billing.backLink"
                :href="billing.backLink.href"
                variant="outline"
            >
                <ArrowLeft class="size-3.5" />{{ billing.backLink.label }}
            </ActionLink>
            <h1 class="text-base font-semibold">
                {{
                    manage
                        ? 'Payments'
                        : isCompleted
                          ? 'Completed Visit'
                          : 'Patient Billing'
                }}
            </h1>
            <span v-if="manage" class="text-xs text-muted-foreground">{{
                billing.invoice?.number ?? 'No invoice'
            }}</span>
            <StatusBadge :status="billing.visit.status" />
            <InvoiceActivityDrawer v-if="!manage" :context="billing.activity" />
            <span v-if="isCompleted" class="text-xs text-muted-foreground"
                >Read-only Visit record</span
            >
            <span class="ml-auto text-[11px] text-muted-foreground"
                >{{ billing.branch }} · {{ billing.visit.visitNumber }}</span
            >
            <ActionLink v-if="manage" :href="base" variant="outline"
                >Done</ActionLink
            >
        </header>
        <p
            v-if="error"
            role="alert"
            class="m-3 rounded border border-destructive/30 bg-destructive/5 p-3 text-destructive"
        >
            {{ error }}
        </p>
        <div
            :class="
                manage
                    ? 'mx-auto grid w-full max-w-[1440px] gap-3 p-4'
                    : 'grid min-w-0 items-start gap-3 p-3 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1.3fr)_minmax(0,.8fr)]'
            "
            data-testid="billing-columns"
        >
            <aside v-if="!manage" class="grid min-w-0 gap-3">
                <section class="overflow-hidden rounded-xl border bg-card">
                    <div class="flex items-start gap-3 p-4">
                        <div
                            class="flex h-14 w-12 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                        >
                            <UserRound class="size-6" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold break-words">
                                {{ billing.patient.name }}
                            </h2>
                            <div class="mt-1 text-xs text-muted-foreground">
                                {{ billing.patient.patientNumber }}
                            </div>
                        </div>
                        <ActionLink
                            v-if="billing.patient.profileUrl"
                            :href="billing.patient.profileUrl"
                            variant="outline"
                            >View profile</ActionLink
                        >
                    </div>
                    <div class="border-t p-4">
                        <h3 class="font-medium text-muted-foreground">
                            Patient / Visit
                        </h3>
                        <dl class="mt-3 space-y-2 text-xs">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Visit No.
                                </dt>
                                <dd class="font-mono">
                                    {{ billing.visit.visitNumber }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Branch
                                </dt>
                                <dd>{{ billing.branch }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Coverage
                                </dt>
                                <dd>{{ billing.visit.coverage }}</dd>
                            </div>
                            <div v-if="billing.visit.completedAt">
                                <dt class="text-xs text-muted-foreground">
                                    Completed
                                </dt>
                                <dd>{{ billing.visit.completedAt }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-xs text-muted-foreground">
                            Registration coverage is not financial approval.
                        </p>
                    </div>
                </section>
                <OutstandingBills :groups="billing.outstanding" />
            </aside>
            <section
                v-if="!manage"
                class="min-w-0 overflow-hidden rounded-xl border bg-card"
            >
                <OperationalTabs
                    v-model="tab"
                    :tabs="invoiceTabs"
                    label="Invoice content"
                    class="p-3"
                />
                <template v-if="tab !== 'documents'"
                    ><p
                        v-if="!billing.invoice"
                        class="px-4 py-6 text-xs text-muted-foreground"
                    >
                        Awaiting Billing. Build the draft from completed
                        fulfilment and confirmed services. No stock is moved
                        here.
                    </p>
                    <BillingChargeRows v-else :lines="lines" />
                </template>
                <div v-else class="space-y-3 p-4">
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
                                :disabled="busy"
                                @click="openReversal(receipt)"
                            >
                                Reverse recording error
                            </Button>
                        </div>
                    </div>
                </div>
                <div
                    v-if="billing.can.build || billing.can.finalize"
                    class="flex flex-wrap gap-2 border-t p-3"
                >
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
                class="min-w-0 space-y-3 rounded-xl border bg-card p-4"
                data-testid="billing-financial"
            >
                <div
                    v-if="manage"
                    class="flex flex-wrap items-start justify-between gap-3 border-b pb-3"
                >
                    <div>
                        <h2 class="text-sm font-semibold">
                            {{ billing.patient.name }}
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ billing.patient.patientNumber }} ·
                            {{ billing.visit.visitNumber }}
                        </p>
                    </div>
                    <div class="text-xs sm:ml-auto">
                        <p class="text-muted-foreground">Coverage</p>
                        <p class="mt-1">{{ billing.visit.coverage }}</p>
                    </div>
                    <dl
                        v-if="billing.invoice"
                        class="ml-auto text-right sm:ml-8"
                    >
                        <dt class="text-xs font-medium text-muted-foreground">
                            Due Now
                        </dt>
                        <dd
                            class="mt-1 text-3xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ myr(billing.invoice.state.due_now) }}
                        </dd>
                    </dl>
                </div>
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
                    <dl
                        class="billing-summary-grid mt-4 grid auto-rows-[44px] text-[13px]"
                        :class="
                            manage && 'gap-x-8 sm:grid-cols-2 lg:grid-cols-3'
                        "
                        data-testid="billing-summary-grid"
                    >
                        <div class="billing-summary-row">
                            <dt class="font-medium">Invoice Total</dt>
                            <dd class="font-semibold tabular-nums">
                                {{ myr(billing.invoice.state.total) }}
                            </dd>
                        </div>
                        <div
                            v-for="[key, label] in responsibilityTotals"
                            :key="key"
                            class="billing-summary-row"
                        >
                            <dt class="text-muted-foreground">{{ label }}</dt>
                            <dd class="text-right tabular-nums">
                                {{ myr(billing.invoice.state[key]) }}
                            </dd>
                        </div>
                        <div class="billing-summary-row">
                            <dt class="font-medium">Outstanding</dt>
                            <dd class="text-right font-medium tabular-nums">
                                {{ myr(outstandingSen(billing.invoice.state)) }}
                            </dd>
                        </div>
                        <div
                            class="billing-summary-row"
                            :class="!manage && 'rounded-lg bg-muted'"
                        >
                            <dt class="font-semibold">Due Now</dt>
                            <dd class="font-semibold tabular-nums">
                                {{ myr(billing.invoice.state.due_now) }}
                            </dd>
                        </div>
                    </dl>
                    <dl
                        v-if="billing.invoice.adjustment.adjustmentSen !== 0"
                        class="mt-2 border-t pt-2 text-xs"
                    >
                        <div class="flex justify-between py-1">
                            <dt>Original subtotal</dt>
                            <dd>
                                {{
                                    myr(billing.invoice.adjustment.subtotalSen)
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between py-1">
                            <dt>
                                {{
                                    billing.invoice.adjustment.adjustmentSen < 0
                                        ? 'Discount'
                                        : 'Surcharge'
                                }}
                            </dt>
                            <dd>
                                {{
                                    billing.invoice.adjustment.adjustmentSen < 0
                                        ? '−'
                                        : '+'
                                }}{{
                                    myr(
                                        Math.abs(
                                            billing.invoice.adjustment
                                                .adjustmentSen,
                                        ),
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                    <InvoiceAdjustmentDialog
                        v-if="!manage"
                        :billing="billing"
                    />
                    <p v-if="billing.invoice.correctionHold" role="status">
                        Correction hold — review required.
                    </p>
                    <details
                        v-if="billing.can.pay || billing.can.panelPropose"
                        class="mt-3 text-xs"
                    >
                        <summary class="cursor-pointer font-medium">
                            Billing help
                        </summary>
                        <p
                            v-if="billing.can.pay || billing.can.panelPropose"
                            class="mt-3 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground"
                            data-testid="billing-howto"
                        >
                            <span class="font-semibold text-foreground"
                                >How to settle this invoice.</span
                            >
                            Use Add → Record new payment for money received. Use
                            Add → Propose Panel responsibility or Request pay
                            later for approval, then collect any remaining
                            patient balance.
                        </p>
                    </details></template
                >
                <p
                    v-if="!billing.invoice"
                    class="text-xs text-muted-foreground"
                >
                    Build a draft to see the financial summary.
                </p>
                <section
                    v-if="billing.invoice"
                    class="overflow-hidden rounded-lg border"
                    data-testid="billing-payments"
                >
                    <header
                        class="flex items-center justify-between gap-2 border-b bg-muted/30 px-3 py-2"
                    >
                        <h3 class="font-medium">Payments</h3>
                        <div class="flex gap-2">
                            <ActionLink
                                v-if="!manage"
                                :href="`${base}/payments`"
                                variant="outline"
                                >Manage</ActionLink
                            ><DropdownMenu
                                v-if="
                                    billing.can.pay ||
                                    billing.can.panelPropose ||
                                    billing.can.deferPropose
                                "
                                ><DropdownMenuTrigger as-child
                                    ><Button
                                        type="button"
                                        variant="outline"
                                        :disabled="busy"
                                        >Add</Button
                                    ></DropdownMenuTrigger
                                ><DropdownMenuContent align="end"
                                    ><DropdownMenuItem
                                        v-if="billing.can.pay"
                                        @select="openEntry('payment')"
                                        >Record new payment</DropdownMenuItem
                                    ><DropdownMenuItem
                                        v-if="billing.can.panelPropose"
                                        @select="openEntry('panel')"
                                        >Propose Panel
                                        responsibility</DropdownMenuItem
                                    ><DropdownMenuItem
                                        v-if="billing.can.deferPropose"
                                        @select="openEntry('deferment')"
                                        >Request pay later</DropdownMenuItem
                                    ></DropdownMenuContent
                                ></DropdownMenu
                            >
                        </div>
                    </header>
                    <p
                        v-if="!billing.payments.length"
                        class="p-3 text-xs text-muted-foreground"
                    >
                        No payments recorded.
                    </p>
                    <div
                        v-else-if="manage"
                        class="hidden overflow-x-auto sm:block"
                    >
                        <table class="w-full text-left text-xs">
                            <thead
                                class="border-b bg-muted/20 text-[11px] text-muted-foreground"
                            >
                                <tr>
                                    <th class="p-3">Receipt / method</th>
                                    <th class="p-3">Payment date</th>
                                    <th class="p-3 text-right">Amount</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="receipt in billing.payments"
                                    :key="receipt.publicId"
                                >
                                    <td class="p-3">
                                        <p class="font-medium">
                                            {{ receipt.method }}
                                        </p>
                                        <p class="mt-1 text-muted-foreground">
                                            {{ receipt.number }}
                                        </p>
                                    </td>
                                    <td class="p-3">
                                        {{ receipt.receivedAt }}
                                    </td>
                                    <td
                                        class="p-3 text-right whitespace-nowrap tabular-nums"
                                    >
                                        {{ myr(receipt.amountSen) }}
                                    </td>
                                    <td class="p-3">
                                        <StatusBadge :status="receipt.status" />
                                    </td>
                                    <td class="p-3">
                                        <div
                                            class="flex flex-wrap justify-end gap-2"
                                        >
                                            <ActionLink
                                                v-if="billing.can.print"
                                                :href="`${invoiceBase}/receipts/${receipt.publicId}/print`"
                                                variant="outline"
                                                target="_blank"
                                                rel="noopener"
                                                >Print Receipt</ActionLink
                                            ><Button
                                                v-if="
                                                    billing.can.reverse &&
                                                    receipt.status === 'posted'
                                                "
                                                type="button"
                                                variant="outline"
                                                :disabled="busy"
                                                @click="openReversal(receipt)"
                                                >Reverse recording error</Button
                                            >
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <ul
                        v-if="manage && billing.payments.length"
                        class="divide-y sm:hidden"
                    >
                        <li
                            v-for="receipt in billing.payments"
                            :key="receipt.publicId"
                            class="space-y-2 p-3"
                        >
                            <div class="flex justify-between gap-2">
                                <span class="font-medium">{{
                                    receipt.method
                                }}</span
                                ><span class="tabular-nums">{{
                                    myr(receipt.amountSen)
                                }}</span>
                            </div>
                            <p class="text-[11px] text-muted-foreground">
                                {{ receipt.number }} · {{ receipt.receivedAt }}
                            </p>
                            <StatusBadge :status="receipt.status" />
                            <div class="flex flex-wrap gap-2">
                                <ActionLink
                                    v-if="billing.can.print"
                                    :href="`${invoiceBase}/receipts/${receipt.publicId}/print`"
                                    variant="outline"
                                    target="_blank"
                                    rel="noopener"
                                    >Print Receipt</ActionLink
                                ><Button
                                    v-if="
                                        billing.can.reverse &&
                                        receipt.status === 'posted'
                                    "
                                    type="button"
                                    variant="outline"
                                    :disabled="busy"
                                    @click="openReversal(receipt)"
                                    >Reverse recording error</Button
                                >
                            </div>
                        </li>
                    </ul>
                    <ul
                        v-if="!manage && billing.payments.length"
                        class="divide-y"
                    >
                        <li
                            v-for="receipt in billing.payments"
                            :key="receipt.publicId"
                            class="p-3"
                        >
                            <div class="flex justify-between gap-2">
                                <span class="font-medium">{{
                                    receipt.method
                                }}</span
                                ><span class="tabular-nums">{{
                                    myr(receipt.amountSen)
                                }}</span>
                            </div>
                            <div class="mt-1 text-[11px] text-muted-foreground">
                                {{ receipt.number }} ·
                                {{ receipt.receivedAt }} ·
                                <span class="capitalize">{{
                                    receipt.status
                                }}</span>
                            </div>
                        </li>
                    </ul>
                </section>
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
                        <p
                            v-if="billing[kind]!.status === 'proposed'"
                            class="text-xs text-muted-foreground"
                            data-testid="proposal-hint"
                        >
                            {{
                                (
                                    kind === 'panel'
                                        ? billing.can.panelApprove
                                        : billing.can.deferApprove
                                )
                                    ? 'Check the details, then press Approve.'
                                    : 'Waiting for approval. Someone with approval rights must press Approve before this counts.'
                            }}
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
                            >Approve</Button
                        ></template
                    >
                </div>
                <details v-if="!manage && billing.can.void">
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
                    v-if="!manage && billing.can.complete"
                    class="w-full"
                    :disabled="busy"
                    @click="completionOpen = true"
                    >Complete Visitation</Button
                >
                <BillingCompletionDialog
                    v-if="!manage && billing.invoice"
                    v-model:open="completionOpen"
                    :visit-number="billing.visit.visitNumber"
                    :branch-id="page.props.branchContext?.active?.id ?? 0"
                    :visit-lock-version="billing.visit.lockVersion"
                    :invoice-lock-version="billing.invoice.lockVersion"
                />
                <p class="text-xs text-muted-foreground">
                    Billing never deducts stock. Panel and approved pay later
                    are not cash receipts.
                </p>
            </aside>
        </div>

        <div data-testid="billing-inline-forms">
            <Dialog
                :open="paymentOpen"
                @update:open="updateDialog('payment', $event)"
                ><DialogContent
                    class="billing-entry max-h-[90dvh] overflow-y-auto text-[13px] sm:max-w-2xl"
                    :show-close-button="!busy"
                    @escape-key-down="busy && $event.preventDefault()"
                    @interact-outside="busy && $event.preventDefault()"
                    ><DialogHeader
                        ><DialogTitle class="text-base"
                            >Record new payment</DialogTitle
                        ><DialogDescription class="text-xs"
                            >Patient receipts are separate from approved Panel
                            responsibility and pay later.</DialogDescription
                        ></DialogHeader
                    >
                    <dl
                        v-if="billing.invoice"
                        class="grid grid-cols-2 gap-2 rounded-lg border bg-muted/20 p-3 text-xs"
                    >
                        <dt>Invoice Total</dt>
                        <dd class="text-right">
                            {{ myr(billing.invoice.state.total) }}
                        </dd>
                        <dt>Self-pay received</dt>
                        <dd class="text-right">
                            {{ myr(billing.invoice.state.self_pay) }}
                        </dd>
                        <dt>Outstanding</dt>
                        <dd class="text-right">
                            {{ myr(outstandingSen(billing.invoice.state)) }}
                        </dd>
                        <dt>Due Now</dt>
                        <dd class="text-right font-semibold">
                            {{ myr(billing.invoice.state.due_now) }}
                        </dd>
                    </dl>
                    <p v-if="error" role="alert" class="text-destructive">
                        {{ error }}
                    </p>
                    <div v-if="billing.can.pay">
                        <h3 class="text-[13px] font-semibold">
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
                    <template v-if="billing.can.pay"
                        ><p class="text-xs text-muted-foreground">
                            For money the patient pays now. Panel responsibility
                            is recorded separately.
                        </p>
                        <label class="block"
                            >Amount received (MYR)<input
                                v-model="form.amount"
                                inputmode="decimal"
                                class="billing-input"
                                autocomplete="off"
                        /></label>
                        <button
                            v-if="dueNowText && !isCompleted"
                            type="button"
                            class="text-xs font-medium text-primary"
                            @click="form.amount = dueNowText"
                        >
                            Use amount due (RM {{ dueNowText }})
                        </button>
                        <label
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
                        >
                        <p
                            v-if="!form.method"
                            class="text-xs text-muted-foreground"
                        >
                            Choose a payment method to enable Add Payment.
                        </p></template
                    >
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="busy"
                            @click="updateDialog('payment', false)"
                            >Cancel</Button
                        ></DialogFooter
                    ></DialogContent
                ></Dialog
            >
            <Dialog
                :open="responsibilityOpen"
                @update:open="updateDialog('responsibility', $event)"
                ><DialogContent
                    class="billing-entry max-h-[90dvh] overflow-y-auto text-[13px] sm:max-w-lg"
                    :show-close-button="!busy"
                    @escape-key-down="busy && $event.preventDefault()"
                    @interact-outside="busy && $event.preventDefault()"
                    ><DialogHeader
                        ><DialogTitle class="text-base">{{
                            responsibilityKind === 'panel'
                                ? 'Panel responsibility'
                                : 'Pay later'
                        }}</DialogTitle
                        ><DialogDescription class="text-xs"
                            >Pay by Panel or pay later. This request needs
                            approval and is not a cash
                            receipt.</DialogDescription
                        ></DialogHeader
                    >
                    <p v-if="error" role="alert" class="text-destructive">
                        {{ error }}
                    </p>
                    <div class="space-y-2">
                        <details
                            v-if="
                                responsibilityKind === 'panel' &&
                                billing.can.panelPropose
                            "
                            class="my-2 text-xs"
                        >
                            <summary class="cursor-pointer">
                                Panel approval steps
                            </summary>
                            <ol
                                v-if="
                                    responsibilityKind === 'panel' &&
                                    billing.can.panelPropose
                                "
                                class="my-2 list-decimal space-y-1 pl-5 text-xs text-muted-foreground"
                                data-testid="panel-steps"
                            >
                                <li>Enter the amount the Panel will cover.</li>
                                <li>
                                    Add the reason or the Panel confirmation
                                    reference.
                                </li>
                                <li>
                                    Choose the verified Panel and add the member
                                    reference if the Panel needs one.
                                </li>
                                <li>Press Propose Panel responsibility.</li>
                                <li>
                                    Someone with Panel approval rights presses
                                    Approve. Due Now then drops by the Panel
                                    amount.
                                </li>
                                <li>
                                    Collect any remaining balance under Record
                                    Payment, then Complete Visitation.
                                </li>
                            </ol>
                        </details>
                        <label class="block"
                            >Amount the Panel or pay later will cover
                            (MYR)<input
                                v-model="form.responsibility_amount"
                                inputmode="decimal"
                                class="billing-input"
                                autocomplete="off"
                        /></label>
                        <button
                            v-if="dueNowText && !isCompleted"
                            type="button"
                            class="mb-2 text-xs font-medium text-primary"
                            @click="form.responsibility_amount = dueNowText"
                        >
                            Use amount due (RM {{ dueNowText }})
                        </button>
                        <label class="block"
                            >Reason / verification evidence<textarea
                                v-model="form.reason"
                                maxlength="500"
                                class="billing-input"
                            /></label
                        ><template
                            v-if="
                                responsibilityKind === 'panel' &&
                                billing.can.panelPropose
                            "
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
                            >
                            <p class="mt-1 text-xs text-muted-foreground">
                                This only sends the request for approval. The
                                Panel amount counts after it is approved.
                            </p></template
                        ><template
                            v-if="
                                responsibilityKind === 'deferment' &&
                                billing.can.deferPropose
                            "
                            ><p class="mt-3 text-xs text-muted-foreground">
                                Pay later: the patient pays on the due date. It
                                needs approval and is not a cash receipt.
                            </p>
                            <label class="mt-2 block"
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
                    </div>
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="busy"
                            @click="updateDialog('responsibility', false)"
                            >Cancel</Button
                        ></DialogFooter
                    ></DialogContent
                ></Dialog
            >
            <Dialog
                :open="reversalOpen"
                @update:open="updateDialog('reversal', $event)"
                ><DialogContent
                    class="billing-entry text-[13px]"
                    :show-close-button="!busy"
                    @escape-key-down="busy && $event.preventDefault()"
                    @interact-outside="busy && $event.preventDefault()"
                    ><DialogHeader
                        ><DialogTitle class="text-base"
                            >Reverse recording error</DialogTitle
                        ><DialogDescription class="text-xs"
                            >Recording errors only. No refunds or
                            post-completion accounting
                            correction.</DialogDescription
                        ></DialogHeader
                    >
                    <p v-if="reversalReceipt">
                        {{ reversalReceipt.number }} ·
                        {{ myr(reversalReceipt.amountSen) }}
                    </p>
                    <p v-if="error" role="alert" class="text-destructive">
                        {{ error }}
                    </p>
                    <label
                        >Reason<textarea
                            v-model="form.reason"
                            maxlength="500"
                            class="billing-input"
                        /></label
                    ><label class="flex gap-2"
                        ><input
                            v-model="form.recording_error_only"
                            type="checkbox"
                        />No real refund: recording error only</label
                    ><DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="busy"
                            @click="updateDialog('reversal', false)"
                            >Cancel</Button
                        ><Button
                            type="button"
                            variant="destructive"
                            :disabled="
                                busy ||
                                !form.reason ||
                                !form.recording_error_only ||
                                !reversalReceipt
                            "
                            @click="reversalReceipt && reverse(reversalReceipt)"
                            >Reverse recording error</Button
                        ></DialogFooter
                    ></DialogContent
                ></Dialog
            >
        </div>
    </main>
</template>

<style scoped>
.billing-summary-row {
    display: flex;
    height: 44px;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    border-bottom: 1px solid var(--border);
    padding-inline: 0.75rem;
    line-height: 1.25rem;
}
.billing-entry :deep(button),
.billing-workspace :deep(button),
.billing-workspace :deep(a[data-slot='button']) {
    min-height: 28px;
    height: 30px;
    padding-inline: 10px;
    font-size: 12px;
}
.billing-entry :deep(input),
.billing-entry :deep(textarea),
.billing-workspace :deep(input),
.billing-workspace :deep(textarea) {
    font-size: 12px;
}
.billing-input {
    display: block;
    width: 100%;
    margin-block: 0.25rem 0.5rem;
    padding: 0.4rem 0.5rem;
    min-height: 30px;
    border: 1px solid var(--input);
    border-radius: 6px;
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
