<script setup lang="ts">
import { myr } from '@/types/billing';
import type { InvoiceSalesReconciliation } from '@/types/insights';
defineProps<{ totals: InvoiceSalesReconciliation }>();
</script>
<template>
    <section
        class="rounded-xl border bg-card p-4 text-sm"
        aria-label="Gross to net invoice sales"
    >
        <h2 class="font-semibold">Gross to net invoice sales</h2>
        <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div>
                <dt class="text-xs text-muted-foreground">
                    Gross item sales (all lines)
                </dt>
                <dd class="mt-1 font-semibold tabular-nums">
                    {{ myr(totals.grossSen) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    Invoice surcharges
                </dt>
                <dd class="mt-1 font-semibold tabular-nums">
                    +{{ myr(totals.surchargesSen) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Invoice discounts</dt>
                <dd class="mt-1 font-semibold tabular-nums">
                    −{{ myr(totals.discountsSen) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Refund credits</dt>
                <dd class="mt-1 font-semibold tabular-nums">
                    −{{ myr(totals.refundCreditsSen) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Net invoice sales</dt>
                <dd class="mt-1 font-semibold tabular-nums">
                    {{ myr(totals.netSen) }}
                </dd>
            </div>
        </dl>
        <p class="mt-3 text-xs text-muted-foreground">
            Gross items + surcharges − discounts − refund credits = net invoice
            sales. Reversed adjustments have no effect. Items, surcharges and
            discounts use the invoice finalisation-date cohort, including
            adjustments recorded later. Refund credits count on the day the
            refund after completion was approved. Rankings remain gross item
            values; no proportional item discounts are applied.
        </p>
    </section>
</template>
