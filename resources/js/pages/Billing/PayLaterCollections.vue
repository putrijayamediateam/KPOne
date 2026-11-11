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
        payLaterDue?: Record<string, string>;
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

// BIL-23: money due now on a completed visit (after a receipt correction) and what the bill still owes in all.
const dueNowOf = (row: { visitNumber: string }) =>
    props.collections.dueNow?.[row.visitNumber] ?? 0;
const owedOf = (row: { visitNumber: string; remainingSen: number }) =>
    row.remainingSen + dueNowOf(row);
// Money due now with no pay-later date counts from the visit day (owner decision, BIL-23).
const daysSinceVisit = (days: number) =>
    `${days} ${days === 1 ? 'day' : 'days'} since visit`;

const visit = (page: number, overdueOnly = props.collections.overdueOnly) =>
    router.get(
        '/pay-later-collections',
        overdueOnly ? { page, overdue: 1 } : { page },
        { preserveScroll: true },
    );
</script>

<template>
    <Head title="Bills to collect" />
    <main class="mx-auto w-full max-w-[1600px] space-y-4 px-4 py-6">
        <PageHeader
            title="Bills to collect"
            :description="`${collections.branch} · Money patients still owe: approved pay later and money due on completed visits, oldest first`"
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
                <p class="text-xs text-muted-foreground">Still owed</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ myr(collections.all.totalSen) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ collections.all.count }} patient
                    {{ collections.all.count === 1 ? 'bill' : 'bills' }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    Owed on bills past due (today is {{ collections.today }})
                </p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ myr(collections.all.overdueSen) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ collections.all.overdueCount }} patient
                    {{ collections.all.overdueCount === 1 ? 'bill' : 'bills' }}
                </p>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <label class="flex min-h-11 items-center gap-2 text-sm">
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
                does not record anything. Due now is money that should have been
                paid at the visit; it counts from the visit day. A payment pays
                due now before pay later.
            </p>
        </div>

        <EmptyState
            v-if="collections.data.length === 0"
            title="Nothing to collect"
            :description="
                collections.overdueOnly
                    ? `No bill in ${collections.branch} is past due.`
                    : `No patient owes money in ${collections.branch}.`
            "
        />

        <section
            v-if="collections.data.length > 0"
            class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden"
            aria-label="Bills to collect"
        >
            <article
                v-for="row in collections.data"
                :key="row.invoiceNumber"
                class="grid min-w-0 grid-cols-1 gap-2 rounded-xl border bg-card p-4"
                data-testid="pay-later-card"
            >
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <p class="text-xs text-muted-foreground">Still owed</p>
                        <p class="text-xl font-semibold tabular-nums">
                            {{ myr(owedOf(row)) }}
                        </p>
                    </div>
                    <span
                        v-if="row.daysOverdue > 0 && dueNowOf(row) > 0"
                        class="block w-fit rounded-full border border-amber-300 bg-amber-50 px-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                        data-testid="bill-days-since-visit"
                        >{{ daysSinceVisit(row.daysOverdue) }}</span
                    >
                    <span
                        v-else-if="row.daysOverdue > 0"
                        class="block w-fit rounded-full border border-amber-300 bg-amber-50 px-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                        >{{ row.daysOverdue }}
                        {{ row.daysOverdue === 1 ? 'day' : 'days' }} past
                        due</span
                    >
                </div>
                <dl
                    class="grid gap-1 text-xs"
                    data-testid="bill-card-breakdown"
                >
                    <div
                        v-if="row.remainingSen > 0"
                        class="flex flex-wrap justify-between gap-x-3"
                    >
                        <dt class="text-muted-foreground">Pay later</dt>
                        <dd class="tabular-nums">
                            {{ myr(row.remainingSen) }}
                            <span
                                v-if="
                                    collections.payLaterDue?.[row.visitNumber]
                                "
                                class="text-muted-foreground"
                                data-testid="bill-card-pay-later-due"
                                >· due
                                {{
                                    collections.payLaterDue[row.visitNumber]
                                }}</span
                            >
                        </dd>
                    </div>
                    <div
                        v-if="dueNowOf(row) > 0"
                        class="flex flex-wrap justify-between gap-x-3"
                    >
                        <dt class="text-muted-foreground">Due now</dt>
                        <dd class="tabular-nums">{{ myr(dueNowOf(row)) }}</dd>
                    </div>
                </dl>
                <p
                    v-if="row.remainingSen > 0 && dueNowOf(row) > 0"
                    class="text-xs text-muted-foreground"
                    data-testid="pay-later-card-due-now"
                >
                    {{ myr(dueNowOf(row)) }} is also due now. A payment pays
                    that first.
                </p>
                <div class="min-w-0">
                    <p class="font-medium break-words">{{ row.patientName }}</p>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ row.patientNumber }}
                    </p>
                    <MergedFromNote
                        v-if="row.mergedFrom"
                        :numbers="[row.mergedFrom]"
                    />
                </div>
                <dl class="grid gap-1 text-xs">
                    <div class="flex flex-wrap justify-between gap-x-3">
                        <dt class="text-muted-foreground">Due date</dt>
                        <dd>{{ dueNowOf(row) > 0 ? 'Now' : row.dueDate }}</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-x-3">
                        <dt class="text-muted-foreground">Visit date</dt>
                        <dd>{{ row.visitDate }}</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-x-3">
                        <dt class="text-muted-foreground">Visit</dt>
                        <dd>{{ row.visitNumber }}</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-x-3">
                        <dt class="text-muted-foreground">Invoice</dt>
                        <dd class="font-mono">{{ row.invoiceNumber }}</dd>
                    </div>
                    <div
                        v-if="row.approvedSen > 0"
                        class="flex flex-wrap justify-between gap-x-3"
                    >
                        <dt class="text-muted-foreground">
                            Approved pay later
                        </dt>
                        <dd class="tabular-nums">
                            {{
                                row.approvedSen > 0 ? myr(row.approvedSen) : '–'
                            }}
                        </dd>
                    </div>
                </dl>
                <ActionLink
                    :href="row.url"
                    variant="secondary"
                    class="min-h-11 w-full"
                    :aria-label="`Open billing for ${row.visitNumber}`"
                >
                    Open billing
                </ActionLink>
            </article>
        </section>

        <div v-if="collections.data.length > 0" class="hidden min-w-0 lg:block">
            <OperationalTable
                label="Bills to collect"
                :columns="9"
                min-width="1120px"
            >
                <template #head>
                    <tr>
                        <th scope="col">Patient</th>
                        <th scope="col">Visit / Invoice</th>
                        <th scope="col">Visit date</th>
                        <th scope="col">Due date</th>
                        <th scope="col" class="text-right">
                            Approved pay later
                        </th>
                        <th scope="col" class="text-right">Pay later</th>
                        <th scope="col" class="text-right">Due now</th>
                        <th scope="col" class="text-right">Still owed</th>
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
                            {{ dueNowOf(row) > 0 ? 'Now' : row.dueDate }}
                            <span
                                v-if="row.daysOverdue > 0 && dueNowOf(row) > 0"
                                class="mt-1 block w-fit rounded-full border border-amber-300 bg-amber-50 px-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                                >{{ daysSinceVisit(row.daysOverdue) }}</span
                            >
                            <span
                                v-else-if="row.daysOverdue > 0"
                                class="mt-1 block w-fit rounded-full border border-amber-300 bg-amber-50 px-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                                >{{ row.daysOverdue }}
                                {{ row.daysOverdue === 1 ? 'day' : 'days' }}
                                past due</span
                            >
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{
                                row.approvedSen > 0 ? myr(row.approvedSen) : '–'
                            }}
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{
                                row.remainingSen > 0
                                    ? myr(row.remainingSen)
                                    : '–'
                            }}
                            <span
                                v-if="
                                    collections.payLaterDue?.[row.visitNumber]
                                "
                                class="block text-xs text-muted-foreground"
                                data-testid="bill-pay-later-due"
                                >due
                                {{
                                    collections.payLaterDue[row.visitNumber]
                                }}</span
                            >
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            <span
                                v-if="dueNowOf(row) > 0"
                                data-testid="pay-later-due-now"
                                >{{ myr(dueNowOf(row)) }}</span
                            >
                            <span v-else>–</span>
                        </td>
                        <td
                            class="text-right font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{ myr(owedOf(row)) }}
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
        </div>

        <CompactPagination
            v-if="collections.shown.count > 0"
            :current-page="collections.currentPage"
            :last-page="collections.lastPage"
            :total="collections.shown.count"
            @change="(page: number) => visit(page)"
        />
    </main>
</template>
