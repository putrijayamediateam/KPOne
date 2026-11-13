<script setup lang="ts">
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
            class="rounded-[14px] border bg-card p-4"
            aria-label="Submissions"
        >
            <h2 class="mb-2 text-base leading-[22px] font-semibold">
                Submissions
            </h2>
            <dl class="text-sm">
                <div
                    class="flex items-center justify-between gap-3 border-b py-2.5"
                >
                    <dt>Pending</dt>
                    <dd class="font-medium tabular-nums">
                        {{ countLabel(summary.pending.count, 'invoice') }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt>Overdue</dt>
                    <dd
                        class="font-medium tabular-nums"
                        :class="
                            summary.overdue.count > 0 ? 'text-destructive' : ''
                        "
                    >
                        {{ countLabel(summary.overdue.count, 'invoice') }}
                    </dd>
                </div>
            </dl>
        </section>
        <section class="rounded-[14px] border bg-card p-4" aria-label="Payouts">
            <h2 class="mb-2 text-base leading-[22px] font-semibold">Payouts</h2>
            <dl class="text-sm">
                <div
                    v-for="[key, label] in payouts"
                    :key="key"
                    class="flex items-center justify-between gap-3 border-b py-2.5 last:border-0"
                >
                    <dt>{{ label }}</dt>
                    <dd class="text-right tabular-nums">
                        <span class="text-muted-foreground">{{
                            countLabel(summary[key].count, 'claim')
                        }}</span>
                        <span class="ml-3 font-medium">{{
                            myr(summary[key].amountSen)
                        }}</span>
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</template>
