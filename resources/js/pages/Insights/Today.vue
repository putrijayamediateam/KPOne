<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import ComparisonCard from '@/components/insights/ComparisonCard.vue';
import HourlyTrendChart from '@/components/insights/HourlyTrendChart.vue';
import RankingCard from '@/components/insights/RankingCard.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { OperationalSelect } from '@/components/ui/select';
import type { InsightRankingItem, TodayInsightsReport } from '@/types/insights';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Insights', href: '/insights/today' },
            { title: 'Today', href: '/insights/today' },
        ],
    },
});

const props = defineProps<{
    branch: string;
    branches: { code: string; name: string }[];
    report: TodayInsightsReport;
}>();

const sections = [
    { id: 'today', title: 'Today', href: '/insights/today' },
    { id: 'sales', title: 'Sales', href: '/insights/sales' },
    { id: 'in-clinic', title: 'In-clinic', href: '/insights/in-clinic' },
    { id: 'payments', title: 'Payments', href: '/insights/payments' },
    { id: 'inventory', title: 'Inventory', href: '/insights/inventory' },
    { id: 'patients', title: 'Patients', href: '/insights/patients' },
];

const branchSelection = ref(props.branch);
const branchOptions = computed(() => [
    { value: 'all', label: 'All branches' },
    ...props.branches.map((branch) => ({
        value: branch.code,
        label: branch.name,
    })),
]);
const detailOpen = ref(false);
const detailTitle = ref('');
const detailKind = ref('');
const detailRows = ref<InsightRankingItem[]>([]);
const search = ref('');
const sortKey = ref<'name' | 'salesSen' | 'patients' | 'unitsSold'>('salesSen');
const descending = ref(true);
let refreshTimer: number | undefined;

const filteredRows = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    return [...detailRows.value]
        .filter((row) => !query || row.name.toLocaleLowerCase().includes(query))
        .sort((left, right) => {
            const a = left[sortKey.value] ?? 0;
            const b = right[sortKey.value] ?? 0;
            const comparison =
                typeof a === 'string'
                    ? a.localeCompare(String(b))
                    : Number(a) - Number(b);

            return descending.value ? -comparison : comparison;
        });
});

const salesTrendSummary = computed(() => {
    const amounts = props.report.salesTrend.map((point) => point.salesSen);
    const highest = amounts.length === 0 ? 0 : Math.max(...amounts);
    const lowest = amounts.length === 0 ? 0 : Math.min(...amounts);
    const average =
        amounts.length === 0
            ? 0
            : Math.round(
                  amounts.reduce((sum, amount) => sum + amount, 0) /
                      amounts.length,
              );

    return `For today: highest ${money(highest)}, lowest ${money(lowest)}, average ${money(average)} per hour.`;
});

const money = (amountSen: number) =>
    new Intl.NumberFormat('en-MY', {
        style: 'currency',
        currency: 'MYR',
        maximumFractionDigits: 2,
    }).format(amountSen / 100);

const openDetails = (
    title: string,
    items: InsightRankingItem[],
    kind: string,
) => {
    detailTitle.value = title;
    detailKind.value = kind;
    detailRows.value = items;
    search.value = '';
    detailOpen.value = true;
};

const toggleSort = (key: typeof sortKey.value) => {
    if (sortKey.value === key) {
        descending.value = !descending.value;
    } else {
        sortKey.value = key;
        descending.value = key !== 'name';
    }
};

const changeBranch = () =>
    router.get(
        '/insights/today',
        branchSelection.value === 'all'
            ? {}
            : { branch: branchSelection.value },
        { preserveScroll: true, replace: true },
    );

const refresh = () => {
    if (document.visibilityState === 'visible') {
        router.reload({ only: ['report'] });
    }
};

onMounted(() => {
    refreshTimer = window.setInterval(refresh, 60_000);
});

onUnmounted(() => {
    if (refreshTimer !== undefined) {
        window.clearInterval(refreshTimer);
    }
});
</script>

<template>
    <Head title="Insights · Today" />
    <main
        class="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-5 p-4 md:gap-6 md:p-6"
    >
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <p
                    class="text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase"
                >
                    Insights · {{ report.date }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                    Today
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Sales and clinic activity, refreshed every minute.
                </p>
            </div>
            <label
                class="grid gap-1.5 text-xs font-medium text-muted-foreground"
            >
                Branch
                <OperationalSelect
                    id="insights-today-branch"
                    v-model="branchSelection"
                    label="Branch"
                    :options="branchOptions"
                    trigger-class="h-10 min-w-60"
                    @update:model-value="changeBranch"
                />
            </label>
        </header>

        <nav
            class="flex gap-1 overflow-x-auto border-b"
            aria-label="Insights reports"
        >
            <a
                v-for="item in sections"
                :key="item.id"
                :href="item.href"
                class="border-b-2 px-3 py-2 text-sm whitespace-nowrap"
                :class="
                    item.id === 'today'
                        ? 'border-primary font-medium text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
            >
                {{ item.title }}
            </a>
        </nav>

        <section
            class="grid gap-3 md:grid-cols-2"
            aria-label="New and returning sales"
        >
            <div class="rounded-xl border border-border bg-card p-4 md:p-5">
                <p class="text-xs text-muted-foreground">New sales</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ money(report.sales.newSen) }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 md:p-5">
                <p class="text-xs text-muted-foreground">Returning sales</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ money(report.sales.returningSen) }}
                </p>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-3" aria-label="Today's totals">
            <div class="rounded-xl border border-border bg-card p-4 md:p-5">
                <p class="text-xs text-muted-foreground">Total sales</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ money(report.sales.totalSen) }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 md:p-5">
                <p class="text-xs text-muted-foreground">Patients</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ report.sales.patientCount }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4 md:p-5">
                <p class="text-xs text-muted-foreground">Sales per patient</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ money(report.sales.averagePerPatientSen) }}
                </p>
            </div>
        </section>

        <section
            class="grid gap-3 lg:grid-cols-3"
            aria-label="Sales and patient comparisons with yesterday"
        >
            <ComparisonCard
                title="Average sales comparison"
                :current="report.sales.totalSen"
                :previous="report.sales.previousTotalSen"
                :money="true"
                unit=""
            />
            <ComparisonCard
                title="Average patient comparison"
                :current="report.sales.patientCount"
                :previous="report.sales.previousPatientCount"
                unit=" patients"
            />
            <ComparisonCard
                title="Average sales per patient comparison"
                :current="report.sales.averagePerPatientSen"
                :previous="report.sales.previousAveragePerPatientSen"
                :money="true"
                unit=""
            />
        </section>

        <HourlyTrendChart
            title="Sales trend"
            :summary="salesTrendSummary"
            :points="
                report.salesTrend.map((point) => ({
                    hour: point.hour,
                    value: point.salesSen,
                }))
            "
            :money="true"
        />

        <section
            class="grid gap-4 xl:grid-cols-2"
            aria-label="Today's rankings"
        >
            <RankingCard
                title="Services ranking"
                kind="service"
                :items="report.rankings.services"
                @view-all="openDetails"
            />
            <RankingCard
                title="Medication ranking"
                kind="medicine"
                :items="report.rankings.medicines"
                @view-all="openDetails"
            />
            <RankingCard
                title="Packages ranking"
                kind="package"
                :items="report.rankings.packages"
                empty-message="Package sales are not supported by the current billing catalogue."
            />
            <RankingCard
                title="Provider ranking"
                kind="provider"
                :items="report.rankings.providers"
                @view-all="openDetails"
            />
        </section>

        <section
            class="grid gap-4 xl:grid-cols-3"
            aria-label="Clinic time averages"
        >
            <div
                v-for="metric in [
                    { key: 'inClinic', title: 'Average in-clinic time' },
                    { key: 'waiting', title: 'Average waiting time' },
                    { key: 'serving', title: 'Average serving time' },
                ] as const"
                :key="metric.key"
                class="space-y-3"
            >
                <ComparisonCard
                    :title="`${metric.title} comparison`"
                    :current="report.time[metric.key].average"
                    :previous="report.time[metric.key].previousAverage"
                    unit=" min"
                />
                <HourlyTrendChart
                    :title="`${metric.title} trend`"
                    :summary="`Today · average ${report.time[metric.key].average} min; longest ${report.time[metric.key].longest} min${report.time[metric.key].longestAt ? ` at ${report.time[metric.key].longestAt}` : ''}`"
                    :points="
                        report.time[metric.key].trend.map((point) => ({
                            hour: point.hour,
                            value: point.minutes,
                        }))
                    "
                />
            </div>
        </section>
    </main>

    <Dialog v-model:open="detailOpen">
        <DialogContent
            class="flex max-h-[85vh] flex-col gap-0 overflow-hidden sm:max-w-4xl"
        >
            <DialogHeader class="pb-4">
                <DialogTitle>{{ detailTitle }}</DialogTitle>
                <DialogDescription>
                    Search and sort today's complete ranking.
                </DialogDescription>
            </DialogHeader>
            <div class="pb-3">
                <Input
                    v-model="search"
                    aria-label="Search ranking"
                    placeholder="Search…"
                />
            </div>
            <div class="min-h-0 flex-1 overflow-auto rounded-md border">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <thead class="sticky top-0 bg-muted">
                        <tr>
                            <th class="px-3 py-2">#</th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    @click="toggleSort('name')"
                                >
                                    {{
                                        detailKind === 'provider'
                                            ? 'Doctor'
                                            : 'Service / medication'
                                    }}
                                </button>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    @click="toggleSort('salesSen')"
                                >
                                    Sales
                                </button>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    @click="
                                        toggleSort(
                                            detailKind === 'service' ||
                                                detailKind === 'provider'
                                                ? 'patients'
                                                : 'unitsSold',
                                        )
                                    "
                                >
                                    {{
                                        detailKind === 'service' ||
                                        detailKind === 'provider'
                                            ? 'Patients'
                                            : 'Unit sold'
                                    }}
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(item, index) in filteredRows"
                            :key="`${item.code ?? item.name}-${index}`"
                            class="border-t"
                        >
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ index + 1 }}
                            </td>
                            <td class="px-3 py-2 font-medium">
                                {{ item.name }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ money(item.salesSen) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{
                                    detailKind === 'service' ||
                                    detailKind === 'provider'
                                        ? item.patients
                                        : item.unitsSold
                                }}
                            </td>
                        </tr>
                        <tr v-if="filteredRows.length === 0">
                            <td
                                colspan="4"
                                class="px-3 py-8 text-center text-muted-foreground"
                            >
                                No matching results.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <DialogFooter class="pt-4">
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted"
                    @click="detailOpen = false"
                >
                    Done
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
