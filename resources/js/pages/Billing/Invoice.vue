<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowLeft, Download, FileText, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import InvoiceActivityDrawer from '@/components/billing/InvoiceActivityDrawer.vue';
import OutstandingBills from '@/components/billing/OutstandingBills.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { OperationalTabs } from '@/components/ui/tabs';
import { myr } from '@/types/billing';
import type { CompletedInvoicePage } from '@/types/completedInvoice';

const props = defineProps<{ invoice: CompletedInvoicePage }>();
const tab = ref('all');
const lines = computed(() => props.invoice.financial?.lines ?? []);
const documents = computed(() => {
    const financial = props.invoice.financial;

    if (!financial) {
        return [];
    }

    return [
        ...(financial.printUrl
            ? [
                  {
                      name: `Invoice ${financial.invoiceNumber}`,
                      url: financial.printUrl,
                  },
              ]
            : []),
        ...financial.payments.flatMap((payment) =>
            payment.printUrl
                ? [
                      {
                          name: `Receipt ${payment.receiptNumber}`,
                          url: payment.printUrl,
                      },
                  ]
                : [],
        ),
    ];
});
const tabs = computed(() => [
    {
        value: 'all',
        label: 'All',
        count: lines.value.length + documents.value.length,
    },
    {
        value: 'items',
        label: 'Items',
        count: lines.value.filter((line) => line.type === 'medicine').length,
    },
    {
        value: 'services',
        label: 'Services',
        count: lines.value.filter((line) => line.type !== 'medicine').length,
    },
    { value: 'documents', label: 'Documents', count: documents.value.length },
]);
const visibleLines = computed(() =>
    lines.value.filter(
        (line) =>
            tab.value === 'all' ||
            (tab.value === 'items' && line.type === 'medicine') ||
            (tab.value === 'services' && line.type !== 'medicine'),
    ),
);
const totals = computed(() => {
    const state = props.invoice.financial?.state;

    return state
        ? ([
              ['Self-pay received', state.self_pay],
              ['Panel responsibility', state.panel],
              ['Approved pay later', state.deferred],
          ] as Array<[string, number]>)
        : [];
});
</script>

<template>
    <Head
        :title="`Invoice ${invoice.financial?.invoiceNumber ?? invoice.visit.visitNumber}`"
    />
    <main
        class="invoice-workspace flex flex-1 flex-col bg-muted/40 text-[13px]"
        data-testid="completed-invoice"
    >
        <header
            class="flex flex-wrap items-center gap-2 border-b bg-card px-4 py-3"
        >
            <ActionLink
                :href="invoice.links.back"
                variant="outline"
                aria-label="Back to Registration"
                ><ArrowLeft class="size-3.5"
            /></ActionLink>
            <h1 class="mr-1 text-base font-semibold">
                Invoice
                {{
                    invoice.financial?.invoiceNumber ??
                    '· ' + invoice.visit.visitNumber
                }}
            </h1>
            <ActionLink :href="invoice.links.consultation" variant="outline"
                >View consultation</ActionLink
            >
            <InvoiceActivityDrawer :context="invoice.links.activity" />
            <span class="ml-auto text-[11px] text-muted-foreground"
                >{{ invoice.visit.branch }} ·
                {{ invoice.visit.visitNumber }}</span
            >
        </header>

        <div
            class="grid items-start gap-3 p-3 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1.3fr)_minmax(0,.8fr)]"
            data-testid="invoice-columns"
        >
            <aside class="grid min-w-0 gap-3" data-testid="invoice-patient">
                <section class="overflow-hidden rounded-xl border bg-card">
                    <div class="flex items-start gap-3 p-4">
                        <div
                            class="flex h-14 w-12 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                        >
                            <UserRound class="size-6" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold break-words">
                                {{ invoice.patient.name }}
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                {{
                                    [invoice.patient.age, invoice.patient.sex]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                {{ invoice.patient.patientNumber }}
                            </div>
                        </div>
                        <ActionLink
                            v-if="invoice.patient.profileUrl"
                            :href="invoice.patient.profileUrl"
                            variant="outline"
                            >View profile</ActionLink
                        >
                    </div>
                    <div class="border-t p-4">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="font-medium text-muted-foreground">
                                Invoice
                            </h2>
                            <ActionLink
                                v-if="invoice.financial?.printUrl"
                                :href="invoice.financial.printUrl"
                                variant="outline"
                                ><Download class="size-3" />Download</ActionLink
                            >
                        </div>
                        <dl
                            class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-xs"
                        >
                            <dt class="text-muted-foreground">
                                Payment method
                            </dt>
                            <dd class="text-right break-words">
                                {{ invoice.visit.coverage }}
                            </dd>
                            <dt class="text-muted-foreground">Registered</dt>
                            <dd class="text-right">
                                {{ invoice.visit.registeredAt }}
                            </dd>
                            <dt class="text-muted-foreground">Completed</dt>
                            <dd class="text-right">
                                {{ invoice.visit.completedAt }}
                            </dd>
                        </dl>
                    </div>
                </section>
                <OutstandingBills :groups="invoice.outstanding" />
            </aside>

            <section
                class="min-w-0 overflow-hidden rounded-xl border bg-card"
                data-testid="invoice-lines"
            >
                <OperationalTabs
                    v-model="tab"
                    :tabs="tabs"
                    label="Invoice items"
                    class="p-3"
                />
                <div aria-live="polite">
                    <div
                        v-if="!invoice.canSeeLines && tab !== 'documents'"
                        class="p-4 text-xs text-muted-foreground"
                    >
                        {{
                            invoice.canSeeFinancial
                                ? 'Totals only for your access.'
                                : 'Billing details are not shown for your role.'
                        }}
                    </div>
                    <ul v-else class="divide-y">
                        <li
                            v-for="(line, index) in visibleLines"
                            :key="index"
                            class="flex items-start justify-between gap-4 px-4 py-4"
                            data-testid="invoice-line"
                        >
                            <div class="min-w-0">
                                <div class="font-semibold break-words">
                                    {{ line.name }}
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    {{ myr(line.unitPriceSen) }} ×
                                    {{ line.quantity }} {{ line.unit }}
                                </div>
                            </div>
                            <span class="shrink-0 tabular-nums">{{
                                myr(line.totalSen)
                            }}</span>
                        </li>
                        <template v-if="tab === 'all' || tab === 'documents'">
                            <li
                                v-for="document in documents"
                                :key="document.url"
                                class="flex flex-wrap items-center justify-between gap-3 px-4 py-4"
                            >
                                <span class="font-medium">{{
                                    document.name
                                }}</span>
                                <ActionLink
                                    :href="document.url"
                                    variant="outline"
                                    ><FileText class="size-3" />Print
                                    document</ActionLink
                                >
                            </li>
                        </template>
                    </ul>
                    <p
                        v-if="
                            invoice.canSeeLines &&
                            !visibleLines.length &&
                            tab !== 'documents'
                        "
                        class="p-4 text-xs text-muted-foreground"
                    >
                        No billed items in this category.
                    </p>
                    <p
                        v-if="tab === 'documents' && !documents.length"
                        class="p-4 text-xs text-muted-foreground"
                    >
                        No printable documents for your access.
                    </p>
                </div>
            </section>

            <aside
                class="min-w-0 rounded-xl border bg-card p-4"
                data-testid="invoice-financial"
            >
                <template v-if="invoice.financial">
                    <dl class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-3">
                        <dt class="font-semibold">Subtotal</dt>
                        <dd class="text-right font-semibold tabular-nums">
                            {{ myr(invoice.financial.state.total) }}
                        </dd>
                        <dt class="font-semibold">Total</dt>
                        <dd class="text-right font-semibold tabular-nums">
                            {{ myr(invoice.financial.state.total) }}
                        </dd>
                    </dl>
                    <dl
                        class="mt-4 grid grid-cols-[1fr_auto] gap-x-3 gap-y-2 border-t pt-4"
                    >
                        <template v-for="[label, amount] in totals" :key="label"
                            ><dt class="text-xs text-muted-foreground">
                                {{ label }}
                            </dt>
                            <dd class="text-right text-xs tabular-nums">
                                {{ myr(amount) }}
                            </dd></template
                        >
                        <dt class="pt-1 font-semibold">Amount due now</dt>
                        <dd class="pt-1 text-right font-semibold tabular-nums">
                            {{ myr(invoice.financial.state.due_now) }}
                        </dd>
                    </dl>
                    <section
                        class="mt-4 overflow-hidden rounded-lg border"
                        data-testid="invoice-payments"
                    >
                        <header
                            class="flex items-center justify-between gap-2 border-b bg-muted/30 px-3 py-2"
                        >
                            <h2 class="font-medium">Payments</h2>
                            <ActionLink
                                v-if="invoice.links.payments"
                                :href="invoice.links.payments"
                                variant="outline"
                                >Manage</ActionLink
                            >
                        </header>
                        <p
                            v-if="!invoice.financial.payments.length"
                            class="p-3 text-xs text-muted-foreground"
                        >
                            No payments recorded.
                        </p>
                        <ul v-else class="divide-y">
                            <li
                                v-for="payment in invoice.financial.payments"
                                :key="payment.receiptNumber"
                                class="p-3"
                            >
                                <div class="flex justify-between gap-2">
                                    <span class="font-medium">{{
                                        payment.method
                                    }}</span
                                    ><span class="tabular-nums">{{
                                        myr(payment.amountSen)
                                    }}</span>
                                </div>
                                <div
                                    class="mt-1 text-[11px] text-muted-foreground"
                                >
                                    {{ payment.receiptNumber }} ·
                                    <span class="capitalize">{{
                                        payment.status
                                    }}</span>
                                </div>
                            </li>
                        </ul>
                    </section>
                </template>
                <p v-else class="text-xs text-muted-foreground">
                    {{
                        invoice.canSeeFinancial
                            ? 'No invoice was issued for this visit.'
                            : 'Billing details are not shown for your role.'
                    }}
                </p>
                <Button
                    disabled
                    class="mt-4 w-full"
                    data-testid="invoice-completed"
                    >Visitation completed</Button
                >
            </aside>
        </div>
    </main>
</template>

<style scoped>
.invoice-workspace :deep(button),
.invoice-workspace :deep(a[data-slot='button']) {
    min-height: 28px;
    height: 30px;
    padding-inline: 10px;
    font-size: 12px;
}
</style>
