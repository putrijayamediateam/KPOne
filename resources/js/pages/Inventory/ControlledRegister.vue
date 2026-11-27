<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ActionLink } from '@/components/ui/action-link';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';

// STK-24: the controlled-drug register, read from the stock ledger. DRAFT pending pharmacist review.
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
</script>

<template>
    <Head title="Controlled register" />
    <main
        class="mx-auto w-full max-w-6xl space-y-6 px-4 py-4 sm:px-6 lg:px-8 lg:py-6"
    >
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Controlled register"
            :description="`Controlled medicines at ${register.branchName}, from the stock records.`"
        />
        <p
            class="rounded-xl border border-warning/25 bg-warning-wash p-3 text-sm font-medium text-warning-strong"
            data-testid="cd-draft-banner"
        >
            Draft register — pending pharmacist review. Not a statutory register
            until a pharmacist approves its format.
        </p>
        <p class="text-sm text-muted-foreground">
            Every movement in or out of this branch is listed with a running
            balance. A check is signed by a second person: not anyone who
            recorded a movement since the last check, and not whoever did the
            last check. A check never changes stock.
        </p>

        <p
            v-if="!register.rows.length"
            class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
        >
            No medicine is marked controlled. A medicine appears here once it is
            marked controlled in the Medicine catalogue and linked to a SKU.
        </p>

        <template v-else>
            <ul class="space-y-2 lg:hidden" aria-label="Controlled medicines">
                <li
                    v-for="row in register.rows"
                    :key="row.skuPublicId"
                    class="space-y-2 rounded-xl border bg-card p-4"
                >
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ row.medicine }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ row.skuCode }}
                        </p>
                    </div>
                    <p class="text-sm">
                        On hand:
                        <span class="font-medium tabular-nums">{{
                            row.blind
                                ? 'Shown after your count'
                                : `${row.balance} ${row.stockUnit}`
                        }}</span>
                    </p>
                    <StatusBadge
                        :status="row.status"
                        :label="statusLabel(row)"
                        :tone="statusTone(row)"
                    />
                    <StatusBadge
                        v-if="row.reasonNeeded"
                        status="reason-needed"
                        label="Difference: reason needed"
                        tone="warning"
                        data-testid="cd-row-reason-needed"
                    />
                    <ActionLink
                        :href="`/inventory/controlled-register/${row.skuPublicId}`"
                        variant="outline"
                        class="min-h-11 w-full"
                        :aria-label="`Open register for ${row.medicine}`"
                        >Open register</ActionLink
                    >
                </li>
            </ul>
            <div
                class="hidden overflow-x-auto rounded-xl border bg-card lg:block"
            >
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Controlled medicines
                    </caption>
                    <thead
                        class="bg-card-quiet text-left text-muted-foreground"
                    >
                        <tr>
                            <th scope="col" class="p-3 font-medium">
                                Medicine
                            </th>
                            <th scope="col" class="p-3 font-medium">SKU</th>
                            <th scope="col" class="p-3 text-right font-medium">
                                On hand
                            </th>
                            <th scope="col" class="p-3 font-medium">Check</th>
                            <th scope="col" class="p-3 font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in register.rows"
                            :key="row.skuPublicId"
                            class="border-t"
                        >
                            <td class="p-3 font-medium">{{ row.medicine }}</td>
                            <td class="p-3 whitespace-nowrap">
                                {{ row.skuCode }}
                            </td>
                            <td
                                class="p-3 text-right whitespace-nowrap tabular-nums"
                            >
                                {{
                                    row.blind
                                        ? 'Shown after your count'
                                        : `${row.balance} ${row.stockUnit}`
                                }}
                            </td>
                            <td class="p-3">
                                <div class="flex flex-wrap gap-1">
                                    <StatusBadge
                                        :status="row.status"
                                        :label="statusLabel(row)"
                                        :tone="statusTone(row)"
                                    />
                                    <StatusBadge
                                        v-if="row.reasonNeeded"
                                        status="reason-needed"
                                        label="Difference: reason needed"
                                        tone="warning"
                                    />
                                </div>
                            </td>
                            <td class="p-3 text-right">
                                <ActionLink
                                    :href="`/inventory/controlled-register/${row.skuPublicId}`"
                                    variant="outline"
                                    class="min-h-9"
                                    :aria-label="`Open register for ${row.medicine}`"
                                    >Open register</ActionLink
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </main>
</template>
