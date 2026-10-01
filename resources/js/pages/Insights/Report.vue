<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateRangePicker from '@/components/insights/DateRangePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import type {
    InsightsRangeFilters,
    InsightsRangeReport,
    RangeInsightRow,
} from '@/types/insights';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Insights', href: '/insights/today' },
            { title: 'Report', href: '/insights/today' },
        ],
    },
});

const props = defineProps<{
    filters: InsightsRangeFilters;
    branches: { code: string; name: string }[];
    doctors: { id: number; name: string; branch: string }[];
    data: {
        section: string;
        branch: string;
        from: string;
        to: string;
        previousFrom: string;
        previousTo: string;
        doctor: number | null;
        report: InsightsRangeReport;
    };
}>();

const selectedBranch = ref(props.filters.branch);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const doctor = ref(props.filters.doctor ? String(props.filters.doctor) : '');
const searches = ref<Record<string, string>>({});
const section = computed(() => props.filters.section);
const report = computed(() => props.data.report);
const branchOptions = computed(() => [
    { value: 'all', label: 'All branches' },
    ...props.branches.map((branch) => ({
        value: branch.code,
        label: branch.name,
    })),
]);
const doctorOptions = computed(() => [
    { value: '', label: 'All doctors' },
    ...props.doctors.map((item) => ({
        value: String(item.id),
        label: `${item.name}${item.branch ? ` · ${item.branch}` : ''}`,
    })),
]);

const sections = [
    { id: 'today', title: 'Today', href: '/insights/today' },
    { id: 'sales', title: 'Sales', href: '/insights/sales' },
    { id: 'in-clinic', title: 'In-clinic', href: '/insights/in-clinic' },
    { id: 'payments', title: 'Payments', href: '/insights/payments' },
    { id: 'inventory', title: 'Inventory', href: '/insights/inventory' },
    { id: 'patients', title: 'Patients', href: '/insights/patients' },
];
const titles: Record<InsightsRangeFilters['section'], string> = {
    sales: 'Sales',
    'in-clinic': 'In-clinic',
    payments: 'Payments',
    inventory: 'Inventory',
    patients: 'Patients',
};
const title = computed(() => titles[section.value]);

const money = (amountSen: number) =>
    new Intl.NumberFormat('en-MY', {
        style: 'currency',
        currency: 'MYR',
        maximumFractionDigits: 2,
    }).format(amountSen / 100);

const cardRows = computed(() => {
    const value = report.value;

    if (value.kind === 'sales') {
        return [
            { label: 'New sales', value: money(value.summary.newSen) },
            {
                label: 'Returning sales',
                value: money(value.summary.returningSen),
            },
            {
                label: 'Sales',
                value: money(value.summary.totalSen),
                previous: money(value.summary.previousTotalSen),
            },
            {
                label: 'Patients',
                value: String(value.summary.patients),
                previous: String(value.summary.previousPatients),
            },
            {
                label: 'Sales per patient',
                value: money(value.summary.perPatientSen),
                previous: money(value.summary.previousPerPatientSen),
            },
        ];
    }

    if (value.kind === 'in-clinic') {
        return [
            {
                label: 'Average in-clinic time',
                value: `${value.time.inClinic.average} min`,
                previous: `${value.time.inClinic.previousAverage} min`,
                note: `Longest ${value.time.inClinic.longest} min${value.time.inClinic.longestAt ? ` · ${value.time.inClinic.longestAt}` : ''}`,
            },
            {
                label: 'Average waiting time',
                value: `${value.time.waiting.average} min`,
                previous: `${value.time.waiting.previousAverage} min`,
                note: `Longest ${value.time.waiting.longest} min${value.time.waiting.longestAt ? ` · ${value.time.waiting.longestAt}` : ''}`,
            },
            {
                label: 'Average serving time',
                value: `${value.time.serving.average} min`,
                previous: `${value.time.serving.previousAverage} min`,
                note: `Longest ${value.time.serving.longest} min${value.time.serving.longestAt ? ` · ${value.time.serving.longestAt}` : ''}`,
            },
            { label: 'Urgent visits', value: String(value.priority.urgent) },
            { label: 'Normal visits', value: String(value.priority.normal) },
        ];
    }

    if (value.kind === 'payments') {
        return [
            {
                label: 'Self-pay sales',
                value: money(value.summary.selfPaySalesSen),
            },
            { label: 'Panel sales', value: money(value.summary.panelSalesSen) },
            {
                label: 'Payments received',
                value: money(value.summary.receivedSen),
            },
            {
                label: 'Outstanding balance',
                value: money(value.summary.outstandingSen),
            },
            {
                label: 'Outstanding invoices',
                value: String(value.summary.outstandingInvoices),
            },
        ];
    }

    if (value.kind === 'inventory') {
        return [
            {
                label: 'Low-stock items',
                value: String(value.summary.lowStockItems),
            },
            {
                label: 'Batches expiring within 30 days',
                value: String(value.summary.expiringBatchesNext30Days),
            },
            {
                label: 'Recorded wastage',
                value: `${value.summary.wastageUnits} units`,
            },
            {
                label: 'Inventory value',
                value: 'Unavailable',
                note: 'Inventory receipt costs are not recorded.',
            },
            {
                label: 'Cost of inventory sold',
                value: 'Unavailable',
                note: 'No stock-cost valuation method is recorded.',
            },
        ];
    }

    return [
        { label: 'Patients', value: String(value.summary.patients) },
        { label: 'Visits', value: String(value.summary.visits) },
        {
            label: 'Appointments',
            value: 'Unavailable',
            note: 'Appointment records are not part of the current system.',
        },
    ];
});

type TableGroup = {
    title: string;
    rows: RangeInsightRow[];
    truncated?: boolean;
};
const tableGroups = computed<TableGroup[]>(() => {
    const value = report.value;

    if (value.kind === 'sales') {
        return [
            {
                title: 'Services ranking',
                rows: value.services,
                truncated: value.rankingTruncated.services,
            },
            {
                title: 'Medication ranking',
                rows: value.medicines,
                truncated: value.rankingTruncated.medicines,
            },
            { title: 'Packages ranking', rows: value.packages },
            {
                title: 'Provider ranking',
                rows: value.providers,
                truncated: value.rankingTruncated.providers,
            },
        ];
    }

    if (value.kind === 'payments') {
        return [
            { title: 'Panel ranking', rows: value.panels },
            { title: 'Payment method', rows: value.paymentMethods },
        ];
    }

    if (value.kind === 'inventory') {
        return [
            { title: 'Medication sales ranking', rows: value.medicineSales },
        ];
    }

    return [];
});

type ChartPoint = { label: string; value: number; detail: string };
const chartPoints = computed<ChartPoint[]>(() => {
    const value = report.value;

    if (value.kind === 'sales') {
        return value.dailyTrend.map((point) => ({
            label: point.date.slice(5),
            value: point.salesSen,
            detail: money(point.salesSen),
        }));
    }

    if (value.kind === 'in-clinic') {
        return value.dailyVisits.map((point) => ({
            label: point.date.slice(5),
            value: point.patients,
            detail: `${point.patients} visits`,
        }));
    }

    if (value.kind === 'patients') {
        return Object.entries(value.age).map(([label, count]) => ({
            label,
            value: count,
            detail: `${count} patients`,
        }));
    }

    return [];
});
const chartMax = computed(() =>
    Math.max(1, ...chartPoints.value.map((point) => point.value)),
);
const hourlyPatients = computed(() =>
    report.value.kind === 'in-clinic'
        ? report.value.hourlyPatients.map((point) => ({
              label: String(point.hour).padStart(2, '0'),
              value: point.patients,
              detail: `${point.patients} visits`,
          }))
        : [],
);
const hourlyMax = computed(() =>
    Math.max(1, ...hourlyPatients.value.map((point) => point.value)),
);
const timeTrends = computed(() =>
    report.value.kind === 'in-clinic'
        ? [
              {
                  title: 'In-clinic time trend',
                  points: report.value.time.inClinic.trend,
              },
              {
                  title: 'Waiting time trend',
                  points: report.value.time.waiting.trend,
              },
              {
                  title: 'Serving time trend',
                  points: report.value.time.serving.trend,
              },
          ]
        : [],
);

const visibleGroups = computed(() => {
    return tableGroups.value.map((group) => {
        const query = (searches.value[group.title] ?? '')
            .trim()
            .toLocaleLowerCase();

        return {
            ...group,
            rows: group.rows.filter((row) =>
                row.name.toLocaleLowerCase().includes(query),
            ),
        };
    });
});

const applyFilters = () => {
    router.get(
        `/insights/${section.value}`,
        {
            branch:
                selectedBranch.value === 'all'
                    ? undefined
                    : selectedBranch.value,
            from: from.value,
            to: to.value,
            doctor: doctor.value || undefined,
        },
        { preserveScroll: true, replace: true },
    );
};

const applyDateRange = (range: { from: string; to: string }) => {
    from.value = range.from;
    to.value = range.to;
    applyFilters();
};

const formatDate = (value: string) =>
    new Date(`${value}T12:00:00`).toLocaleDateString('en-MY', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const occupancy = computed(() =>
    report.value.kind === 'in-clinic' ? report.value.doctorOccupancy : {},
);
const occupancyMax = computed(() =>
    Math.max(
        1,
        ...Object.values(occupancy.value).flatMap((hours) =>
            Object.values(hours),
        ),
    ),
);
</script>

<template>
    <Head :title="`Insights · ${title}`" />
    <main
        class="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-5 p-4 md:gap-6 md:p-6"
    >
        <PageHeader
            :title="title"
            :description="`${formatDate(data.from)}${data.from === data.to ? '' : ` – ${formatDate(data.to)}`} · Compared with ${formatDate(data.previousFrom)}${data.previousFrom === data.previousTo ? '' : ` – ${formatDate(data.previousTo)}`}`"
        />

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
                    item.id === section
                        ? 'border-primary font-medium text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
            >
                {{ item.title }}
            </a>
        </nav>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4"
            @submit.prevent="applyFilters"
        >
            <label class="grid gap-1 text-xs font-medium">
                Branch
                <OperationalSelect
                    id="insights-filter-branch"
                    v-model="selectedBranch"
                    label="Branch"
                    :options="branchOptions"
                    trigger-class="h-10 min-w-52"
                />
            </label>
            <label
                v-if="section !== 'inventory'"
                class="grid gap-1 text-xs font-medium"
            >
                Doctor
                <OperationalSelect
                    id="insights-filter-doctor"
                    v-model="doctor"
                    label="Doctor"
                    :options="doctorOptions"
                    trigger-class="h-10 min-w-52"
                />
            </label>
            <label class="grid gap-1 text-xs font-medium">
                Date range
                <DateRangePicker
                    :from="from"
                    :to="to"
                    @change="applyDateRange"
                />
            </label>
            <Button type="submit">Apply filters</Button>
        </form>

        <section
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
            :aria-label="`${title} summary`"
        >
            <article
                v-for="card in cardRows"
                :key="card.label"
                class="min-h-28 rounded-xl border bg-card p-4"
            >
                <p class="text-xs text-muted-foreground">{{ card.label }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ card.value }}
                </p>
                <p
                    v-if="'previous' in card"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Previous period: {{ card.previous }}
                </p>
                <p
                    v-if="'note' in card"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{ card.note }}
                </p>
            </article>
        </section>

        <section
            v-if="chartPoints.length"
            class="rounded-xl border bg-card p-4 md:p-5"
        >
            <h2 class="font-semibold">
                {{
                    section === 'sales'
                        ? 'Sales trend'
                        : section === 'patients'
                          ? 'Patient age distribution'
                          : 'Daily patient visits'
                }}
            </h2>
            <div
                class="mt-5 flex h-56 items-end gap-1 overflow-x-auto border-b border-l px-2"
            >
                <div
                    v-for="point in chartPoints"
                    :key="point.label"
                    class="group flex h-full min-w-5 flex-1 flex-col justify-end"
                >
                    <div
                        class="relative w-full rounded-t bg-primary/75 hover:bg-primary"
                        :style="{
                            height: `${Math.max(point.value > 0 ? 3 : 0, (point.value / chartMax) * 100)}%`,
                        }"
                        :aria-label="`${point.label}: ${point.detail}`"
                    >
                        <span
                            class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded bg-foreground px-2 py-1 text-xs whitespace-nowrap text-background group-hover:block"
                            >{{ point.label }} · {{ point.detail }}</span
                        >
                    </div>
                    <span
                        class="mt-1 truncate text-center text-[10px] text-muted-foreground"
                        >{{ point.label }}</span
                    >
                </div>
            </div>
        </section>

        <section
            v-if="hourlyPatients.length"
            class="rounded-xl border bg-card p-4 md:p-5"
        >
            <h2 class="font-semibold">Average hourly patients</h2>
            <div
                class="mt-5 flex h-48 items-end gap-1 overflow-x-auto border-b border-l px-2"
            >
                <div
                    v-for="point in hourlyPatients"
                    :key="point.label"
                    class="group flex h-full min-w-5 flex-1 flex-col justify-end"
                >
                    <div
                        class="relative w-full rounded-t bg-primary/75 hover:bg-primary"
                        :style="{
                            height: `${Math.max(point.value > 0 ? 3 : 0, (point.value / hourlyMax) * 100)}%`,
                        }"
                        :aria-label="`${point.label}: ${point.detail}`"
                    >
                        <span
                            class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded bg-foreground px-2 py-1 text-xs whitespace-nowrap text-background group-hover:block"
                            >{{ point.label }} · {{ point.detail }}</span
                        >
                    </div>
                    <span
                        class="mt-1 text-center text-[10px] text-muted-foreground"
                        >{{ point.label }}</span
                    >
                </div>
            </div>
        </section>

        <section v-if="timeTrends.length" class="grid gap-4 xl:grid-cols-3">
            <article
                v-for="trend in timeTrends"
                :key="trend.title"
                class="rounded-xl border bg-card p-4"
            >
                <h2 class="font-semibold">{{ trend.title }}</h2>
                <div
                    class="mt-4 flex h-36 items-end gap-1 overflow-x-auto border-b border-l px-2"
                >
                    <div
                        v-for="point in trend.points"
                        :key="point.date"
                        class="group flex h-full min-w-5 flex-1 flex-col justify-end"
                    >
                        <div
                            class="relative w-full rounded-t bg-primary/75 hover:bg-primary"
                            :style="{
                                height: `${Math.max(point.minutes > 0 ? 3 : 0, (point.minutes / Math.max(1, ...trend.points.map((item) => item.minutes))) * 100)}%`,
                            }"
                            :aria-label="`${point.date}: ${point.minutes} minutes`"
                        >
                            <span
                                class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded bg-foreground px-2 py-1 text-xs whitespace-nowrap text-background group-hover:block"
                                >{{ point.date }} ·
                                {{ point.minutes }} min</span
                            >
                        </div>
                        <span
                            class="mt-1 truncate text-center text-[10px] text-muted-foreground"
                            >{{ point.date.slice(5) }}</span
                        >
                    </div>
                </div>
            </article>
        </section>

        <section
            v-if="report.kind === 'in-clinic'"
            class="grid gap-4 xl:grid-cols-2"
        >
            <article class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold">Visit priority</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Urgent {{ report.priority.urgent }} · Normal
                    {{ report.priority.normal }}
                </p>
            </article>
            <article
                class="overflow-x-auto rounded-xl border bg-card p-4 xl:col-span-2"
            >
                <h2 class="font-semibold">
                    Clinic activity by weekday and hour
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Based on registered visits; not a staffing or appointment
                    schedule.
                </p>
                <table class="mt-4 w-full min-w-[760px] text-center text-xs">
                    <thead>
                        <tr>
                            <th class="p-1 text-left">Day</th>
                            <th
                                v-for="hour in 24"
                                :key="hour"
                                class="p-1 font-normal"
                            >
                                {{ hour - 1 }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(day, dayIndex) in dayNames" :key="day">
                            <th class="p-1 text-left font-normal">{{ day }}</th>
                            <td v-for="hour in 24" :key="hour" class="p-1">
                                <span
                                    class="block h-5 rounded-sm"
                                    :style="{
                                        backgroundColor: `color-mix(in srgb, var(--primary) ${Math.round(((report.doctorOccupancy[dayIndex]?.[hour - 1] ?? 0) / occupancyMax) * 80)}%, white)`,
                                    }"
                                    :title="`${day} ${hour - 1}:00 · ${report.doctorOccupancy[dayIndex]?.[hour - 1] ?? 0} visits`"
                                ></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>
        </section>

        <section
            v-if="report.kind === 'patients'"
            class="grid gap-4 xl:grid-cols-2"
        >
            <article class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold">Gender</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div
                        v-for="(count, label) in report.gender"
                        :key="label"
                        class="flex justify-between border-b py-2"
                    >
                        <dt class="capitalize">{{ label }}</dt>
                        <dd class="font-medium tabular-nums">{{ count }}</dd>
                    </div>
                </dl>
            </article>
            <article class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold">Visits per patient</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div
                        v-for="(count, label) in report.visitFrequency"
                        :key="label"
                        class="flex justify-between border-b py-2"
                    >
                        <dt>{{ label }}</dt>
                        <dd class="font-medium tabular-nums">{{ count }}</dd>
                    </div>
                </dl>
            </article>
            <p
                class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground xl:col-span-2"
            >
                Walk-in versus appointment is not available: the current system
                records visits but has no appointment records.
            </p>
        </section>

        <section
            v-if="tableGroups.length"
            class="grid gap-4 xl:grid-cols-2"
            aria-label="Rankings"
        >
            <article
                v-for="group in visibleGroups"
                :key="group.title"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b p-4"
                >
                    <h2 class="font-semibold">{{ group.title }}</h2>
                    <Input
                        v-model="searches[group.title]"
                        class="h-9 max-w-56"
                        :placeholder="`Search ${group.title.toLocaleLowerCase()}`"
                        :aria-label="`Search ${group.title}`"
                    />
                </div>
                <div class="overflow-x-auto p-4">
                    <p
                        v-if="group.truncated"
                        class="mb-3 rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground"
                        role="status"
                    >
                        Showing the top 100 results. Search covers only the
                        results shown.
                    </p>
                    <table class="w-full min-w-[420px] text-left text-sm">
                        <thead>
                            <tr class="border-b text-xs text-muted-foreground">
                                <th class="p-2">#</th>
                                <th class="p-2">Name</th>
                                <th class="p-2 text-right">Sales / amount</th>
                                <th class="p-2 text-right">Patients / units</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, rowIndex) in group.rows"
                                :key="`${group.title}-${row.name}`"
                                class="border-b last:border-0"
                            >
                                <td class="p-2 text-muted-foreground">
                                    {{ rowIndex + 1 }}
                                </td>
                                <td class="p-2 font-medium">{{ row.name }}</td>
                                <td class="p-2 text-right tabular-nums">
                                    {{
                                        money(
                                            row.salesSen ??
                                                row.amountSen ??
                                                row.billedSen ??
                                                0,
                                        )
                                    }}
                                </td>
                                <td class="p-2 text-right tabular-nums">
                                    <template v-if="row.billedSen !== undefined"
                                        >{{ money(row.approvedSen ?? 0) }}
                                        approved ·
                                        {{ money(row.rejectedSen ?? 0) }}
                                        rejected</template
                                    >
                                    <template v-else>{{
                                        row.patients ?? row.unitsSold ?? '—'
                                    }}</template>
                                </td>
                            </tr>
                            <tr v-if="group.rows.length === 0">
                                <td
                                    colspan="4"
                                    class="p-6 text-center text-muted-foreground"
                                >
                                    {{
                                        group.title === 'Packages ranking'
                                            ? 'Package billing is not supported.'
                                            : 'No data for this period.'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <p
            v-if="report.kind === 'sales'"
            class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
        >
            Package sales are unavailable because package line items are not
            supported by the current billing catalogue.
        </p>
    </main>
</template>
