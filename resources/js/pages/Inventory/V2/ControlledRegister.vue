<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';

/**
 * Version 2 Controlled register list (inventory V2 page 8a). Everything from `type Row` to `statusTone` is a verbatim copy
 * of Classic (a test compares them). Only the layout is new. DRAFT pending pharmacist review, as in Classic.
 */
type Row = {
    skuPublicId: string;
    medicine: string;
    skuCode: string;
    stockUnit: string;
    balance: string | null;
    blind: boolean;
    status: 'checked' | 'moved_since' | 'never' | 'no_longer_controlled';
    lastCheckedOn: string | null;
    lastOutcome: 'matches' | 'differs' | null;
    /** STK-40b: the last check differs and its reason has not been added yet. */
    reasonNeeded: boolean;
};

defineProps<{
    register: { expectedBranchId: number; branchName: string; rows: Row[] };
}>();

const statusLabel = (row: Row) =>
    row.status === 'no_longer_controlled'
        ? 'No longer marked controlled'
        : row.status === 'never'
          ? 'Never checked'
          : row.status === 'checked'
            ? 'Checked'
            : `Not checked since ${row.lastCheckedOn}`;
const statusTone = (row: Row) =>
    row.status === 'checked'
        ? ('success' as const)
        : row.status === 'no_longer_controlled'
          ? ('neutral' as const)
          : ('warning' as const);

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Controlled register' },
]);
/** A code never breaks across lines. */
const unbroken = (code: string) => code.replaceAll('-', '‑');
</script>

<template>
    <Head title="Controlled register" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Controlled register"
            :description="`Controlled medicines at ${register.branchName}, from the stock records.`"
        >
            <template #main>
                <p
                    class="flex items-start gap-2 rounded-[14px] border border-warning bg-warning/10 p-3 text-sm font-medium text-warning-strong"
                    data-testid="cd-draft-banner"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span
                        >Draft register — pending pharmacist review. Not a
                        statutory register until a pharmacist approves its
                        format.</span
                    >
                </p>
                <p class="text-sm text-muted-foreground">
                    Every movement in or out of this branch is listed with a
                    running balance. A check is signed by a second person: not
                    anyone who recorded a movement since the last check, and not
                    whoever did the last check. A check never changes stock.
                </p>
                <p
                    v-if="!register.rows.length"
                    class="rounded-[14px] border p-4 text-sm text-muted-foreground"
                >
                    No medicine is marked controlled. A medicine appears here
                    once it is marked controlled in the Medicine catalogue and
                    linked to a SKU.
                </p>
                <V2Table
                    v-else
                    label="Controlled medicines"
                    :columns="4"
                    cards="xl"
                    min-width="760px"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Medicine</th>
                            <th scope="col" class="text-right">On hand</th>
                            <th scope="col" class="w-64">Check</th>
                            <th scope="col">
                                <span class="sr-only">Action</span>
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="row in register.rows" :key="row.skuPublicId">
                            <td data-stack data-label="Medicine" data-primary>
                                <div class="min-w-0">
                                    <span
                                        class="block text-sm font-medium break-words"
                                        >{{ row.medicine }}</span
                                    >
                                    <span
                                        class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                        >{{ unbroken(row.skuCode) }}</span
                                    >
                                </div>
                            </td>
                            <td
                                data-label="On hand"
                                class="text-right text-sm whitespace-nowrap tabular-nums"
                            >
                                <span
                                    v-if="row.blind"
                                    class="font-normal whitespace-normal text-muted-foreground"
                                    >Shown after your count</span
                                >
                                <template v-else>{{
                                    quantityWithUnit(row.balance, row.stockUnit)
                                }}</template>
                            </td>
                            <td data-label="Check">
                                <div class="flex flex-wrap gap-1">
                                    <V2Chip
                                        class="whitespace-nowrap"
                                        :tone="statusTone(row)"
                                        :label="statusLabel(row)"
                                    />
                                    <V2Chip
                                        v-if="row.reasonNeeded"
                                        class="whitespace-nowrap"
                                        tone="warning"
                                        label="Difference: reason needed"
                                        data-testid="cd-row-reason-needed"
                                    />
                                </div>
                            </td>
                            <td data-actions>
                                <Link
                                    :href="`/inventory/controlled-register/${row.skuPublicId}`"
                                    class="inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-auto lg:h-9"
                                    :aria-label="`Open register for ${row.medicine}`"
                                    >Open register</Link
                                >
                            </td>
                        </tr>
                    </template>
                </V2Table>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>

<style scoped>
@media (max-width: 1279.98px) {
    :deep(.v2-cards-xl td[data-stack]) {
        flex-direction: column;
        align-items: stretch;
        gap: 0.25rem;
        text-align: left;
    }
}
</style>
