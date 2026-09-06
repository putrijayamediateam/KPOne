<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { myr, outstandingSen } from '@/types/billing';

type FinancialState = {
    total: number;
    self_pay: number;
    panel: number;
    deferred: number;
    due_now: number;
};

const props = defineProps<{
    work: {
        mode: 'panel' | 'finance';
        branch: string;
        data: Array<{
            invoiceNumber: string;
            visitNumber: string;
            patientName: string;
            branch: string;
            status: string;
            reviewUrl: string;
            amountSen?: number;
            requestedAt?: string;
            state?: FinancialState;
        }>;
        total: number;
        currentPage: number;
        lastPage: number;
    };
}>();

const panel = computed(() => props.work.mode === 'panel');
const title = computed(() =>
    panel.value ? 'Panel Responsibility' : 'Finance / Billing',
);
const description = computed(() =>
    panel.value
        ? `${props.work.branch} · Current Panel responsibility reviews`
        : `${props.work.branch} · Current finalized invoices`,
);
const columnCount = computed(() => (panel.value ? 7 : 11));
const turnPage = (page: number) =>
    router.get(panel.value ? '/panel-claims' : '/financial-work', { page });
</script>

<template>
    <Head :title="title" />
    <main class="mx-auto w-full max-w-[1600px] space-y-4 px-4 py-6">
        <PageHeader :title="title" :description="description">
            <template #actions>
                <ActionLink href="/workspace" variant="secondary">
                    Return to Workspace
                </ActionLink>
            </template>
        </PageHeader>

        <p
            v-if="panel"
            class="rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground"
        >
            Coverage responsibility review only. Claims submission is not
            available in this workspace.
        </p>

        <EmptyState
            v-if="work.data.length === 0"
            :title="panel ? 'No Panel reviews' : 'No Finance work'"
            :description="`No authorized work is currently available in ${work.branch}.`"
        />

        <OperationalTable
            v-else
            :label="title"
            :columns="columnCount"
            :min-width="panel ? '900px' : '1320px'"
        >
            <template #head>
                <tr>
                    <th scope="col">Patient / Visit</th>
                    <th scope="col">Invoice</th>
                    <th scope="col">Branch</th>
                    <template v-if="panel">
                        <th scope="col" class="text-right">Responsibility</th>
                        <th scope="col">Requested</th>
                    </template>
                    <template v-else>
                        <th scope="col" class="text-right">Invoice Total</th>
                        <th scope="col" class="text-right">Received</th>
                        <th scope="col" class="text-right">Panel</th>
                        <th scope="col" class="text-right">Pay Later</th>
                        <th scope="col" class="text-right">Outstanding</th>
                        <th scope="col" class="text-right">Due Now</th>
                    </template>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-right">Action</th>
                </tr>
            </template>

            <template #body>
                <tr v-for="row in work.data" :key="row.invoiceNumber">
                    <td>
                        <span class="block font-medium text-foreground">
                            {{ row.patientName }}
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{ row.visitNumber }}
                        </span>
                    </td>
                    <td class="font-mono text-xs">{{ row.invoiceNumber }}</td>
                    <td>{{ row.branch }}</td>
                    <template v-if="panel">
                        <td class="text-right font-medium tabular-nums">
                            {{ myr(row.amountSen!) }}
                        </td>
                        <td class="whitespace-nowrap">{{ row.requestedAt }}</td>
                    </template>
                    <template v-else-if="row.state">
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{ myr(row.state.total) }}
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{ myr(row.state.self_pay) }}
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{ myr(row.state.panel) }}
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{ myr(row.state.deferred) }}
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{ myr(outstandingSen(row.state)) }}
                        </td>
                        <td
                            class="text-right font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{ myr(row.state.due_now) }}
                        </td>
                    </template>
                    <td><StatusBadge :status="row.status" /></td>
                    <td class="text-right">
                        <ActionLink
                            :href="row.reviewUrl"
                            variant="secondary"
                            size="sm"
                            :aria-label="`Review ${row.invoiceNumber}`"
                        >
                            Review
                        </ActionLink>
                    </td>
                </tr>
            </template>
        </OperationalTable>

        <CompactPagination
            v-if="work.total > 0"
            :current-page="work.currentPage"
            :last-page="work.lastPage"
            :total="work.total"
            @change="turnPage"
        />
    </main>
</template>
