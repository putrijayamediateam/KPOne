<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import MergedFromNote from '@/components/patient/MergedFromNote.vue';
import { ActionLink } from '@/components/ui/action-link';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { OperationalTable } from '@/components/ui/table';
import { myr } from '@/types/billing';

type Totals = {
    count: number;
    totalSen: number;
    overdueCount: number;
    overdueSen: number;
};

const props = defineProps<{
    collections: {
        branch: string;
        today: string;
        overdueOnly: boolean;
        all: Totals;
        shown: Totals;
        currentPage: number;
        lastPage: number;
        /** BIL-17: money due after a receipt correction, by visit number; it is paid before pay-later. */
        dueNow?: Record<string, number>;
        data: Array<{
            patientName: string;
            patientNumber: string;
            mergedFrom: string | null;
            visitNumber: string;
            invoiceNumber: string;
            visitDate: string;
            dueDate: string;
            daysOverdue: number;
            approvedSen: number;
            remainingSen: number;
            url: string;
        }>;
    };
}>();

const visit = (page: number, overdueOnly = props.collections.overdueOnly) =>
    router.get(
        '/pay-later-collections',
        overdueOnly ? { page, overdue: 1 } : { page },
        { preserveScroll: true },
    );
</script>

<template>
    <Head title="Pay later to collect" />
    <main class="mx-auto w-full max-w-[1600px] space-y-4 px-4 py-6">
        <PageHeader
            title="Pay later to collect"
            :description="`${collections.branch} · Approved pay-later amounts still to collect, oldest due first`"
        >
            <template #actions>
                <ActionLink href="/workspace" variant="secondary">
                    Return to Workspace
                </ActionLink>
            </template>
        </PageHeader>

        <section
            class="grid gap-3 sm:grid-cols-2"
            data-testid="pay-later-totals"
        >
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Still to collect</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ myr(collections.all.totalSen) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ collections.all.count }} patient bill(s)
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    Past due date (today is {{ collections.today }})
                </p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ myr(collections.all.overdueSen) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ collections.all.overdueCount }} patient bill(s)
                </p>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <label class="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    :checked="collections.overdueOnly"
                    data-testid="overdue-only"
                    @change="
                        visit(1, ($event.target as HTMLInputElement).checked)
                    "
                />
                Show only bills past their due date
            </label>
            <p class="text-xs text-muted-foreground">
                Open the patient's billing page to record the payment. This list
                does not record anything. It shows approved pay later only;
                other amounts due appear on the billing page.
            </p>
        </div>

        <EmptyState
            v-if="collections.data.length === 0"
            title="Nothing to collect"
            :description="
                collections.overdueOnly
                    ? `No pay-later bill in ${collections.branch} is past its due date.`
                    : `No approved pay-later amount is waiting in ${collections.branch}.`
            "
        />

        <OperationalTable
            v-else
            label="Pay later to collect"
            :columns="7"
            min-width="960px"
        >
            <template #head>
                <tr>
                    <th scope="col">Patient</th>
                    <th scope="col">Visit / Invoice</th>
                    <th scope="col">Visit date</th>
                    <th scope="col">Due date</th>
                    <th scope="col" class="text-right">Approved</th>
                    <th scope="col" class="text-right">Still to collect</th>
                    <th scope="col" class="text-right">Action</th>
                </tr>
            </template>
            <template #body>
                <tr
                    v-for="row in collections.data"
                    :key="row.invoiceNumber"
                    data-testid="pay-later-row"
                >
                    <td>
                        <span class="block font-medium text-foreground">
                            {{ row.patientName }}
                        </span>
                        <span
                            class="block font-mono text-xs text-muted-foreground"
                        >
                            {{ row.patientNumber }}
                        </span>
                        <MergedFromNote
                            v-if="row.mergedFrom"
                            :numbers="[row.mergedFrom]"
                        />
                    </td>
                    <td>
                        <span class="block">{{ row.visitNumber }}</span>
                        <span
                            class="block font-mono text-xs text-muted-foreground"
                            >{{ row.invoiceNumber }}</span
                        >
                    </td>
                    <td class="whitespace-nowrap">{{ row.visitDate }}</td>
                    <td class="whitespace-nowrap">
                        {{ row.dueDate }}
                        <span
                            v-if="row.daysOverdue > 0"
                            class="mt-1 block w-fit rounded-full border border-amber-300 bg-amber-50 px-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
                            >{{ row.daysOverdue }} day(s) past due</span
                        >
                    </td>
                    <td class="text-right whitespace-nowrap tabular-nums">
                        {{ myr(row.approvedSen) }}
                    </td>
                    <td
                        class="text-right font-semibold whitespace-nowrap tabular-nums"
                    >
                        {{ myr(row.remainingSen) }}
                        <span
                            v-if="
                                (collections.dueNow?.[row.visitNumber] ?? 0) > 0
                            "
                            class="block text-xs font-normal text-muted-foreground"
                            data-testid="pay-later-due-now"
                            >{{
                                myr(collections.dueNow?.[row.visitNumber] ?? 0)
                            }}
                            is also due now. A payment pays that first.</span
                        >
                    </td>
                    <td class="text-right">
                        <ActionLink
                            :href="row.url"
                            variant="secondary"
                            size="sm"
                            :aria-label="`Open billing for ${row.visitNumber}`"
                        >
                            Open billing
                        </ActionLink>
                    </td>
                </tr>
            </template>
        </OperationalTable>

        <CompactPagination
            v-if="collections.shown.count > 0"
            :current-page="collections.currentPage"
            :last-page="collections.lastPage"
            :total="collections.shown.count"
            @change="(page: number) => visit(page)"
        />
    </main>
</template>
