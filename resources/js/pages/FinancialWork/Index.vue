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
        ? 'Current Panel responsibility reviews'
        : 'Current finalized invoices',
);
const columnCount = computed(() => (panel.value ? 7 : 11));
const turnPage = (page: number) =>
    router.get(panel.value ? '/panel-responsibility' : '/financial-work', {
        page,
    });
</script>

<template>
    <Head :title="title" />
    <main class="mx-auto w-full max-w-[1600px] space-y-4 px-4 py-6">
        <PageHeader :title="title" :description="description">
            <template #actions>
                <ActionLink
                    href="/workspace"
                    variant="secondary"
                    class="min-h-11 lg:min-h-9"
                >
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

        <section
            v-if="work.data.length > 0"
            class="grid min-w-0 gap-3 md:grid-cols-2 lg:hidden"
            aria-label="Work to review"
        >
            <article
                v-for="row in work.data"
                :key="row.invoiceNumber"
                class="min-w-0 rounded-xl border bg-card p-4"
                data-testid="financial-work-card"
            >
                <div
                    class="flex min-w-0 flex-wrap items-start justify-between gap-2"
                >
                    <div class="min-w-0 break-words">
                        <h2 class="font-semibold">{{ row.patientName }}</h2>
                        <p class="text-xs text-muted-foreground">
                            {{ row.visitNumber }}
                        </p>
                    </div>
                    <StatusBadge :status="row.status" />
                </div>
                <dl
                    class="mt-3 grid min-w-0 grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm"
                >
                    <dt class="text-muted-foreground">Invoice</dt>
                    <dd class="text-right font-mono text-xs break-words">
                        {{ row.invoiceNumber }}
                    </dd>
                    <dt class="text-muted-foreground">Branch</dt>
                    <dd class="text-right break-words">{{ row.branch }}</dd>
                    <template v-if="panel">
                        <dt class="text-muted-foreground">Responsibility</dt>
                        <dd class="text-right font-semibold tabular-nums">
                            {{ myr(row.amountSen!) }}
                        </dd>
                        <dt class="text-muted-foreground">Requested</dt>
                        <dd class="text-right break-words">
                            {{ row.requestedAt }}
                        </dd>
                    </template>
                    <template v-else-if="row.state">
                        <dt class="text-muted-foreground">Invoice Total</dt>
                        <dd class="text-right tabular-nums">
                            {{ myr(row.state.total) }}
                        </dd>
                        <dt class="text-muted-foreground">Received</dt>
                        <dd class="text-right tabular-nums">
                            {{ myr(row.state.self_pay) }}
                        </dd>
                        <dt class="text-muted-foreground">Panel</dt>
                        <dd class="text-right tabular-nums">
                            {{ myr(row.state.panel) }}
                        </dd>
                        <dt class="text-muted-foreground">Pay Later</dt>
                        <dd class="text-right tabular-nums">
                            {{ myr(row.state.deferred) }}
                        </dd>
                        <dt class="text-muted-foreground">Outstanding</dt>
                        <dd class="text-right font-medium tabular-nums">
                            {{ myr(outstandingSen(row.state)) }}
                        </dd>
                        <dt class="text-muted-foreground">Due Now</dt>
                        <dd class="text-right font-semibold tabular-nums">
                            {{ myr(row.state.due_now) }}
                        </dd>
                    </template>
                </dl>
                <ActionLink
                    :href="row.reviewUrl"
                    variant="secondary"
                    class="mt-4 min-h-11 w-full"
                    :aria-label="`Review ${row.invoiceNumber}`"
                    >Review</ActionLink
                >
            </article>
        </section>
        <div v-if="work.data.length > 0" class="hidden space-y-2 lg:block">
            <p
                id="financial-work-scroll-help"
                class="text-sm text-muted-foreground"
            >
                Scroll horizontally to see every column and Review. With the
                table focused, use the left and right arrow keys.
            </p>
            <OperationalTable
                :label="title"
                :columns="columnCount"
                :min-width="panel ? '900px' : '1320px'"
                aria-describedby="financial-work-scroll-help"
                class="focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <template #head>
                    <tr>
                        <th scope="col">Patient / Visit</th>
                        <th scope="col">Invoice</th>
                        <th scope="col">Branch</th>
                        <template v-if="panel">
                            <th scope="col" class="text-right">
                                Responsibility
                            </th>
                            <th scope="col">Requested</th>
                        </template>
                        <template v-else>
                            <th scope="col" class="text-right">
                                Invoice Total
                            </th>
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
                        <td class="font-mono text-xs">
                            {{ row.invoiceNumber }}
                        </td>
                        <td>{{ row.branch }}</td>
                        <template v-if="panel">
                            <td class="text-right font-medium tabular-nums">
                                {{ myr(row.amountSen!) }}
                            </td>
                            <td class="whitespace-nowrap">
                                {{ row.requestedAt }}
                            </td>
                        </template>
                        <template v-else-if="row.state">
                            <td
                                class="text-right whitespace-nowrap tabular-nums"
                            >
                                {{ myr(row.state.total) }}
                            </td>
                            <td
                                class="text-right whitespace-nowrap tabular-nums"
                            >
                                {{ myr(row.state.self_pay) }}
                            </td>
                            <td
                                class="text-right whitespace-nowrap tabular-nums"
                            >
                                {{ myr(row.state.panel) }}
                            </td>
                            <td
                                class="text-right whitespace-nowrap tabular-nums"
                            >
                                {{ myr(row.state.deferred) }}
                            </td>
                            <td
                                class="text-right whitespace-nowrap tabular-nums"
                            >
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
                                class="min-h-11 lg:min-h-9"
                                :aria-label="`Review ${row.invoiceNumber}`"
                            >
                                Review
                            </ActionLink>
                        </td>
                    </tr>
                </template>
            </OperationalTable>
        </div>

        <CompactPagination
            v-if="work.total > 0"
            class="[&_button]:min-h-11 lg:[&_button]:min-h-9"
            :current-page="work.currentPage"
            :last-page="work.lastPage"
            :total="work.total"
            @change="turnPage"
        />
        <details class="rounded-xl border bg-card px-4">
            <summary
                class="min-h-11 cursor-pointer py-3 text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                About this list
            </summary>
            <p class="pb-4 text-sm leading-relaxed text-muted-foreground">
                {{
                    panel
                        ? 'Review Panel responsibility using the existing authorised billing view. Claims submission is a separate workflow.'
                        : 'Current finalized invoices at this branch. Use Review to open the authorised billing view. This list does not record payments.'
                }}
            </p>
        </details>
    </main>
</template>
