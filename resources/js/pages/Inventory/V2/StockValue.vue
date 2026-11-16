<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Stat from '@/components/v2/V2Stat.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { formatDate } from '@/lib/presentation';

/**
 * STK-28: stock value, cost of goods sold, margin and losses (Director only). New page, Version 2 only; with the new
 * layout off, the Classic page offers to turn it on. Figures come from the server; this page only formats them. Every
 * figure is a KPOne default, not professional accounting advice, so the banner stays on every tab (Daniel, 2026-11-15).
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

usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock value and cost' },
]);

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
const fromInvalid = ref(false);
const toInvalid = ref(false);
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
        !fromInvalid.value &&
        !toInvalid.value &&
        from.value !== '' &&
        to.value !== '' &&
        rangeError.value === undefined,
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
const tabs = computed(() => [
    { value: 'value', label: 'Stock value', count: props.report.stock.length },
    {
        value: 'margin',
        label: 'Cost and margin',
        count: props.report.medicines.length,
    },
    {
        value: 'losses',
        label: 'Losses',
        count: props.report.losses.rows.filter((r) => r.count > 0).length,
    },
    { value: 'counted', label: 'How this is counted' },
]);
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
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Stock value and cost"
            :description="`${report.scopeLabel} · ${periodWords} · Generated ${report.generatedAt} (${report.timezone})`"
        >
            <template #main>
                <p
                    class="flex items-start gap-2 rounded-[14px] border border-warning bg-warning/10 p-3 text-sm font-medium text-warning-strong"
                    data-testid="valuation-banner"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{{ report.banner }}</span>
                </p>

                <p
                    v-if="refused.length"
                    role="alert"
                    class="rounded-[14px] border border-destructive bg-destructive/10 p-3 text-sm font-medium text-destructive"
                    data-testid="valuation-refused"
                >
                    {{ refused.join(' ') }} Showing the default period instead.
                </p>
                <form
                    class="flex min-w-0 flex-wrap items-end gap-2"
                    aria-label="Report filters"
                    novalidate
                    @submit.prevent="show"
                >
                    <div class="min-w-0 flex-1 basis-48">
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            label="Branch"
                        >
                            <OperationalSelect
                                :id="id"
                                v-model="branch"
                                label="Branch"
                                :describedby="describedBy"
                                :invalid="invalid"
                                :options="branchOptions"
                            />
                        </V2Field>
                    </div>
                    <div class="min-w-0 flex-1 basis-56">
                        <V2DateField
                            v-model="from"
                            label="From"
                            subject="From"
                            :max="report.filters.today"
                            @update:invalid="(value) => (fromInvalid = value)"
                        />
                    </div>
                    <div class="min-w-0 flex-1 basis-56">
                        <V2DateField
                            v-model="to"
                            label="To"
                            subject="To"
                            :max="report.filters.today"
                            :error="rangeError"
                            @update:invalid="(value) => (toInvalid = value)"
                        />
                    </div>
                    <button
                        type="submit"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-5 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9"
                        :disabled="!ready || busy"
                    >
                        {{ busy ? 'Showing…' : 'Show' }}
                    </button>
                </form>

                <!-- STK-29: the monthly journal summary CSV, only for one calendar month (Daniel). -->
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
                                class="inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 sm:w-fit lg:h-9"
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
                        The journal summary is for one calendar month. Choose
                        the 1st to the last day of a month.
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

                <!-- Summary: a plain list below 640 px, tiles from 640 px (Daniel). -->
                <dl
                    class="flex flex-col divide-y rounded-[14px] border sm:hidden"
                    data-testid="valuation-summary-list"
                >
                    <div
                        v-for="item in summaryItems"
                        :key="item.label"
                        class="flex min-h-11 items-start justify-between gap-3 px-3 py-2.5"
                    >
                        <dt class="text-sm">
                            {{ item.label }}
                            <span
                                v-if="item.detail"
                                class="block text-xs text-muted-foreground"
                                >{{ item.detail }}</span
                            >
                        </dt>
                        <dd
                            class="text-right text-sm font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{ item.value }}
                            <span
                                v-if="item.status"
                                class="block text-xs font-medium"
                                :class="
                                    item.tone === 'warning'
                                        ? 'text-warning-strong'
                                        : 'text-foreground'
                                "
                                >{{ item.status }}</span
                            >
                        </dd>
                    </div>
                </dl>
                <div
                    class="hidden gap-3 sm:grid sm:grid-cols-2 xl:grid-cols-5"
                    data-testid="valuation-summary-tiles"
                >
                    <V2Stat
                        v-for="item in summaryItems"
                        :key="item.label"
                        :label="item.label"
                        :value="item.value"
                        :detail="item.detail"
                        :tone="item.tone"
                        :status="item.status"
                    />
                </div>

                <V2Tabs
                    v-model="tab"
                    :tabs="tabs"
                    ariaLabel="Report sections"
                    id-prefix="valuation"
                />

                <section
                    :id="`valuation-panel-${tab}`"
                    role="tabpanel"
                    :aria-labelledby="`valuation-tab-${tab}`"
                    class="flex min-w-0 flex-col gap-3"
                >
                    <p
                        v-if="tab !== 'counted'"
                        class="flex items-start gap-2 rounded-[14px] border border-warning bg-warning/10 px-3 py-2 text-xs font-medium text-warning-strong"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{{ report.banner }}</span>
                    </p>
                    <V2DisclosureBar
                        v-if="tab !== 'counted'"
                        title="How this is counted"
                    >
                        <ul
                            class="list-disc space-y-1 pl-5 text-sm text-muted-foreground"
                        >
                            <li>
                                Cost is a weighted average per medicine per
                                branch. A purchase receipt moves it; stock
                                leaves at the average.
                            </li>
                            <li>
                                Cost of goods sold is counted when stock is
                                dispensed and when it is used in a treatment
                                room.
                            </li>
                            <li>
                                Stock with no cost recorded is counted apart and
                                named, never valued at zero.
                            </li>
                        </ul>
                    </V2DisclosureBar>

                    <!-- Stock value -->
                    <template v-if="tab === 'value'">
                        <p
                            v-if="uncostedNames.length"
                            class="flex items-start gap-2 rounded-[14px] border border-warning bg-warning/10 p-3 text-sm text-warning-strong"
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
                                stock with no cost recorded. They are marked
                                below and left out of the value.</span
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
                        <V2Table
                            label="Stock value per medicine"
                            :columns="4"
                            cards="xl"
                            min-width="880px"
                            :empty="report.stock.length === 0"
                            empty-message="No stock on hand at the end of this period."
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Medicine</th>
                                    <th scope="col" class="text-right">
                                        On hand
                                    </th>
                                    <th scope="col" class="text-right">
                                        Average cost per unit
                                    </th>
                                    <th scope="col" class="text-right">
                                        Value
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="row in rowsOf(report.stock)"
                                    :key="row.sku"
                                >
                                    <td data-label="Medicine" data-primary>
                                        <span
                                            class="block text-sm font-medium"
                                            >{{ row.medicine }}</span
                                        >
                                        <span
                                            class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >{{ unbroken(row.sku) }}</span
                                        >
                                        <span
                                            class="mt-1 flex flex-wrap gap-1.5"
                                        >
                                            <V2Chip
                                                v-if="row.includesEstimate"
                                                tone="neutral"
                                                label="Includes an estimate"
                                            />
                                        </span>
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
                                    </td>
                                    <td
                                        data-label="On hand"
                                        class="text-right text-sm whitespace-nowrap"
                                    >
                                        {{
                                            quantityWithUnit(
                                                row.quantity,
                                                row.unit,
                                            )
                                        }}
                                    </td>
                                    <td
                                        data-label="Average cost per unit"
                                        class="text-right text-sm whitespace-nowrap"
                                    >
                                        {{ perUnit(row.averageMilliSen) }}
                                    </td>
                                    <td
                                        data-label="Value"
                                        class="text-right text-sm font-medium whitespace-nowrap"
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
                                                v-if="
                                                    row.averageMilliSen === null
                                                "
                                                class="text-xs font-normal text-muted-foreground"
                                                >No cost recorded</span
                                            >
                                        </span>
                                    </td>
                                </tr>
                                <tr data-total>
                                    <td
                                        data-label="Total"
                                        data-primary
                                        class="text-sm font-semibold"
                                    >
                                        Total
                                    </td>
                                    <td
                                        data-label="Medicines"
                                        class="text-right text-sm"
                                    >
                                        {{ report.stock.length }}
                                        {{
                                            report.stock.length === 1
                                                ? 'medicine'
                                                : 'medicines'
                                        }}
                                    </td>
                                    <td aria-hidden="true" />
                                    <td
                                        data-label="Value"
                                        class="text-right text-sm font-semibold whitespace-nowrap"
                                    >
                                        {{ money(s.stockValueSen) }}
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
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
                        <V2Table
                            label="Sales, cost and margin per medicine"
                            :columns="5"
                            cards="xl"
                            min-width="880px"
                            :empty="report.medicines.length === 0"
                            empty-message="No medicine sold or dispensed in this period."
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Medicine</th>
                                    <th scope="col" class="text-right">
                                        Sales
                                    </th>
                                    <th scope="col" class="text-right">Cost</th>
                                    <th scope="col" class="text-right">
                                        Margin
                                    </th>
                                    <th scope="col" class="text-right">
                                        Margin %
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="row in rowsOf(report.medicines)"
                                    :key="row.code"
                                >
                                    <td data-label="Medicine" data-primary>
                                        <span
                                            class="block text-sm font-medium"
                                            >{{ row.medicine }}</span
                                        >
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
                                    </td>
                                    <td
                                        data-label="Sales"
                                        class="text-right text-sm whitespace-nowrap"
                                    >
                                        {{ money(row.salesSen) }}
                                    </td>
                                    <td
                                        data-label="Cost"
                                        class="text-right text-sm whitespace-nowrap"
                                    >
                                        {{ money(row.costSen) }}
                                    </td>
                                    <td
                                        data-label="Margin"
                                        class="text-right text-sm font-medium whitespace-nowrap"
                                    >
                                        <span
                                            class="inline-flex flex-col items-end"
                                        >
                                            <span>{{
                                                money(row.marginSen)
                                            }}</span>
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
                                        data-label="Margin %"
                                        class="text-right text-sm whitespace-nowrap"
                                    >
                                        {{ percent(row.marginPercent) }}
                                    </td>
                                </tr>
                                <tr data-total>
                                    <td
                                        data-label="Total"
                                        data-primary
                                        class="text-sm font-semibold"
                                    >
                                        Total
                                    </td>
                                    <td
                                        data-label="Sales"
                                        class="text-right text-sm font-semibold whitespace-nowrap"
                                    >
                                        {{ money(s.salesSen) }}
                                    </td>
                                    <td
                                        data-label="Cost"
                                        class="text-right text-sm font-semibold whitespace-nowrap"
                                    >
                                        {{ money(s.dispenseCostSen) }}
                                    </td>
                                    <td
                                        data-label="Margin"
                                        class="text-right text-sm font-semibold whitespace-nowrap"
                                    >
                                        <span
                                            class="inline-flex flex-col items-end"
                                        >
                                            <span>{{
                                                money(s.marginSen)
                                            }}</span>
                                            <span
                                                v-if="marginStatus"
                                                class="text-xs font-medium text-warning-strong"
                                                >{{ marginStatus }}</span
                                            >
                                        </span>
                                    </td>
                                    <td
                                        data-label="Margin %"
                                        class="text-right text-sm font-semibold whitespace-nowrap"
                                    >
                                        {{ percent(s.marginPercent) }}
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
                    </template>

                    <!-- Losses -->
                    <template v-else-if="tab === 'losses'">
                        <p
                            v-if="report.losses.creditsWithoutAmount > 0"
                            class="flex items-start gap-2 rounded-[14px] border border-warning bg-warning/10 p-3 text-sm text-warning-strong"
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
                        <V2Table
                            label="Losses in this period"
                            :columns="3"
                            cards="xl"
                            min-width="720px"
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Loss</th>
                                    <th scope="col" class="text-right">
                                        Records
                                    </th>
                                    <th scope="col" class="text-right">
                                        Amount
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="row in report.losses.rows"
                                    :key="row.key"
                                >
                                    <td data-label="Loss" data-primary>
                                        <span
                                            class="block text-sm font-medium"
                                            >{{ row.label }}</span
                                        >
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
                                        data-label="Records"
                                        class="text-right text-sm whitespace-nowrap"
                                    >
                                        {{ row.count }}
                                    </td>
                                    <td
                                        data-label="Amount"
                                        class="text-right text-sm font-medium whitespace-nowrap"
                                    >
                                        <span
                                            class="inline-flex flex-col items-end"
                                        >
                                            <span>{{
                                                money(Math.abs(row.sen))
                                            }}</span>
                                            <!-- Every non-zero row says which way it goes, so the column adds up in words too (Daniel). -->
                                            <span
                                                v-if="row.sen !== 0"
                                                class="text-xs font-medium"
                                                :class="
                                                    row.sen > 0
                                                        ? 'text-warning-strong'
                                                        : ''
                                                "
                                                >{{
                                                    row.sen > 0
                                                        ? 'Loss'
                                                        : 'Gain'
                                                }}</span
                                            >
                                        </span>
                                    </td>
                                </tr>
                                <tr data-total>
                                    <td
                                        data-label="Total"
                                        data-primary
                                        class="text-sm font-semibold"
                                    >
                                        Total
                                    </td>
                                    <td aria-hidden="true" />
                                    <td
                                        data-label="Amount"
                                        class="text-right text-sm font-semibold whitespace-nowrap"
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
                        </V2Table>
                    </template>

                    <!-- How this is counted -->
                    <template v-else>
                        <p
                            class="flex items-start gap-2 rounded-[14px] border border-warning bg-warning/10 px-3 py-2 text-xs font-medium text-warning-strong"
                        >
                            <TriangleAlert
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{{ report.banner }}</span>
                        </p>
                        <ul
                            class="list-disc space-y-2 pl-5 text-sm"
                            data-testid="valuation-rules"
                        >
                            <li>
                                <strong>Weighted average cost</strong> per
                                medicine per branch. A purchase receipt moves
                                the average: value before plus the receipt's
                                cost, divided by the quantity before plus what
                                was received. The receipt's cost is the lot
                                total paid, else the unit cost times the
                                quantity.
                            </li>
                            <li>
                                <strong>Opening stock</strong> enters at the
                                unit cost entered for it.
                            </li>
                            <li>
                                <strong>Stock leaving</strong> (dispensing,
                                treatment-room use and waste, write-offs,
                                supplier returns, count losses) leaves at the
                                average at that moment.
                            </li>
                            <li>
                                <strong>Transfers</strong> leave one branch at
                                its average and arrive at the same cost.
                            </li>
                            <li>
                                <strong>Purchase order estimates</strong> are
                                used when nothing was paid yet, and marked
                                "Includes an estimate" until that stock is gone.
                            </li>
                            <li>
                                <strong>Stock with no cost</strong> enters at
                                the current average. With no average yet it is
                                counted apart and named, never valued at zero.
                            </li>
                            <li>
                                <strong
                                    >Patient returns, count gains and
                                    adjustments in</strong
                                >
                                enter at the current average. Returned medicine
                                is not taken off the cost of goods sold.
                            </li>
                            <li>
                                <strong>Held stock</strong> is valued.
                                <strong>Rejected lots</strong> never enter
                                stock.
                            </li>
                            <li>
                                <strong>Supplier credit notes</strong> do not
                                move the average; the difference between the
                                credit and the cost of the returned stock is
                                shown under Losses.
                            </li>
                            <li>
                                <strong>Medicine sales</strong> are the medicine
                                lines of invoices finalised in the period and
                                not voided, before invoice discounts or
                                surcharges.
                            </li>
                            <li>
                                No delivery or other costs are added to stock.
                            </li>
                        </ul>
                    </template>

                    <p
                        v-if="tab === 'value' && report.stock.length > 0"
                        class="text-xs text-muted-foreground"
                        data-testid="valuation-decimals"
                    >
                        Unit costs show up to 4 decimals; values are rounded to
                        sen.
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
                    <V2Pager
                        v-if="tab === 'value' && lastPage(report.stock) > 1"
                        :current-page="page"
                        :last-page="lastPage(report.stock)"
                        :total="report.stock.length"
                        :per-page="PAGE_SIZE"
                        @change="(p) => (page = p)"
                    />
                    <V2Pager
                        v-if="
                            tab === 'margin' && lastPage(report.medicines) > 1
                        "
                        :current-page="page"
                        :last-page="lastPage(report.medicines)"
                        :total="report.medicines.length"
                        :per-page="PAGE_SIZE"
                        @change="(p) => (page = p)"
                    />
                </section>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
