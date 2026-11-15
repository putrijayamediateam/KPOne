<script setup lang="ts">
import V2Stat from '@/components/v2/V2Stat.vue';
import { myr } from '@/types/billing';

/**
 * Version 2 Panel summary: the Submissions and Payouts groups of the Panel Claims list (the same figures and counts as
 * Classic). Used in the rail from 1536 px and inside the disclosure bar below it.
 */
type Figure = { count: number; amountSen: number };

defineProps<{
    summary: Record<
        | 'pending'
        | 'overdue'
        | 'approved'
        | 'received'
        | 'rejected'
        | 'outstanding',
        Figure
    >;
}>();

// "1 claim" / "2 claims": the same wording as the Classic list.
const countLabel = (count: number, noun: 'claim' | 'invoice') =>
    `${count} ${noun}${count === 1 ? '' : 's'}`;

const payouts = [
    ['approved', 'Approved'],
    ['received', 'Received'],
    ['rejected', 'Rejected'],
    ['outstanding', 'Outstanding'],
] as const;
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <section
            class="grid gap-3 rounded-[14px] border bg-card p-4"
            aria-label="Submissions"
        >
            <h2 class="text-base leading-[22px] font-semibold">Submissions</h2>
            <V2Stat
                label="Pending"
                :value="countLabel(summary.pending.count, 'invoice')"
                :detail="myr(summary.pending.amountSen)"
            />
            <V2Stat
                label="Overdue"
                :value="countLabel(summary.overdue.count, 'invoice')"
                :detail="myr(summary.overdue.amountSen)"
                :tone="summary.overdue.count > 0 ? 'warning' : 'neutral'"
                :status="
                    summary.overdue.count > 0 ? 'Past due date' : undefined
                "
            />
        </section>
        <section
            class="grid gap-3 rounded-[14px] border bg-card p-4"
            aria-label="Payouts"
        >
            <h2 class="text-base leading-[22px] font-semibold">Payouts</h2>
            <V2Stat
                v-for="[key, label] in payouts"
                :key="key"
                :label="label"
                :value="myr(summary[key].amountSen)"
                :detail="countLabel(summary[key].count, 'claim')"
            />
        </section>
    </div>
</template>
