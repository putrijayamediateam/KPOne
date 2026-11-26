<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, Info, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';
import { quantityWithUnit } from '@/lib/inventory-words';
import { formatDate } from '@/lib/presentation';

/**
 * STK-28 in Classic: stock value, cost of goods sold, margin and losses (Director only), with the STK-29 journal summary
 * download. The only Stock value page since Classic only (CO-6 removed the V2 copy; owner decision 2026-11-19,
 * replacing the 2026-11-15 "new layout only" page). Figures come from the server; this page
 * only formats them. Every figure uses the weighted average cost policy the clinic's accountant approved verbally on
 * 25 Nov 2026 (REL-STK); it is still a management report, so the banner shows once, above the summary.
 */
type StockRow = {
    sku: string;
    medicine: string;
    unit: string;
    quantity: string;
    uncostedQuantity: string;
    averageMilliSen: number | null;
    valueSen: number;
    includesEstimate: boolean;
};
type MedicineRow = {
    medicine: string;
    code: string;
    salesSen: number;
    costSen: number | null;
    marginSen: number | null;
    marginPercent: number | null;
    reason: string | null;
};
type LossRow = {
    key: string;
    label: string;
    count: number;
    sen: number;
    uncosted: boolean;
};
type Report = {
    banner: string;
    handCostChanges?: { id: number; message: string }[];
    filters: {
        branch: string;
        from: string;
        to: string;
        today: string;
        maxDays: number;
    };
    branches: { id: string; name: string }[];
    scopeLabel: string;
    periodEnd: string;
    generatedAt: string;
    timezone: string;
    summary: {
        stockValueSen: number;
        cogsSen: number;
        dispenseCostSen: number;
        usageCostSen: number;
        salesSen: number;
        marginSen: number;
        marginPercent: number | null;
        lossesSen: number;
        inTransitSen: number;
        uncostedSkus: number;
        estimateSkus: number;
    };
    stock: StockRow[];
    medicines: MedicineRow[];
    losses: { rows: LossRow[]; creditsWithoutAmount: number };
    refused?: string[];
};

const props = defineProps<{ report: Report }>();

const PAGE_SIZE = 25;

// A filter refused by the server shows the default period, with its reason at the top of the page.
const refused = computed(() => props.report.refused ?? []);

// ---- Formatting (no maths beyond display) ----
/** Whole sen to "RM 1,234.50", with a real minus sign for a negative figure; RM never wraps from its number. */
const money = (sen: number | null | undefined): string => {
    if (sen === null || sen === undefined) {
        return '—';
    }

    const abs = Math.abs(sen);
    const ringgit = String(Math.trunc(abs / 100)).replace(
        /\B(?=(\d{3})+(?!\d))/g,
        ',',
    );

    return `${sen < 0 ? '\u2212' : ''}RM\u00a0${ringgit}.${String(abs % 100).padStart(2, '0')}`;
};
/** An average per stock unit, in thousandths of a sen, to "RM 0.1234" (four places, so a tablet's cost is readable). */
const perUnit = (milliSen: number | null): string =>
    milliSen === null ? '—' : `RM\u00a0${(milliSen / 100_000).toFixed(4)}`;
const percent = (value: number | null): string =>
    value === null
        ? '—'
        : `${value < 0 ? '\u2212' : ''}${Math.abs(value).toFixed(1)}%`;
/** A word beside a margin, so colour is never the only signal. */
const marginWord = (sen: number | null): string | null =>
    sen === null ? null : sen < 0 ? 'Loss' : sen === 0 ? 'No margin' : null;
const unbroken = (code: string) => code.replaceAll('-', '‑');
const s = props.report.summary;
const periodWords = computed(
    () =>
        `${formatDate(props.report.filters.from)} to ${formatDate(props.report.filters.to)}`,
);

// ---- Filters: an explicit Show; dates day first ----
const branch = ref(props.report.filters.branch);
const from = ref(props.report.filters.from);
const to = ref(props.report.filters.to);
const branchOptions = computed(() => [
    ...props.report.branches.map((b) => ({ value: b.id, label: b.name })),
    { value: 'all', label: 'All branches' },
]);
const days = computed(() =>
    /^\d{4}-\d{2}-\d{2}$/.test(from.value) &&
    /^\d{4}-\d{2}-\d{2}$/.test(to.value)
        ? Math.round(
              (Date.parse(`${to.value}T00:00:00Z`) -
                  Date.parse(`${from.value}T00:00:00Z`)) /
                  86_400_000,
          ) + 1
        : null,
);
const rangeError = computed<string | undefined>(() => {
    if (from.value && to.value && from.value > to.value) {
        return 'From must be on or before To.';
    }

    if (days.value !== null && days.value > props.report.filters.maxDays) {
        return `Choose ${props.report.filters.maxDays} days or fewer.`;
    }

    return undefined;
});
const ready = computed(
    () =>
        from.value !== '' && to.value !== '' && rangeError.value === undefined,
);
const busy = ref(false);
const show = () => {
    if (!ready.value) {
        return;
    }

    router.get(
        '/inventory/stock-value',
        { branch: branch.value, from: from.value, to: to.value },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
};

// ---- STK-29: monthly journal summary download ----
const monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];
/** The shown period, when it is one calendar month (to its last day, or to today for this month); otherwise null. */
const journalMonth = computed(() => {
    const { from: f, to: t, today, branch: b } = props.report.filters;

    if (!/^\d{4}-\d{2}-01$/.test(f) || f.slice(0, 7) !== t.slice(0, 7)) {
        return null;
    }

    const [year, month] = f.split('-').map(Number);
    const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const fullMonth =
        t === `${f.slice(0, 8)}${String(lastDay).padStart(2, '0')}`;
    const toDate = !fullMonth && t === today;

    if (!fullMonth && !toDate) {
        return null;
    }

    return {
        month: f.slice(0, 7),
        branch: b,
        label: `${monthNames[month - 1]} ${year} · ${props.report.scopeLabel} · ${fullMonth && t < today ? 'full month' : 'month to date'}`,
    };
});
const downloading = ref(false);
const journalError = ref<string | null>(null);
const journalDone = ref('');
const downloadJournal = async () => {
    if (downloading.value || !journalMonth.value) {
        return;
    }

    downloading.value = true;
    journalError.value = null;
    journalDone.value = '';

    try {
        const query = new URLSearchParams({
            branch: journalMonth.value.branch,
            month: journalMonth.value.month,
        });
        const response = await fetch(
            `/inventory/stock-value/journal?${query}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            const body = (await response.json().catch(() => null)) as {
                message?: string;
                errors?: Record<string, string[]>;
            } | null;
            journalError.value =
                Object.values(body?.errors ?? {})[0]?.[0] ??
                body?.message ??
                'The journal summary could not be prepared. Try again.';

            return;
        }

        const name =
            /filename="([^"]+)"/.exec(
                response.headers.get('Content-Disposition') ?? '',
            )?.[1] ?? 'kpone-stock-journal.csv';
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
        journalDone.value = `Downloaded ${name}`;
    } catch {
        journalError.value =
            'The journal summary could not be prepared. Try again.';
    } finally {
        downloading.value = false;
    }
};

// ---- Summary ----
// With no medicine sales there is no margin to describe, so no word either.
const marginStatus = s.salesSen > 0 ? marginWord(s.marginSen) : null;
const summaryItems = computed(() => [
    {
        label: `Stock value on ${formatDate(props.report.periodEnd)}`,
        value: money(s.stockValueSen),
        detail:
            s.uncostedSkus > 0
                ? `${s.uncostedSkus} ${s.uncostedSkus === 1 ? 'medicine has' : 'medicines have'} stock with no cost recorded`
                : undefined,
        status: undefined,
        tone: 'neutral' as const,
    },
    {
        label: 'Cost of goods sold',
        value: money(s.cogsSen),
        detail: `Dispensed ${money(s.dispenseCostSen)} · treatment-room use ${money(s.usageCostSen)}`,
        status: undefined,
        tone: 'neutral' as const,
    },
    {
        label: 'Medicine sales',
        value: money(s.salesSen),
        detail: 'Before invoice discounts or surcharges',
        status: undefined,
        tone: 'neutral' as const,
    },
    {
        label: 'Gross margin',
        value: money(s.marginSen),
        detail:
            s.marginPercent === null
                ? 'No medicine sales in this period'
                : `${percent(s.marginPercent)} of medicine sales`,
        status: marginStatus ?? undefined,
        tone: marginStatus ? ('warning' as const) : ('neutral' as const),
    },
    {
        label: 'Losses',
        // A negative total means the credit notes outweighed the losses: shown as a gain in words, never "−RM … Gain".
        value: money(Math.abs(s.lossesSen)),
        detail:
            s.lossesSen < 0
                ? 'Supplier credits were more than the losses'
                : undefined,
        status: s.lossesSen < 0 ? 'Net gain' : undefined,
        tone: s.lossesSen < 0 ? ('success' as const) : ('neutral' as const),
    },
]);

// ---- Tabs and paging (25 a page, one pager per tab) ----
const tab = ref('value');
const tabs = computed(
    () =>
        [
            {
                value: 'value',
                label: 'Stock value',
                count: props.report.stock.length,
            },
            {
                value: 'margin',
                label: 'Cost and margin',
                count: props.report.medicines.length,
            },
            {
                value: 'losses',
                label: 'Losses',
                count: props.report.losses.rows.filter((r) => r.count > 0)
                    .length,
            },
            { value: 'counted', label: 'How this is counted' },
        ] as { value: string; label: string; count?: number }[],
);
const tabLabel = computed(
    () => tabs.value.find((t) => t.value === tab.value)?.label ?? '',
);
const page = ref(1);
watch(tab, () => (page.value = 1));
const rowsOf = <T,>(rows: T[]) =>
    rows.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE);
const lastPage = (rows: unknown[]) =>
    Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
const showing = (rows: unknown[]) =>
    `Showing ${rowsOf(rows).length} of ${rows.length}`;
const uncostedNames = computed(() =>
    props.report.stock
        .filter((r) => r.uncostedQuantity !== '0')
        .map(
            (r) =>
                `${r.medicine} ${r.sku} (${quantityWithUnit(r.uncostedQuantity, r.unit)})`,
        ),
);
const usageCost = s.usageCostSen;
const lossesTotal = s.lossesSen;
const lossHelp: Record<string, string> = {
    credit: 'Cost of the returned stock minus the supplier credit note amount. A gain when the credit was more than the cost.',
    count: 'Count losses and adjustments out, less count gains and adjustments in.',
};
</script>

<template>
    <Head title="Stock value and cost" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Stock value and cost"
            :description="`${report.scopeLabel} · ${periodWords} · Generated ${report.generatedAt} (${report.timezone})`"
        />

        <p
            class="flex items-start gap-2 rounded-lg border bg-muted/40 p-3 text-sm font-medium text-foreground"
            data-testid="valuation-banner"
        >
            <Info
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <span>{{ report.banner }}</span>
        </p>

        <section
            v-if="report.handCostChanges?.length"
            aria-labelledby="hand-cost-changes-title"
            class="rounded-lg border border-border bg-info-wash p-3 text-sm text-foreground"
            data-testid="hand-cost-changes"
        >
            <h2 id="hand-cost-changes-title" class="font-medium">
                Historical figures updated
            </h2>
            <ul class="mt-2 grid gap-2">
                <li
                    v-for="change in report.handCostChanges"
                    :key="change.id"
                    class="break-words"
                >
                    {{ change.message }}
                </li>
            </ul>
        </section>

        <p
            v-if="refused.length"
            role="alert"
            class="rounded-lg border border-destructive bg-destructive/10 p-3 text-sm font-medium text-destructive"
            data-testid="valuation-refused"
        >
            {{ refused.join(' ') }} Showing the default period instead.
        </p>

        <form
            class="flex min-w-0 flex-wrap items-start gap-2"
            aria-label="Report filters"
            novalidate
            @submit.prevent="show"
        >
            <label class="grid min-w-0 flex-1 basis-48 gap-1 text-sm"
                >Branch
                <select
                    v-model="branch"
                    class="h-11 rounded-md border bg-background px-3 lg:h-9"
                    data-testid="valuation-branch"
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
            <label class="grid min-w-0 flex-1 basis-56 gap-1 text-sm"
                >From
                <DateField
                    v-model="from"
                    required
                    :max="report.filters.today"
                    :aria-invalid="rangeError ? true : undefined"
                    :aria-describedby="
                        rangeError ? 'valuation-range-error' : undefined
                    "
                />
            </label>
            <label class="grid min-w-0 flex-1 basis-56 gap-1 text-sm"
                >To
                <DateField
                    v-model="to"
                    required
                    :max="report.filters.today"
                    :aria-invalid="rangeError ? true : undefined"
                    :aria-describedby="
                        rangeError ? 'valuation-range-error' : undefined
                    "
                />
            </label>
            <!-- An empty label line keeps Show level with the boxes, whose labels sit above them. -->
            <div class="grid w-full gap-1 text-sm sm:w-auto">
                <span aria-hidden="true" class="invisible">&nbsp;</span>
                <Button
                    type="submit"
                    class="h-11 w-full px-5 sm:w-auto lg:h-9"
                    :disabled="!ready || busy"
                >
                    {{ busy ? 'Showing…' : 'Show' }}
                </Button>
            </div>
        </form>
        <p
            v-if="rangeError"
            id="valuation-range-error"
            role="alert"
            class="-mt-4 text-sm text-destructive"
            data-testid="valuation-range-error"
        >
            {{ rangeError }}
        </p>

        <!-- STK-29: the monthly journal summary CSV, only for one calendar month. -->
        <div
            class="flex min-w-0 flex-col gap-1.5"
            data-testid="journal-download"
        >
            <template v-if="journalMonth">
                <div
                    class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5"
                >
                    <button
                        type="button"
                        class="inline-flex h-11 w-full items-center justify-center rounded-md border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 sm:w-fit lg:h-9"
                        :disabled="downloading"
                        :aria-busy="downloading"
                        data-testid="journal-button"
                        @click="downloadJournal"
                    >
                        {{
                            downloading
                                ? 'Preparing…'
                                : 'Download journal summary (CSV)'
                        }}
                    </button>
                    <span
                        class="text-sm text-muted-foreground"
                        data-testid="journal-scope"
                        >{{ journalMonth.label }}</span
                    >
                </div>
                <p class="text-xs text-muted-foreground">
                    A management summary only. No real accounting system
                    receives this file.
                </p>
            </template>
            <p
                v-else
                class="text-sm text-muted-foreground"
                data-testid="journal-other-period"
            >
                The journal summary is for one calendar month. Choose the 1st to
                the last day of a month.
            </p>
            <p
                v-if="journalError"
                role="alert"
                class="text-sm text-destructive"
                data-testid="journal-error"
            >
                {{ journalError }}
            </p>
            <p role="status" class="sr-only">{{ journalDone }}</p>
        </div>

        <ul
            class="grid grid-cols-1 gap-2 sm:grid-cols-2 sm:gap-3 xl:grid-cols-5"
            aria-label="Stock value summary"
            data-testid="valuation-summary"
        >
            <li
                v-for="item in summaryItems"
                :key="item.label"
                class="min-w-0 rounded-xl border bg-card p-3"
            >
                <p class="text-xs break-words text-muted-foreground">
                    {{ item.label }}
                </p>
                <p
                    class="mt-1 text-lg font-semibold whitespace-nowrap tabular-nums sm:text-xl"
                >
                    {{ item.value }}
                </p>
                <p
                    v-if="item.status"
                    class="text-xs font-medium"
                    :class="
                        item.tone === 'warning'
                            ? 'text-warning-strong'
                            : item.tone === 'success'
                              ? 'text-success'
                              : 'text-foreground'
                    "
                >
                    {{ item.status }}
                </p>
                <p v-if="item.detail" class="text-xs text-muted-foreground">
                    {{ item.detail }}
                </p>
            </li>
        </ul>

        <section class="space-y-4 rounded-xl border bg-card p-4">
            <OperationalTabs
                v-model="tab"
                :tabs="tabs"
                label="Report sections"
            />

            <div
                role="tabpanel"
                :aria-label="tabLabel"
                class="flex min-w-0 flex-col gap-3"
            >
                <details
                    v-if="tab !== 'counted'"
                    class="group rounded-lg border bg-muted/40"
                >
                    <summary
                        class="flex min-h-11 cursor-pointer list-none items-center px-3 text-sm font-medium focus-visible:rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9 [&::-webkit-details-marker]:hidden"
                    >
                        How this is counted
                    </summary>
                    <ul
                        class="list-disc space-y-1 px-3 pb-3 pl-8 text-sm text-muted-foreground"
                    >
                        <li>
                            Cost is a weighted average per medicine per branch.
                            A purchase receipt moves it; stock leaves at the
                            average.
                        </li>
                        <li>
                            Cost of goods sold is counted when stock is
                            dispensed and when it is used in a treatment room.
                        </li>
                        <li>
                            Stock with no cost recorded is counted apart and
                            named, never valued at zero.
                        </li>
                    </ul>
                </details>

                <!-- Stock value -->
                <template v-if="tab === 'value'">
                    <p
                        v-if="uncostedNames.length"
                        class="flex items-start gap-2 rounded-lg border border-warning bg-warning/10 p-3 text-sm text-warning-strong"
                        data-testid="valuation-uncosted"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span
                            >{{ uncostedNames.length }}
                            {{
                                uncostedNames.length === 1
                                    ? 'medicine has'
                                    : 'medicines have'
                            }}
                            stock with no cost recorded. They are marked below
                            and left out of the value.</span
                        >
                    </p>
                    <p
                        v-if="
                            report.filters.branch === 'all' &&
                            s.inTransitSen > 0
                        "
                        class="text-sm text-muted-foreground"
                    >
                        Stock in transit between branches:
                        <span
                            class="font-medium whitespace-nowrap text-foreground tabular-nums"
                            >{{ money(s.inTransitSen) }}</span
                        >
                        (not in any branch's value).
                    </p>
                    <OperationalTable
                        label="Stock value per medicine"
                        :columns="4"
                        min-width="0px"
                        :empty="report.stock.length === 0"
                        empty-message="No stock on hand at the end of this period."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Medicine</th>
                                <th
                                    scope="col"
                                    class="hidden text-right md:table-cell"
                                >
                                    On hand
                                </th>
                                <th
                                    scope="col"
                                    class="hidden text-right md:table-cell"
                                >
                                    Average cost per unit
                                </th>
                                <th scope="col" class="text-right">Value</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in rowsOf(report.stock)"
                                :key="row.sku"
                            >
                                <td>
                                    <span class="block font-medium">{{
                                        row.medicine
                                    }}</span>
                                    <span
                                        class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                        >{{ unbroken(row.sku) }}</span
                                    >
                                    <StatusBadge
                                        v-if="row.includesEstimate"
                                        class="mt-1"
                                        status="estimate"
                                        tone="neutral"
                                        label="Includes an estimate"
                                    />
                                    <span
                                        v-if="row.uncostedQuantity !== '0'"
                                        class="mt-1 block text-xs text-warning-strong"
                                        >{{
                                            quantityWithUnit(
                                                row.uncostedQuantity,
                                                row.unit,
                                            )
                                        }}
                                        with no cost recorded</span
                                    >
                                    <!-- Below md the two middle columns move here, so Value stays on screen. -->
                                    <span
                                        class="mt-1 block text-xs text-muted-foreground tabular-nums md:hidden"
                                        >{{
                                            quantityWithUnit(
                                                row.quantity,
                                                row.unit,
                                            )
                                        }}
                                        on hand<template
                                            v-if="row.averageMilliSen !== null"
                                        >
                                            ·
                                            {{ perUnit(row.averageMilliSen) }}
                                            per unit</template
                                        ></span
                                    >
                                </td>
                                <td
                                    class="hidden text-right whitespace-nowrap tabular-nums md:table-cell"
                                >
                                    {{
                                        quantityWithUnit(row.quantity, row.unit)
                                    }}
                                </td>
                                <td
                                    class="hidden text-right whitespace-nowrap tabular-nums md:table-cell"
                                >
                                    {{ perUnit(row.averageMilliSen) }}
                                </td>
                                <td
                                    class="text-right font-medium whitespace-nowrap tabular-nums"
                                >
                                    <span
                                        class="inline-flex flex-col items-end"
                                    >
                                        <span>{{
                                            row.averageMilliSen === null
                                                ? '—'
                                                : money(row.valueSen)
                                        }}</span>
                                        <span
                                            v-if="row.averageMilliSen === null"
                                            class="text-xs font-normal text-muted-foreground"
                                            >No cost recorded</span
                                        >
                                    </span>
                                </td>
                            </tr>
                            <tr data-total>
                                <th scope="row" class="font-semibold">Total</th>
                                <td class="hidden text-right md:table-cell">
                                    {{ report.stock.length }}
                                    {{
                                        report.stock.length === 1
                                            ? 'medicine'
                                            : 'medicines'
                                    }}
                                </td>
                                <td
                                    aria-hidden="true"
                                    class="hidden md:table-cell"
                                />
                                <td
                                    class="text-right font-semibold whitespace-nowrap tabular-nums"
                                >
                                    {{ money(s.stockValueSen) }}
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                </template>

                <!-- Cost and margin -->
                <template v-else-if="tab === 'margin'">
                    <p class="text-sm text-muted-foreground">
                        Treatment-room use is a cost with no sale:
                        <span
                            class="font-medium whitespace-nowrap text-foreground tabular-nums"
                            >{{ money(usageCost) }}</span
                        >
                        in this period, not in the margins below.
                    </p>
                    <OperationalTable
                        label="Sales, cost and margin per medicine"
                        :columns="5"
                        min-width="0px"
                        :empty="report.medicines.length === 0"
                        empty-message="No medicine sold or dispensed in this period."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Medicine</th>
                                <th
                                    scope="col"
                                    class="hidden text-right md:table-cell"
                                >
                                    Sales
                                </th>
                                <th
                                    scope="col"
                                    class="hidden text-right md:table-cell"
                                >
                                    Cost
                                </th>
                                <th scope="col" class="text-right">Margin</th>
                                <th
                                    scope="col"
                                    class="hidden text-right md:table-cell"
                                >
                                    Margin %
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in rowsOf(report.medicines)"
                                :key="row.code"
                            >
                                <td>
                                    <span class="block font-medium">{{
                                        row.medicine
                                    }}</span>
                                    <span
                                        v-if="row.code"
                                        class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                        >{{ unbroken(row.code) }}</span
                                    >
                                    <span
                                        v-if="row.reason"
                                        class="block text-xs text-muted-foreground"
                                        >{{ row.reason }}</span
                                    >
                                    <!-- Below md, sales, cost and margin % move here, so Margin stays on screen. -->
                                    <span
                                        class="mt-1 block text-xs text-muted-foreground tabular-nums md:hidden"
                                        >Sales {{ money(row.salesSen) }} · Cost
                                        {{ money(row.costSen) }} ·
                                        {{ percent(row.marginPercent) }}</span
                                    >
                                </td>
                                <td
                                    class="hidden text-right whitespace-nowrap tabular-nums md:table-cell"
                                >
                                    {{ money(row.salesSen) }}
                                </td>
                                <td
                                    class="hidden text-right whitespace-nowrap tabular-nums md:table-cell"
                                >
                                    {{ money(row.costSen) }}
                                </td>
                                <td
                                    class="text-right font-medium whitespace-nowrap tabular-nums"
                                >
                                    <span
                                        class="inline-flex flex-col items-end"
                                    >
                                        <span>{{ money(row.marginSen) }}</span>
                                        <span
                                            v-if="marginWord(row.marginSen)"
                                            class="text-xs font-medium text-warning-strong"
                                            >{{
                                                marginWord(row.marginSen)
                                            }}</span
                                        >
                                    </span>
                                </td>
                                <td
                                    class="hidden text-right whitespace-nowrap tabular-nums md:table-cell"
                                >
                                    {{ percent(row.marginPercent) }}
                                </td>
                            </tr>
                            <tr data-total>
                                <th scope="row" class="font-semibold">
                                    Total
                                    <span
                                        class="block text-xs font-normal text-muted-foreground tabular-nums md:hidden"
                                        >Sales {{ money(s.salesSen) }} · Cost
                                        {{ money(s.dispenseCostSen) }} ·
                                        {{ percent(s.marginPercent) }}</span
                                    >
                                </th>
                                <td
                                    class="hidden text-right font-semibold whitespace-nowrap tabular-nums md:table-cell"
                                >
                                    {{ money(s.salesSen) }}
                                </td>
                                <td
                                    class="hidden text-right font-semibold whitespace-nowrap tabular-nums md:table-cell"
                                >
                                    {{ money(s.dispenseCostSen) }}
                                </td>
                                <td
                                    class="text-right font-semibold whitespace-nowrap tabular-nums"
                                >
                                    <span
                                        class="inline-flex flex-col items-end"
                                    >
                                        <span>{{ money(s.marginSen) }}</span>
                                        <span
                                            v-if="marginStatus"
                                            class="text-xs font-medium text-warning-strong"
                                            >{{ marginStatus }}</span
                                        >
                                    </span>
                                </td>
                                <td
                                    class="hidden text-right font-semibold whitespace-nowrap tabular-nums md:table-cell"
                                >
                                    {{ percent(s.marginPercent) }}
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                </template>

                <!-- Losses -->
                <template v-else-if="tab === 'losses'">
                    <p
                        v-if="report.losses.creditsWithoutAmount > 0"
                        class="flex items-start gap-2 rounded-lg border border-warning bg-warning/10 p-3 text-sm text-warning-strong"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span
                            >{{ report.losses.creditsWithoutAmount }}
                            {{
                                report.losses.creditsWithoutAmount === 1
                                    ? 'credit note was'
                                    : 'credit notes were'
                            }}
                            recorded with no amount, so not counted.</span
                        >
                    </p>
                    <OperationalTable
                        label="Losses in this period"
                        :columns="3"
                        min-width="0px"
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Loss</th>
                                <th scope="col" class="text-right">Records</th>
                                <th scope="col" class="text-right">Amount</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in report.losses.rows"
                                :key="row.key"
                            >
                                <td>
                                    <span class="block font-medium">{{
                                        row.label
                                    }}</span>
                                    <span
                                        v-if="lossHelp[row.key]"
                                        class="block text-xs text-muted-foreground"
                                        >{{ lossHelp[row.key] }}</span
                                    >
                                    <span
                                        v-if="row.uncosted"
                                        class="block text-xs text-warning-strong"
                                        >Part of this stock had no cost
                                        recorded.</span
                                    >
                                </td>
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ row.count }}
                                </td>
                                <td
                                    class="text-right font-medium whitespace-nowrap tabular-nums"
                                >
                                    <span
                                        class="inline-flex flex-col items-end"
                                    >
                                        <span>{{
                                            money(Math.abs(row.sen))
                                        }}</span>
                                        <!-- Every non-zero row says which way it goes, so the column adds up in words too. -->
                                        <span
                                            v-if="row.sen !== 0"
                                            class="text-xs font-medium"
                                            :class="
                                                row.sen > 0
                                                    ? 'text-warning-strong'
                                                    : ''
                                            "
                                            >{{
                                                row.sen > 0 ? 'Loss' : 'Gain'
                                            }}</span
                                        >
                                    </span>
                                </td>
                            </tr>
                            <tr data-total>
                                <th scope="row" class="font-semibold">Total</th>
                                <td aria-hidden="true" />
                                <td
                                    class="text-right font-semibold whitespace-nowrap tabular-nums"
                                >
                                    <span
                                        class="inline-flex flex-col items-end"
                                    >
                                        <span>{{
                                            money(Math.abs(lossesTotal))
                                        }}</span>
                                        <span
                                            v-if="lossesTotal !== 0"
                                            class="text-xs font-medium"
                                            :class="
                                                lossesTotal > 0
                                                    ? 'text-warning-strong'
                                                    : ''
                                            "
                                            >{{
                                                lossesTotal > 0
                                                    ? 'Net loss'
                                                    : 'Net gain'
                                            }}</span
                                        >
                                    </span>
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                </template>

                <!-- How this is counted -->
                <template v-else>
                    <ul
                        class="list-disc space-y-2 pl-5 text-sm"
                        data-testid="valuation-rules"
                    >
                        <li>
                            <strong>Weighted average cost</strong> per medicine
                            per branch. A purchase receipt moves the average:
                            value before plus the receipt's cost, divided by the
                            quantity before plus what was received. The
                            receipt's cost is the lot total paid, else the unit
                            cost times the quantity.
                        </li>
                        <li>
                            <strong>Opening stock</strong> enters at the unit
                            cost entered for it.
                        </li>
                        <li>
                            <strong>Stock leaving</strong> (dispensing,
                            treatment-room use and waste, write-offs, supplier
                            returns, count losses) leaves at the average at that
                            moment.
                        </li>
                        <li>
                            <strong>Transfers</strong> leave one branch at its
                            average and arrive at the same cost.
                        </li>
                        <li>
                            <strong>Purchase order estimates</strong> are used
                            when nothing was paid yet, and marked "Includes an
                            estimate" until that stock is gone.
                        </li>
                        <li>
                            <strong>Stock with no cost</strong> enters at the
                            current average. With no average yet it is counted
                            apart and named, never valued at zero.
                        </li>
                        <li>
                            <strong
                                >Patient returns, count gains and adjustments
                                in</strong
                            >
                            enter at the current average. Returned medicine is
                            not taken off the cost of goods sold.
                        </li>
                        <li>
                            <strong>Held stock</strong> is valued.
                            <strong>Rejected lots</strong> never enter stock.
                        </li>
                        <li>
                            <strong>Supplier credit notes</strong> do not move
                            the average; the difference between the credit and
                            the cost of the returned stock is shown under
                            Losses.
                        </li>
                        <li>
                            <strong>Medicine sales</strong> are the medicine
                            lines of invoices finalised in the period and not
                            voided, before invoice discounts or surcharges.
                        </li>
                        <li>No delivery or other costs are added to stock.</li>
                    </ul>
                </template>

                <p
                    v-if="tab === 'value' && report.stock.length > 0"
                    class="text-xs text-muted-foreground"
                    data-testid="valuation-decimals"
                >
                    Unit costs show up to 4 decimals; values are rounded to sen.
                </p>
                <p
                    v-if="tab === 'value' && report.stock.length > 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ showing(report.stock) }} medicines
                </p>
                <p
                    v-if="tab === 'margin' && report.medicines.length > 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ showing(report.medicines) }} medicines
                </p>
                <CompactPagination
                    v-if="tab === 'value' && lastPage(report.stock) > 1"
                    :current-page="page"
                    :last-page="lastPage(report.stock)"
                    :total="report.stock.length"
                    @change="(p) => (page = p)"
                />
                <CompactPagination
                    v-if="tab === 'margin' && lastPage(report.medicines) > 1"
                    :current-page="page"
                    :last-page="lastPage(report.medicines)"
                    :total="report.medicines.length"
                    @change="(p) => (page = p)"
                />
            </div>
        </section>
    </main>
</template>
