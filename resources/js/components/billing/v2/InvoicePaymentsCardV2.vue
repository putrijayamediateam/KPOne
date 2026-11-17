<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import V2Chip from '@/components/v2/V2Chip.vue';
import { formatStatusLabel, statusTone } from '@/lib/presentation';
import { myr } from '@/types/billing';
import type { CompletedInvoicePage } from '@/types/completedInvoice';

/**
 * BV2-3: the Payments card of the Version 2 completed-visit Invoice (`pages/Billing/V2/Invoice.vue`). Read only:
 * every amount comes from `invoice.financial.payments` through `myr()`, with the same words as Classic. The page shows
 * it in the right column from 1280 px and under the items below that width; only one is visible at a time.
 */
defineProps<{ invoice: CompletedInvoicePage }>();

// BIL-25 (Siti): a reversed receipt is a settled correction, so it stays grey.
const receiptChip = (status: string) => {
    const tone = status === 'reversed' ? 'neutral' : statusTone(status);

    return {
        label: formatStatusLabel(status),
        tone: (tone === 'accent' ? 'info' : tone) as
            'neutral' | 'info' | 'success' | 'warning' | 'danger',
    };
};
</script>

<template>
    <section
        v-if="invoice.financial"
        class="rounded-[14px] border bg-card p-4 sm:p-5"
        data-testid="invoice-payments"
    >
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-base font-semibold">Payments</h2>
            <Link
                v-if="invoice.links.payments"
                :href="invoice.links.payments"
                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                >Manage</Link
            >
        </div>
        <p
            v-if="!invoice.financial.payments.length"
            class="mt-2 text-sm text-muted-foreground"
        >
            No payments recorded.
        </p>
        <ul v-else class="mt-2 divide-y text-sm">
            <li
                v-for="payment in invoice.financial.payments"
                :key="payment.receiptNumber"
                class="grid gap-1 py-3"
                data-testid="invoice-payment"
            >
                <div class="flex justify-between gap-2">
                    <span class="font-medium break-words">{{
                        payment.method
                    }}</span>
                    <span class="whitespace-nowrap tabular-nums">{{
                        myr(payment.amountSen)
                    }}</span>
                </div>
                <div
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-muted-foreground"
                >
                    <span class="whitespace-nowrap">{{
                        payment.receiptNumber
                    }}</span>
                    <span>{{ payment.receivedAt }}</span>
                    <V2Chip v-bind="receiptChip(payment.status)" />
                </div>
                <p
                    v-if="payment.refundedSen"
                    class="text-muted-foreground tabular-nums"
                    data-testid="payment-refunded"
                >
                    Refunded {{ myr(payment.refundedSen) }} ·
                    {{ myr(payment.amountSen - payment.refundedSen) }}
                    kept
                </p>
                <p
                    class="break-words text-muted-foreground"
                    data-testid="payment-recorded-by"
                >
                    Recorded by {{ payment.recordedBy }}
                </p>
            </li>
        </ul>
    </section>
</template>
