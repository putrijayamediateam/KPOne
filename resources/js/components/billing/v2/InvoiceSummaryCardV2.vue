<script setup lang="ts">
import { computed } from 'vue';
import { myr } from '@/types/billing';
import type { CompletedInvoicePage } from '@/types/completedInvoice';

/**
 * BV2-3: the money summary of the Version 2 completed-visit Invoice (`pages/Billing/V2/Invoice.vue`). Nothing is
 * calculated here: every amount comes from `invoice.financial` through `myr()`, in the same rows and words as Classic
 * (`pages/Billing/Invoice.vue`). The page shows it in the right column from 1280 px and under the title below that
 * width; only one is visible at a time.
 */
const props = defineProps<{ invoice: CompletedInvoicePage }>();

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
const row = 'flex min-h-11 items-center justify-between gap-4 lg:min-h-9';
</script>

<template>
    <section
        class="rounded-[14px] border bg-card p-4 sm:p-5"
        data-testid="invoice-financial"
    >
        <template v-if="invoice.financial">
            <h2 class="text-base font-semibold">
                Invoice
                <span class="text-sm font-medium whitespace-nowrap">{{
                    invoice.financial.invoiceNumber
                }}</span>
            </h2>
            <dl class="mt-2 text-sm">
                <div :class="row">
                    <dt>Subtotal</dt>
                    <dd class="tabular-nums">
                        {{ myr(invoice.financial.adjustment.subtotalSen) }}
                    </dd>
                </div>
                <div
                    v-if="invoice.financial.adjustment.adjustmentSen !== 0"
                    :class="row"
                >
                    <dt>
                        {{
                            invoice.financial.adjustment.adjustmentSen < 0
                                ? 'Discount'
                                : 'Surcharge'
                        }}
                    </dt>
                    <dd class="tabular-nums">
                        {{
                            signedAdjustment(
                                invoice.financial.adjustment.adjustmentSen,
                            )
                        }}
                    </dd>
                </div>
                <div v-if="invoice.financial.refundCreditSen" :class="row">
                    <dt data-testid="invoice-refund-credit">Refund credit</dt>
                    <dd class="tabular-nums">
                        −{{ myr(invoice.financial.refundCreditSen) }}
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
</template>
