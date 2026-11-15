<script setup lang="ts">
import { computed } from 'vue';
import { myr } from '@/types/billing';
import type { VisitHistoryPage } from '@/types/visitHistory';

/**
 * The money card of the Version 2 patient Invoices page. Nothing is calculated here: every amount comes from
 * `history.financial` through `myr()`, in the same rows and words as Classic (`pages/Visits/History.vue`). Codes (the
 * invoice number and the receipt numbers) never break across lines. The page shows this card in two places (the rail
 * from 1280 px, and inline under the visit card below it); only one is visible at a time.
 */
const props = defineProps<{ history: VisitHistoryPage }>();

const totals = computed(() => {
    const state = props.history.financial?.state;

    return state
        ? ([
              ['Total', state.total],
              ['Self-pay received', state.self_pay],
              ['Panel responsibility', state.panel],
              ['Pay later still owed', state.deferred],
          ] as Array<[string, number]>)
        : [];
});

const card = 'rounded-[14px] border bg-card p-4 sm:p-5';
</script>

<template>
    <section v-if="history.financial" :class="card">
        <div
            class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
        >
            <h2 class="text-base font-semibold">
                Invoice
                <span class="text-sm font-medium whitespace-nowrap">{{
                    history.financial.invoiceNumber
                }}</span>
            </h2>
            <span class="text-xs text-muted-foreground capitalize">{{
                history.financial.status
            }}</span>
        </div>
        <ul v-if="history.financial.lines.length" class="mt-2 divide-y text-sm">
            <li
                v-for="(line, index) in history.financial.lines"
                :key="index"
                class="flex justify-between gap-2 py-2 max-sm:flex-col max-sm:gap-0.5 sm:items-baseline"
            >
                <span class="min-w-0 break-words">
                    {{ line.name }}
                    <span
                        class="text-xs whitespace-nowrap text-muted-foreground"
                        >{{ line.quantity }} ×
                        {{ myr(line.unitPriceSen) }}</span
                    >
                </span>
                <span class="whitespace-nowrap tabular-nums">{{
                    myr(line.totalSen)
                }}</span>
            </li>
        </ul>
        <p v-else class="mt-2 text-xs text-muted-foreground">
            Totals only for your access.
        </p>
        <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-sm">
            <template v-for="[label, amount] in totals" :key="label">
                <dt class="text-muted-foreground">{{ label }}</dt>
                <dd class="whitespace-nowrap tabular-nums">
                    {{ myr(amount) }}
                </dd>
            </template>
            <dt class="font-semibold">Due now</dt>
            <dd class="font-semibold whitespace-nowrap tabular-nums">
                {{ myr(history.financial.state.due_now) }}
            </dd>
        </dl>
        <div
            v-if="history.financial.payments.length"
            class="mt-3 border-t pt-2 text-sm"
        >
            <p
                class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
            >
                Payments
            </p>
            <ul class="grid gap-2">
                <li
                    v-for="payment in history.financial.payments"
                    :key="payment.receiptNumber"
                    class="flex flex-col gap-0.5"
                >
                    <span class="whitespace-nowrap"
                        >{{ payment.method }} ·
                        {{ payment.receiptNumber }}</span
                    >
                    <span class="text-right whitespace-nowrap tabular-nums">{{
                        myr(payment.amountSen)
                    }}</span>
                </li>
            </ul>
        </div>
    </section>
    <section v-else :class="[card, 'text-sm text-muted-foreground']">
        {{
            history.canSeeFinancial
                ? 'No invoice was issued for this visit.'
                : 'Billing details are not shown for your role.'
        }}
    </section>
</template>
