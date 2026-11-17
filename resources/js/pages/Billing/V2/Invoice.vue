<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import InvoiceActivityDrawer from '@/components/billing/InvoiceActivityDrawer.vue';
import InvoiceBillTo from '@/components/billing/InvoiceBillTo.vue';
import InvoiceNotes from '@/components/billing/InvoiceNotes.vue';
import IssuePrintButton from '@/components/billing/IssuePrintButton.vue';
import OutstandingBills from '@/components/billing/OutstandingBills.vue';
import InvoicePaymentsCardV2 from '@/components/billing/v2/InvoicePaymentsCardV2.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { myr } from '@/types/billing';
import type { CompletedInvoicePage } from '@/types/completedInvoice';

/**
 * BV2-3: Version 2 completed-visit Invoice (shown only in Version 2; Classic is `pages/Billing/Invoice.vue`, untouched).
 * Read only: same `invoice` prop, routes and permissions; nothing is calculated here. Every amount comes from
 * `invoice.financial` through the same `myr()` as Classic, with the same rows and words. Bill to, notes, the activity
 * drawer, Outstanding bills and the Print buttons are the same shared components with the same props and urls, so a
 * change to them touches one place. As on V2 Billing, the patient's name and visit number head the page and the money
 * summary sits in the right column from 1280 px (above the content below that width); the payments card sits in the
 * right column from 1280 px and under the items below it (one card, shown in one place at a time).
 */
const props = defineProps<{ invoice: CompletedInvoicePage }>();

usePageCrumbs(() => [
    { title: 'Registration', href: props.invoice.links.back },
    { title: props.invoice.visit.visitNumber },
]);

// The shared Classic pieces (Bill to, notes, outstanding bills, the activity drawer) keep their own build; while this
// page is open the body carries a marker, so page-only CSS reaches their dialogs too (they open outside the page).
onMounted(() => document.body.setAttribute('data-invoice-v2', ''));
onUnmounted(() => document.body.removeAttribute('data-invoice-v2'));

const tab = ref('all');
// BIL-5: billing prints are recorded; the old print address only redirects to Issued copies (BIL-14).
const billingPrint = /\/billing\/[^/]+\/(?:receipts\/[^/]+\/)?print$/;
const isBillingPrint = (url: string) => billingPrint.test(url);
const issueUrl = (url: string) => url.replace(/\/print$/, '/issue');
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
const itemCount = computed(
    () => lines.value.filter((line) => line.type === 'medicine').length,
);
const tabs = computed(() => [
    {
        value: 'all',
        label: `All ${lines.value.length + documents.value.length}`,
    },
    { value: 'items', label: `Items ${itemCount.value}` },
    {
        value: 'services',
        label: `Services ${lines.value.length - itemCount.value}`,
    },
    { value: 'documents', label: `Documents ${documents.value.length}` },
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
              ['Pay later still owed', state.deferred],
          ] as Array<[string, number]>)
        : [];
});
const signedAdjustment = (sen: number) =>
    `${sen < 0 ? '−' : '+'}${myr(Math.abs(sen))}`;

const card = 'rounded-[14px] border bg-card p-4 sm:p-5';
const linkButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9';
const row = 'flex min-h-11 items-center justify-between gap-4 lg:min-h-9';
</script>

<template>
    <Head
        :title="`Invoice ${invoice.financial?.invoiceNumber ?? invoice.visit.visitNumber}`"
    />
    <PageFrame class="invoice-v2-page" data-testid="completed-invoice">
        <V2WorkLayout
            eyebrow="Invoice"
            :title="invoice.patient.name"
            :description="`${invoice.financial?.invoiceNumber ?? 'No invoice'} · ${invoice.visit.visitNumber} · ${invoice.visit.branch}`"
            rail-from="xl"
            rail-below="stack-top"
            rail-label="Invoice summary"
        >
            <template #actions>
                <div
                    class="flex w-full flex-wrap gap-2 sm:w-auto"
                    data-testid="invoice-actions"
                >
                    <IssuePrintButton
                        v-if="invoice.financial?.printUrl"
                        :url="issueUrl(invoice.financial.printUrl)"
                        label="Print invoice"
                        variant="outline"
                        return-to="invoice"
                    />
                    <Link :href="invoice.links.consultation" :class="linkButton"
                        >View consultation</Link
                    >
                    <InvoiceActivityDrawer
                        :context="invoice.links.activity"
                        :notes-context="invoice.notes"
                    />
                    <Link
                        :href="invoice.links.back"
                        :class="[
                            linkButton,
                            'max-sm:order-last max-sm:border-0 max-sm:bg-transparent max-sm:px-0 max-sm:text-primary',
                        ]"
                        >← Back to Registration</Link
                    >
                </div>
            </template>

            <template #main>
                <div class="flex flex-wrap items-center gap-2">
                    <span data-testid="invoice-completed"
                        ><V2Chip tone="success" label="Visitation completed"
                    /></span>
                    <span class="text-sm text-muted-foreground">Read only</span>
                </div>

                <div
                    class="grid items-start gap-4 md:grid-cols-2"
                    data-testid="invoice-patient"
                >
                    <section :class="card">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold break-words">
                                    {{ invoice.patient.name }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ invoice.patient.patientNumber }}
                                    <template v-if="invoice.patient.age">
                                        · {{ invoice.patient.age }}
                                    </template>
                                    <template v-if="invoice.patient.sex">
                                        · {{ invoice.patient.sex }}
                                    </template>
                                </p>
                            </div>
                            <Link
                                v-if="invoice.patient.profileUrl"
                                :href="invoice.patient.profileUrl"
                                class="inline-flex min-h-11 shrink-0 items-center text-sm font-medium text-primary hover:underline lg:min-h-0"
                                >View profile</Link
                            >
                        </div>
                    </section>
                    <section :class="card">
                        <h2 class="text-base font-semibold">Visit</h2>
                        <dl
                            class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Payment method
                            </dt>
                            <dd class="min-w-0 break-words">
                                {{ invoice.visit.coverage }}
                            </dd>
                            <dt class="text-muted-foreground">Registered</dt>
                            <dd>{{ invoice.visit.registeredAt }}</dd>
                            <dt class="text-muted-foreground">Completed</dt>
                            <dd>{{ invoice.visit.completedAt }}</dd>
                        </dl>
                    </section>
                </div>

                <p
                    v-if="invoice.financial?.panelNoInvoicePrint"
                    class="rounded-[10px] bg-muted/50 px-4 py-3 text-sm text-muted-foreground"
                    data-testid="panel-no-invoice-print"
                >
                    Panel patient: no invoice is printed. A receipt prints only
                    for cash the patient paid.
                </p>

                <section :class="card" data-testid="invoice-lines">
                    <V2Tabs
                        v-model="tab"
                        :tabs="tabs"
                        ariaLabel="Invoice items"
                    />
                    <div
                        role="tabpanel"
                        aria-label="Invoice items"
                        aria-live="polite"
                        class="mt-3"
                    >
                        <p
                            v-if="!invoice.canSeeLines && tab !== 'documents'"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                invoice.canSeeFinancial
                                    ? 'Totals only for your access.'
                                    : 'Billing details are not shown for your role.'
                            }}
                        </p>
                        <ul v-else class="divide-y text-sm">
                            <li
                                v-for="(line, index) in visibleLines"
                                :key="index"
                                class="flex justify-between gap-4 py-3 max-sm:flex-col max-sm:gap-1"
                                data-testid="invoice-line"
                            >
                                <div class="min-w-0">
                                    <div class="font-medium break-words">
                                        {{ line.name }}
                                    </div>
                                    <div
                                        class="mt-0.5 text-sm text-muted-foreground tabular-nums"
                                    >
                                        {{ myr(line.unitPriceSen) }} ×
                                        {{ line.quantity }} {{ line.unit }}
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 font-medium whitespace-nowrap tabular-nums"
                                    >{{ myr(line.totalSen) }}</span
                                >
                            </li>
                            <template
                                v-if="tab === 'all' || tab === 'documents'"
                            >
                                <li
                                    v-for="document in documents"
                                    :key="document.url"
                                    class="flex flex-wrap items-center justify-between gap-3 py-3"
                                    data-testid="invoice-document"
                                >
                                    <span
                                        class="font-medium whitespace-nowrap"
                                        >{{ document.name }}</span
                                    >
                                    <IssuePrintButton
                                        v-if="isBillingPrint(document.url)"
                                        :url="issueUrl(document.url)"
                                        :label="
                                            /\/receipts\//.test(document.url)
                                                ? 'Print receipt'
                                                : 'Print invoice'
                                        "
                                        variant="outline"
                                        return-to="invoice"
                                    />
                                    <Link
                                        v-else
                                        :href="document.url"
                                        :class="linkButton"
                                        >Print document</Link
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
                            class="text-sm text-muted-foreground"
                        >
                            No billed items in this category.
                        </p>
                        <p
                            v-if="tab === 'documents' && !documents.length"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                invoice.financial?.panelNoInvoicePrint
                                    ? 'Panel patient: no invoice is printed. A receipt prints only for cash the patient paid.'
                                    : 'No printable documents for your access.'
                            }}
                        </p>
                    </div>
                </section>

                <section
                    v-if="invoice.billTo || invoice.notes"
                    class="divide-y rounded-[14px] border bg-card"
                >
                    <div v-if="invoice.billTo" class="p-4 sm:p-5">
                        <InvoiceBillTo :context="invoice.billTo" />
                    </div>
                    <div
                        v-if="invoice.notes"
                        class="flex flex-wrap items-center justify-between gap-2 p-4 sm:p-5"
                    >
                        <h2 class="text-sm font-medium text-muted-foreground">
                            Invoice notes
                        </h2>
                        <InvoiceNotes :context="invoice.notes" />
                    </div>
                </section>

                <InvoicePaymentsCardV2
                    v-if="invoice.financial"
                    :invoice="invoice"
                    class="xl:hidden"
                />

                <OutstandingBills :groups="invoice.outstanding" />
            </template>

            <template #rail>
                <section :class="card" data-testid="invoice-financial">
                    <template v-if="invoice.financial">
                        <h2 class="text-base font-semibold">
                            Invoice
                            <span
                                class="text-sm font-medium whitespace-nowrap"
                                >{{ invoice.financial.invoiceNumber }}</span
                            >
                        </h2>
                        <dl class="mt-2 text-sm">
                            <div :class="row">
                                <dt>Subtotal</dt>
                                <dd class="tabular-nums">
                                    {{
                                        myr(
                                            invoice.financial.adjustment
                                                .subtotalSen,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div
                                v-if="
                                    invoice.financial.adjustment
                                        .adjustmentSen !== 0
                                "
                                :class="row"
                            >
                                <dt>
                                    {{
                                        invoice.financial.adjustment
                                            .adjustmentSen < 0
                                            ? 'Discount'
                                            : 'Surcharge'
                                    }}
                                </dt>
                                <dd class="tabular-nums">
                                    {{
                                        signedAdjustment(
                                            invoice.financial.adjustment
                                                .adjustmentSen,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div
                                v-if="invoice.financial.refundCreditSen"
                                :class="row"
                            >
                                <dt data-testid="invoice-refund-credit">
                                    Refund credit
                                </dt>
                                <dd class="tabular-nums">
                                    −{{
                                        myr(invoice.financial.refundCreditSen)
                                    }}
                                </dd>
                            </div>
                            <div :class="[row, 'font-semibold']">
                                <dt>Total</dt>
                                <dd class="tabular-nums">
                                    {{ myr(invoice.financial.state.total) }}
                                </dd>
                            </div>
                        </dl>
                        <dl class="mt-2 border-t pt-2 text-sm">
                            <div
                                v-for="[label, amount] in totals"
                                :key="label"
                                :class="row"
                            >
                                <dt class="text-muted-foreground">
                                    {{ label }}
                                </dt>
                                <dd class="tabular-nums">{{ myr(amount) }}</dd>
                            </div>
                            <div :class="[row, 'text-base font-semibold']">
                                <dt>Amount due now</dt>
                                <dd class="tabular-nums">
                                    {{ myr(invoice.financial.state.due_now) }}
                                </dd>
                            </div>
                        </dl>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            invoice.canSeeFinancial
                                ? 'No invoice was issued for this visit.'
                                : 'Billing details are not shown for your role.'
                        }}
                    </p>
                </section>

                <InvoicePaymentsCardV2
                    v-if="invoice.financial"
                    :invoice="invoice"
                    class="max-xl:hidden"
                />
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>

<style>
/* Version 2 wrapper over the shared Classic pieces on this page only (as on V2 Billing). Not scoped: their dialogs open
   outside the page, so the rules key on the body marker this page sets while it is open.
   1. Every control is at least 44 px below 1024 px, in the page and in any dialog it opens (the close cross excepted).
   2. The page has no main action (it is read only), so a filled button inside the shared blocks reads as outline. */
@layer base {
    @media (max-width: 1023.98px) {
        body[data-invoice-v2]
            :where(.invoice-v2-page, [role='dialog'])
            :where(
                button:not([data-slot='dialog-close']),
                a[data-slot='button'],
                select,
                textarea,
                [role='combobox'],
                input:not([type='checkbox'], [type='radio'])
            ) {
            min-height: 44px;
        }
    }
}
body[data-invoice-v2]
    .invoice-v2-page
    button[data-slot='button']:is(
        :not([data-variant]),
        [data-variant='default']
    ) {
    border: 1px solid var(--border);
    background: var(--card);
    color: var(--foreground);
}
body[data-invoice-v2]
    .invoice-v2-page
    button[data-slot='button']:is(
        :not([data-variant]),
        [data-variant='default']
    ):hover {
    background: var(--muted);
}
</style>
