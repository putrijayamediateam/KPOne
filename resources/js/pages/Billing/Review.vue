<script setup lang="ts">
/**
 * BIL-37: the Resident Doctor's read-only bill of their own patient's visit. View only: no money action, no print.
 * The server records every open before it builds this page.
 */
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import ReadOnlyBanner from '@/components/ReadOnlyBanner.vue';
import { PageHeader } from '@/components/ui/page-header';
import { myr } from '@/types/billing';

type Line = {
    name: string;
    quantity: string | number;
    unitPriceSen: number;
    totalSen: number;
};
type Payment = {
    receivedAt: string;
    method: string;
    amountSen: number;
    status: string;
    recordedBy: string;
};
type Totals = {
    subtotalSen: number;
    adjustmentSen: number;
    totalSen: number;
    paidSen: number;
    panelSen: number;
    payLaterSen: number;
    dueNowSen: number;
};

defineProps<{
    review: {
        visit: { number: string; status: string };
        patient: { name: string; patientNumber: string };
        invoice: {
            number: string;
            status: string;
            finalizedAt: string | null;
            lines: Line[];
            totals: Totals | null;
            payments: Payment[];
        } | null;
    };
    backUrl: string;
}>();

const quantity = (value: string | number): string =>
    String(Number(value)).replace(/\.0+$/, '');
// Kumar: "Paid" only when the patient's own payments settle it; a Panel or pay-later share is named as such.
const statusLabel = (totals: Totals): string =>
    totals.dueNowSen > 0
        ? 'Due'
        : totals.payLaterSen > 0
          ? 'Settled (part on pay later)'
          : totals.panelSen > 0
            ? totals.paidSen > 0
                ? 'Settled (part by Panel)'
                : 'Covered by Panel'
            : 'Paid';
</script>

<template>
    <Head title="Bill (read only)" />
    <main class="mx-auto grid w-full max-w-4xl gap-4 px-4 py-6">
        <ReadOnlyBanner>
            <Link
                :href="backUrl"
                class="inline-flex h-11 items-center gap-2 rounded-md border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                data-testid="review-back"
                ><ArrowLeft class="size-4" aria-hidden="true" />Back</Link
            >
        </ReadOnlyBanner>

        <PageHeader
            title="Bill"
            :description="`${review.patient.name} · ${review.patient.patientNumber} · ${review.visit.number}`"
        />

        <p
            v-if="!review.invoice"
            class="rounded-md border p-4 text-sm text-muted-foreground dark:border-foreground/20"
            data-testid="review-no-invoice"
        >
            Not finalised yet. The bill shows here once the counter finalises
            it.
        </p>

        <template v-else>
            <section
                class="grid gap-1 rounded-md border p-4 text-sm dark:border-foreground/20"
                aria-labelledby="review-invoice"
            >
                <h2 id="review-invoice" class="font-semibold">
                    Invoice {{ review.invoice.number }}
                </h2>
                <p class="text-muted-foreground" data-testid="review-status">
                    {{
                        review.invoice.status === 'voided'
                            ? 'Void'
                            : review.invoice.totals
                              ? statusLabel(review.invoice.totals)
                              : 'Finalised'
                    }}
                    <template v-if="review.invoice.finalizedAt">
                        · Finalised {{ review.invoice.finalizedAt }}</template
                    >
                </p>
            </section>

            <section
                v-if="review.invoice.lines.length"
                class="rounded-md border text-sm dark:border-foreground/20"
                aria-labelledby="review-lines"
            >
                <h2 id="review-lines" class="px-4 pt-4 font-semibold">Items</h2>
                <ul class="divide-y dark:divide-foreground/10">
                    <li
                        v-for="(line, index) in review.invoice.lines"
                        :key="index"
                        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-2"
                    >
                        <span class="min-w-0 flex-1">{{ line.name }}</span>
                        <span class="text-muted-foreground tabular-nums"
                            >{{ quantity(line.quantity) }} ×
                            {{ myr(line.unitPriceSen) }}</span
                        >
                        <span
                            class="w-28 text-right font-medium whitespace-nowrap tabular-nums"
                            >{{ myr(line.totalSen) }}</span
                        >
                    </li>
                </ul>
            </section>

            <section
                v-if="review.invoice.totals"
                class="grid gap-1 rounded-md border p-4 text-sm dark:border-foreground/20"
                aria-labelledby="review-totals"
                data-testid="review-totals"
            >
                <h2 id="review-totals" class="mb-1 font-semibold">Totals</h2>
                <div
                    v-if="review.invoice.totals.adjustmentSen !== 0"
                    class="flex justify-between gap-4"
                >
                    <span class="text-muted-foreground">Subtotal</span>
                    <span class="tabular-nums">{{
                        myr(review.invoice.totals.subtotalSen)
                    }}</span>
                </div>
                <div
                    v-if="review.invoice.totals.adjustmentSen !== 0"
                    class="flex justify-between gap-4"
                >
                    <span class="text-muted-foreground">{{
                        review.invoice.totals.adjustmentSen < 0
                            ? 'Discount'
                            : 'Surcharge'
                    }}</span>
                    <span class="tabular-nums">{{
                        myr(review.invoice.totals.adjustmentSen)
                    }}</span>
                </div>
                <div class="flex justify-between gap-4 font-medium">
                    <span>Total</span>
                    <span class="tabular-nums">{{
                        myr(review.invoice.totals.totalSen)
                    }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Paid</span>
                    <span class="tabular-nums">{{
                        myr(review.invoice.totals.paidSen)
                    }}</span>
                </div>
                <div
                    v-if="review.invoice.totals.panelSen > 0"
                    class="flex justify-between gap-4"
                >
                    <span class="text-muted-foreground">Panel cover</span>
                    <span class="tabular-nums">{{
                        myr(review.invoice.totals.panelSen)
                    }}</span>
                </div>
                <div
                    v-if="review.invoice.totals.payLaterSen > 0"
                    class="flex justify-between gap-4"
                >
                    <span class="text-muted-foreground">Pay later</span>
                    <span class="tabular-nums">{{
                        myr(review.invoice.totals.payLaterSen)
                    }}</span>
                </div>
                <div class="flex justify-between gap-4 font-medium">
                    <span>Due now</span>
                    <span class="tabular-nums">{{
                        myr(review.invoice.totals.dueNowSen)
                    }}</span>
                </div>
            </section>

            <section
                v-if="review.invoice.payments.length"
                class="rounded-md border text-sm dark:border-foreground/20"
                aria-labelledby="review-payments"
            >
                <h2 id="review-payments" class="px-4 pt-4 font-semibold">
                    Payments
                </h2>
                <ul class="divide-y dark:divide-foreground/10">
                    <li
                        v-for="(payment, index) in review.invoice.payments"
                        :key="index"
                        class="grid gap-1 px-4 py-2 sm:grid-cols-[1fr_auto] sm:items-baseline"
                        data-testid="review-payment"
                    >
                        <span class="min-w-0">
                            {{ payment.receivedAt }} · {{ payment.method }}
                            <span class="text-muted-foreground"
                                >· {{ payment.recordedBy }}</span
                            >
                            <span
                                v-if="payment.status === 'reversed'"
                                class="text-muted-foreground"
                            >
                                · Reversed</span
                            >
                        </span>
                        <span
                            class="font-medium whitespace-nowrap tabular-nums sm:text-right"
                            >{{ myr(payment.amountSen) }}</span
                        >
                    </li>
                </ul>
            </section>
        </template>
    </main>
</template>
