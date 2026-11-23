<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { OperationalTable } from '@/components/ui/table';

/**
 * STK-44: the medicine sales page (Director and Finance Officer). The top 25 medicines for one month, with the cost of
 * what was dispensed and the profit, and every medicine with a line sold below cost. Every figure comes from the server
 * (MedicineSalesReportService); this page only formats them. Figures are KPOne defaults, not accounting advice, so the
 * accountant banner sits above them.
 */
type Units = { unit: string; quantity: string }[];
type Row = {
    id: number;
    rank: number;
    medicine: string;
    code: string;
    units: Units;
    salesSen: number;
    costSen: number | null;
    profitSen: number | null;
    marginPercent: number | null;
    costComplete: boolean;
    estimate: boolean;
    belowLines: number;
    shortfallSen: number;
    freeLines: number;
};
type Report = {
    banner: string;
    filters: { month: string; branch: string; rank: string; belowPage: number };
    months: { value: string; label: string }[];
    monthLabel: string;
    branches: { id: string; name: string }[];
    scopeLabel: string;
    generatedAt: string;
    summary: {
        salesSen: number;
        costSen: number;
        profitSen: number;
        marginPercent: number | null;
        estimate: boolean;
        incompleteMedicines: number;
        belowLines: number;
        freeLines: number;
        lineCount: number;
    };
    rows: Row[];
    medicineCount: number;
    below: { rows: Row[]; page: number; lastPage: number; total: number };
    refused?: string[];
};

const props = defineProps<{ report: Report }>();

const refused = computed(() => props.report.refused ?? []);
const s = computed(() => props.report.summary);

// ---- Formatting only ----
const NBSP = ' ';
/** Whole sen to "RM 1,234.50"; a negative figure gets a minus sign ("-RM 12.00"), never colour alone. */
const money = (sen: number | null): string => {
    if (sen === null) {
        return '—';
    }

    const abs = Math.abs(sen);
    const ringgit = String(Math.trunc(abs / 100)).replace(
        /\B(?=(\d{3})+(?!\d))/g,
        ',',
    );

    return `${sen < 0 ? '-' : ''}RM${NBSP}${ringgit}.${String(abs % 100).padStart(2, '0')}`;
};
const percent = (value: number | null): string =>
    value === null
        ? '—'
        : `${value < 0 ? '-' : ''}${Math.abs(value).toFixed(1)}%`;
/** "(estimate)" stays joined to its figure, so it never wraps alone. */
const withEstimate = (text: string, estimate: boolean): string =>
    estimate ? `${text}${NBSP}(estimate)` : text;
const count = (n: number): string => (n === 0 ? '—' : String(n));
const NOT_COMPLETE = 'Cost not complete';
const NOT_COMPLETE_REASON =
    'Cost not complete: some stock sold has no cost recorded.';
const unbroken = (code: string) => code.replaceAll('-', '‑');

// ---- Filters: a GET form with an outline Apply and a Reset ----
const month = ref(props.report.filters.month);
const branch = ref(props.report.filters.branch);
const rank = ref(props.report.filters.rank);
const branchOptions = computed(() => [
    ...props.report.branches.map((b) => ({ value: b.id, label: b.name })),
    { value: 'all', label: 'All branches' },
]);
const busy = ref(false);
const visit = (query: Record<string, string | number>) =>
    router.get('/inventory/medicine-sales', query, {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
const apply = () =>
    visit({ month: month.value, branch: branch.value, rank: rank.value });
const belowPage = (page: number) =>
    visit({ ...props.report.filters, below_page: page });

const anyIncomplete = computed(() => s.value.incompleteMedicines > 0);
const moneyCards = computed(() => [
    { label: 'Medicine sales', value: money(s.value.salesSen) },
    {
        label: 'Cost of medicines sold',
        value: withEstimate(money(s.value.costSen), s.value.estimate),
    },
    { label: 'Gross profit', value: money(s.value.profitSen) },
    { label: 'Margin', value: percent(s.value.marginPercent) },
]);
const countCards = computed(() => [
    { label: 'Lines below cost', value: String(s.value.belowLines) },
    { label: 'Free lines', value: String(s.value.freeLines) },
]);
</script>

<template>
    <Head title="Medicine sales" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Medicine sales"
            description="Your best-selling medicines for one month, with the cost of what was dispensed and the profit."
        />

        <form
            class="flex min-w-0 flex-col gap-2"
            aria-label="Medicine sales filters"
            data-testid="sales-filters"
            @submit.prevent="apply"
        >
            <div class="flex min-w-0 flex-wrap items-end gap-2">
                <label class="grid min-w-0 flex-1 basis-48 gap-1 text-sm"
                    >Month
                    <select
                        v-model="month"
                        class="h-11 rounded-md border bg-background px-3 lg:h-9"
                        data-testid="sales-month"
                    >
                        <option
                            v-for="option in report.months"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label class="grid min-w-0 flex-1 basis-48 gap-1 text-sm"
                    >Branch
                    <select
                        v-model="branch"
                        class="h-11 rounded-md border bg-background px-3 lg:h-9"
                        data-testid="sales-branch"
                    >
                        <option
                            v-for="option in branchOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label class="grid min-w-0 flex-1 basis-40 gap-1 text-sm"
                    >Rank by
                    <select
                        v-model="rank"
                        class="h-11 rounded-md border bg-background px-3 lg:h-9"
                        data-testid="sales-rank"
                    >
                        <option value="sales">Sales</option>
                        <option value="profit">Gross profit</option>
                    </select>
                </label>
                <div class="flex w-full items-center gap-2 sm:w-auto">
                    <Button
                        type="submit"
                        variant="outline"
                        class="h-11 flex-1 px-5 sm:flex-none lg:h-9"
                        :disabled="busy"
                    >
                        {{ busy ? 'Applying…' : 'Apply' }}
                    </Button>
                    <ActionLink
                        href="/inventory/medicine-sales"
                        variant="ghost"
                        class="min-h-11 lg:min-h-9"
                        data-testid="sales-reset"
                        >Reset</ActionLink
                    >
                </div>
            </div>
            <p
                class="text-sm text-muted-foreground"
                data-testid="sales-void-note"
            >
                Figures can change if an invoice is voided later.
            </p>
        </form>

        <p
            v-if="refused.length"
            role="alert"
            class="rounded-lg border border-destructive bg-destructive/10 p-3 text-sm font-medium text-destructive"
            data-testid="sales-refused"
        >
            {{ refused.join(' ') }} Showing the default view instead.
        </p>

        <p
            class="flex items-start gap-2 rounded-lg border border-warning bg-warning/10 p-3 text-sm font-medium text-warning-strong"
            data-testid="sales-banner"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ report.banner }}</span>
        </p>

        <section class="space-y-2" aria-labelledby="sales-totals-heading">
            <h2 id="sales-totals-heading" class="text-base font-semibold">
                {{ report.monthLabel }} · {{ report.scopeLabel }}
            </h2>
            <ul
                class="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4"
                aria-label="Money totals"
                data-testid="sales-money-cards"
            >
                <li
                    v-for="card in moneyCards"
                    :key="card.label"
                    class="min-w-0 rounded-xl border bg-card p-3"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ card.label }}
                    </p>
                    <p
                        class="mt-1 text-lg font-semibold tabular-nums sm:text-xl"
                    >
                        {{ card.value }}
                    </p>
                </li>
            </ul>
            <p
                v-if="anyIncomplete"
                class="flex items-center gap-2 text-sm"
                data-testid="sales-incomplete-line"
            >
                <span
                    class="size-2 shrink-0 rounded-full bg-warning"
                    aria-hidden="true"
                />
                <span
                    >{{ s.incompleteMedicines }}
                    {{
                        s.incompleteMedicines === 1
                            ? 'medicine with cost not complete is'
                            : 'medicines with cost not complete are'
                    }}
                    left out of profit and margin.</span
                >
            </p>
            <ul
                class="grid grid-cols-2 gap-2 sm:max-w-md sm:gap-3"
                aria-label="Line counts"
                data-testid="sales-count-cards"
            >
                <li
                    v-for="card in countCards"
                    :key="card.label"
                    class="min-w-0 rounded-lg border bg-card px-3 py-2"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ card.label }}
                    </p>
                    <p class="text-base font-semibold tabular-nums">
                        {{ card.value }}
                    </p>
                </li>
            </ul>
        </section>

        <section class="space-y-3" aria-labelledby="sales-top-heading">
            <h2 id="sales-top-heading" class="text-base font-semibold">
                Top {{ Math.min(25, report.medicineCount) }} medicines by
                {{
                    report.filters.rank === 'profit' ? 'gross profit' : 'sales'
                }}
            </h2>

            <div class="hidden lg:block" data-testid="sales-table">
                <OperationalTable
                    label="Top medicines"
                    :columns="9"
                    min-width="0px"
                    :empty="report.rows.length === 0"
                    empty-message="No medicine was sold this month."
                >
                    <template #head>
                        <tr>
                            <th scope="col" class="text-right">Rank</th>
                            <th scope="col">Medicine</th>
                            <th scope="col" class="text-right">Units</th>
                            <th scope="col" class="text-right">Sales</th>
                            <th scope="col" class="text-right">Cost</th>
                            <th scope="col" class="text-right">Gross profit</th>
                            <th scope="col" class="text-right">Margin</th>
                            <th scope="col" class="text-right">
                                Lines below cost
                            </th>
                            <th scope="col" class="text-right">Free lines</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="row in report.rows" :key="row.id">
                            <td class="text-right tabular-nums">
                                {{ row.rank }}
                            </td>
                            <td>
                                <span class="block font-medium">{{
                                    row.medicine
                                }}</span>
                                <span
                                    v-if="row.code"
                                    class="block font-mono text-xs text-muted-foreground"
                                    >{{ unbroken(row.code) }}</span
                                >
                            </td>
                            <td class="text-right tabular-nums">
                                <span
                                    v-for="u in row.units"
                                    :key="u.unit"
                                    class="block whitespace-nowrap"
                                    >{{ u.quantity }} {{ u.unit }}</span
                                >
                                <span
                                    v-if="row.units.length > 1"
                                    class="block text-xs text-muted-foreground"
                                    >mixed units</span
                                >
                            </td>
                            <td
                                class="text-right whitespace-nowrap tabular-nums"
                            >
                                {{ money(row.salesSen) }}
                            </td>
                            <template v-if="row.costComplete">
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        withEstimate(
                                            money(row.costSen),
                                            row.estimate,
                                        )
                                    }}
                                </td>
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ money(row.profitSen) }}
                                </td>
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ percent(row.marginPercent) }}
                                </td>
                            </template>
                            <td
                                v-else
                                colspan="3"
                                class="text-right text-sm text-muted-foreground"
                                :title="NOT_COMPLETE_REASON"
                                :aria-label="NOT_COMPLETE_REASON"
                                data-testid="sales-not-complete"
                            >
                                {{ NOT_COMPLETE }}
                            </td>
                            <td class="text-right tabular-nums">
                                {{ count(row.belowLines) }}
                            </td>
                            <td class="text-right tabular-nums">
                                {{ count(row.freeLines) }}
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>

            <ul
                class="space-y-2 lg:hidden"
                aria-label="Top medicines"
                data-testid="sales-cards"
            >
                <li
                    v-if="report.rows.length === 0"
                    class="rounded-xl border bg-card p-3 text-sm text-muted-foreground"
                >
                    No medicine was sold this month.
                </li>
                <li
                    v-for="row in report.rows"
                    :key="row.id"
                    class="min-w-0 rounded-xl border bg-card p-3"
                >
                    <p class="font-medium">
                        <span class="tabular-nums">{{ row.rank }}.</span>
                        {{ row.medicine }}
                        <span
                            v-if="row.code"
                            class="font-mono text-xs text-muted-foreground"
                            >{{ unbroken(row.code) }}</span
                        >
                    </p>
                    <dl
                        class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm tabular-nums"
                    >
                        <dt class="text-muted-foreground">Sales</dt>
                        <dd class="text-right">{{ money(row.salesSen) }}</dd>
                        <template v-if="row.costComplete">
                            <dt class="text-muted-foreground">Cost</dt>
                            <dd class="text-right">
                                {{
                                    withEstimate(
                                        money(row.costSen),
                                        row.estimate,
                                    )
                                }}
                            </dd>
                            <dt class="text-muted-foreground">Gross profit</dt>
                            <dd class="text-right">
                                {{ money(row.profitSen) }}
                            </dd>
                            <dt class="text-muted-foreground">Margin</dt>
                            <dd class="text-right">
                                {{ percent(row.marginPercent) }}
                            </dd>
                        </template>
                        <template v-else>
                            <dt class="text-muted-foreground">Cost</dt>
                            <dd
                                class="text-right text-muted-foreground"
                                :aria-label="NOT_COMPLETE_REASON"
                            >
                                {{ NOT_COMPLETE }}
                            </dd>
                        </template>
                        <dt class="text-muted-foreground">Units</dt>
                        <dd class="text-right">
                            <span
                                v-for="u in row.units"
                                :key="u.unit"
                                class="block"
                                >{{ u.quantity }} {{ u.unit }}</span
                            >
                            <span
                                v-if="row.units.length > 1"
                                class="block text-xs text-muted-foreground"
                                >mixed units</span
                            >
                        </dd>
                        <template v-if="row.belowLines > 0">
                            <dt class="text-muted-foreground">
                                Lines below cost
                            </dt>
                            <dd class="text-right">{{ row.belowLines }}</dd>
                        </template>
                        <template v-if="row.freeLines > 0">
                            <dt class="text-muted-foreground">Free lines</dt>
                            <dd class="text-right">{{ row.freeLines }}</dd>
                        </template>
                    </dl>
                    <p
                        v-if="!row.costComplete"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        {{ NOT_COMPLETE_REASON }}
                    </p>
                </li>
            </ul>

            <p
                v-if="report.rows.some((r) => !r.costComplete)"
                class="text-xs text-muted-foreground"
                data-testid="sales-not-complete-note"
            >
                {{ NOT_COMPLETE_REASON }}
            </p>
            <p
                v-if="report.medicineCount > 0"
                class="text-sm text-muted-foreground"
                data-testid="sales-showing"
            >
                Showing the top {{ report.rows.length }} of
                {{ report.medicineCount }}
                {{ report.medicineCount === 1 ? 'medicine' : 'medicines' }}
                sold this month.
            </p>
        </section>

        <section class="space-y-3" aria-labelledby="sales-below-heading">
            <h2 id="sales-below-heading" class="text-base font-semibold">
                Sold below cost
            </h2>
            <p
                v-if="report.below.total === 0"
                class="rounded-xl border bg-card p-3 text-sm text-muted-foreground"
                data-testid="sales-below-empty"
            >
                No medicine was sold below cost this month.
            </p>
            <template v-else>
                <div class="hidden lg:block" data-testid="sales-below-table">
                    <OperationalTable
                        label="Medicines sold below cost"
                        :columns="3"
                        min-width="0px"
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Medicine</th>
                                <th scope="col" class="text-right">
                                    Lines below cost
                                </th>
                                <th scope="col" class="text-right">
                                    Shortfall
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in report.below.rows" :key="row.id">
                                <td>
                                    <span class="block font-medium">{{
                                        row.medicine
                                    }}</span>
                                    <span
                                        v-if="row.code"
                                        class="block font-mono text-xs text-muted-foreground"
                                        >{{ unbroken(row.code) }}</span
                                    >
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ row.belowLines }}
                                </td>
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ money(row.shortfallSen) }}
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                </div>
                <ul
                    class="space-y-2 lg:hidden"
                    aria-label="Medicines sold below cost"
                    data-testid="sales-below-cards"
                >
                    <li
                        v-for="row in report.below.rows"
                        :key="row.id"
                        class="min-w-0 rounded-xl border bg-card p-3"
                    >
                        <p class="font-medium">
                            {{ row.medicine }}
                            <span
                                v-if="row.code"
                                class="font-mono text-xs text-muted-foreground"
                                >{{ unbroken(row.code) }}</span
                            >
                        </p>
                        <dl
                            class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm tabular-nums"
                        >
                            <dt class="text-muted-foreground">
                                Lines below cost
                            </dt>
                            <dd class="text-right">{{ row.belowLines }}</dd>
                            <dt class="text-muted-foreground">Shortfall</dt>
                            <dd class="text-right">
                                {{ money(row.shortfallSen) }}
                            </dd>
                        </dl>
                    </li>
                </ul>
                <CompactPagination
                    v-if="report.below.lastPage > 1"
                    :current-page="report.below.page"
                    :last-page="report.below.lastPage"
                    :total="report.below.total"
                    @change="belowPage"
                />
            </template>
        </section>

        <details
            class="group rounded-lg border bg-card"
            data-testid="sales-how"
        >
            <summary
                class="flex min-h-11 cursor-pointer list-none items-center px-3 text-sm font-medium focus-visible:rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none [&::-webkit-details-marker]:hidden"
            >
                How this page counts
            </summary>
            <ul
                class="list-disc space-y-1 px-8 pb-3 text-sm text-muted-foreground"
            >
                <li>
                    Sales are the medicine lines of invoices finalised in the
                    month, at each branch's own time. Invoice discounts and
                    surcharges are not split across lines, so lines show their
                    full price.
                </li>
                <li>
                    Units and cost come from what was dispensed for those lines.
                    Cost is the average cost at which that stock left, so a sale
                    and its cost stay together.
                </li>
                <li>
                    "(estimate)" means part of the cost comes from a purchase
                    order's estimated price, not a supplier receipt.
                </li>
                <li>
                    "Cost not complete" means some stock sold has no cost
                    recorded. Those medicines are left out of the profit and
                    margin totals; margin is profit divided by the sales of the
                    other medicines.
                </li>
                <li>
                    A line charged RM 0.00 is a free line, not a sale below
                    cost. Its cost still counts in the cost and profit totals.
                </li>
                <li>
                    Medicine returned after dispensing is not taken off, and
                    treatment-room use is not a sale.
                </li>
                <li>
                    Stock value and cost counts cost by the day stock was
                    dispensed, so near the end of a month the two pages can
                    differ slightly.
                </li>
                <li>
                    A month changes later if one of its invoices is voided.
                    Generated {{ report.generatedAt }}.
                </li>
            </ul>
        </details>
    </main>
</template>
