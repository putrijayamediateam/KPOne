<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { myr, myrNoBreak } from '@/types/billing';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Insights', href: '/insights/today' },
            { title: 'Overview', href: '/insights/overview' },
        ],
    },
});

// BIL-31: the Director and Finance overview (owner item 32, P8, answers all A). The server works out every figure;
// counts and amounts only, for every active branch. No red or green anywhere.

type Progress = {
    targetSen: number | null;
    achievedSen: number;
    balanceSen: number | null;
    overSen: number | null;
    neededPerDaySen: number | null;
    daysLeft: number | null;
    percent: number | null;
    reached: boolean;
};
type BranchRow = {
    branchId: number;
    branch: string;
    code: string;
    sales: {
        todaySoFarSen: number;
        monthToYesterdaySen: number | null;
        lastMonthSameDaysSen: number | null;
        lastMonthFrom: string | null;
        lastMonthTo: string | null;
    };
    target: Progress;
    panel: {
        awaitingDecisionSen: number;
        awaitingDecisionOver90Sen: number;
        acceptedOutstandingSen: number;
        acceptedOutstandingOver90Sen: number;
        unclaimedSen: number;
        unclaimedOver90Sen: number;
    };
    yesterdayClose: string;
    waiting: Record<string, number>;
};

const props = defineProps<{
    canOverview?: boolean;
    overview: {
        month: string;
        branches: BranchRow[];
        organisationWaiting: Record<string, number>;
        canSetTargets: boolean;
    };
}>();

const sections = [
    { id: 'today', title: 'Today', href: '/insights/today' },
    { id: 'overview', title: 'Overview', href: '/insights/overview' },
    { id: 'sales', title: 'Sales', href: '/insights/sales' },
    { id: 'in-clinic', title: 'In-clinic', href: '/insights/in-clinic' },
    { id: 'payments', title: 'Payments', href: '/insights/payments' },
    { id: 'inventory', title: 'Inventory', href: '/insights/inventory' },
    { id: 'patients', title: 'Patients', href: '/insights/patients' },
];

const monthTitle = computed(() =>
    new Date(`${props.overview.month}-01T00:00:00`).toLocaleDateString(
        'en-MY',
        { month: 'long', year: 'numeric' },
    ),
);
const shortDate = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('en-MY', {
        day: 'numeric',
        month: 'short',
    });
const signed = (sen: number) =>
    sen > 0 ? `+${myrNoBreak(sen)}` : myrNoBreak(sen);

const closeLabels: Record<string, string> = {
    approved: 'Approved and locked',
    waiting: 'Waiting for approval',
    returned: 'Returned for recount',
    not_counted: 'Not counted',
    before_start: 'Before the start date',
    open: 'Open',
};

// Daniel: every waiting row says its unit, so "3" reads as three days waiting.
const unit = (kind: string, count: number) =>
    kind === 'cash_close'
        ? `${count} ${count === 1 ? 'day' : 'days'} waiting`
        : `${count} waiting`;

// Neutral dot for "never due", amber for a close that needs work; no red.
const closeTone: Record<string, string> = {
    approved: 'bg-muted-foreground/40',
    open: 'bg-muted-foreground/40',
    before_start: 'bg-muted-foreground/40',
    waiting: 'bg-amber-500',
    returned: 'bg-amber-500',
    not_counted: 'bg-amber-500',
};

const panelTotal = computed(() =>
    props.overview.branches.reduce(
        (total, row) => ({
            awaitingDecisionSen:
                total.awaitingDecisionSen + row.panel.awaitingDecisionSen,
            awaitingDecisionOver90Sen:
                total.awaitingDecisionOver90Sen +
                row.panel.awaitingDecisionOver90Sen,
            acceptedOutstandingSen:
                total.acceptedOutstandingSen + row.panel.acceptedOutstandingSen,
            acceptedOutstandingOver90Sen:
                total.acceptedOutstandingOver90Sen +
                row.panel.acceptedOutstandingOver90Sen,
            unclaimedSen: total.unclaimedSen + row.panel.unclaimedSen,
            unclaimedOver90Sen:
                total.unclaimedOver90Sen + row.panel.unclaimedOver90Sen,
        }),
        {
            awaitingDecisionSen: 0,
            awaitingDecisionOver90Sen: 0,
            acceptedOutstandingSen: 0,
            acceptedOutstandingOver90Sen: 0,
            unclaimedSen: 0,
            unclaimedOver90Sen: 0,
        },
    ),
);
const panelColumns = [
    {
        label: 'Awaiting decision',
        key: 'awaitingDecisionSen',
        over: 'awaitingDecisionOver90Sen',
    },
    {
        label: 'Accepted not paid',
        key: 'acceptedOutstandingSen',
        over: 'acceptedOutstandingOver90Sen',
    },
    {
        label: 'Not yet claimed',
        key: 'unclaimedSen',
        over: 'unclaimedOver90Sen',
    },
] as const;

const kindLabels: Record<string, { label: string; href: string }> = {
    cash_close: { label: 'Daily cash close', href: '/cash-closings' },
    refund: { label: 'Refunds', href: '/financial-work' },
    panel_or_pay_later: {
        label: 'Panel or pay later shares',
        href: '/financial-work',
    },
    panel_claim_adjustment: {
        label: 'Panel claim adjustments',
        href: '/panel-claims',
    },
    supplier_invoice: {
        label: 'Supplier invoices',
        href: '/inventory/supplier-invoices',
    },
    purchase_order: { label: 'Purchase orders', href: '/inventory' },
    panel_price_change: {
        label: 'Panel price changes',
        href: '/panel-price-changes',
    },
};

const waitingLines = computed(() => {
    const lines: {
        key: string;
        kind: string;
        label: string;
        where: string;
        count: number;
        href: string;
    }[] = [];

    for (const branch of props.overview.branches) {
        for (const [kind, count] of Object.entries(branch.waiting)) {
            if (count > 0 && kindLabels[kind]) {
                lines.push({
                    key: `${kind}-${branch.branchId}`,
                    kind,
                    label: kindLabels[kind].label,
                    where: branch.branch,
                    count,
                    href: kindLabels[kind].href,
                });
            }
        }
    }

    for (const [kind, count] of Object.entries(
        props.overview.organisationWaiting,
    )) {
        if (count > 0 && kindLabels[kind]) {
            lines.push({
                key: kind,
                kind,
                label: kindLabels[kind].label,
                where: 'All branches',
                count,
                href: kindLabels[kind].href,
            });
        }
    }

    return lines;
});

const totals = computed(() => {
    const rows = props.overview.branches;
    const sum = (pick: (row: BranchRow) => number | null) =>
        rows.some((row) => pick(row) === null)
            ? null
            : rows.reduce((total, row) => total + (pick(row) ?? 0), 0);

    return {
        today: sum((row) => row.sales.todaySoFarSen),
        monthToYesterday: sum((row) => row.sales.monthToYesterdaySen),
        lastMonth: sum((row) => row.sales.lastMonthSameDaysSen),
    };
});
const firstRow = computed(() => props.overview.branches[0]);
</script>

<template>
    <Head title="Insights · Overview" />
    <main
        class="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-5 p-4 md:gap-6 md:p-6"
    >
        <header>
            <p
                class="text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase"
            >
                Insights · {{ monthTitle }}
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                Director and Finance overview
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Every branch, as it stands now. Each figure links to the page
                that holds the detail.
            </p>
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
                    item.id === 'overview'
                        ? 'border-primary font-medium text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :aria-current="item.id === 'overview' ? 'page' : undefined"
            >
                {{ item.title }}
            </a>
        </nav>

        <div class="grid gap-5 xl:grid-cols-2">
            <section
                class="min-w-0 rounded-xl border bg-card p-4"
                aria-labelledby="overview-targets"
                data-testid="overview-targets"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2 id="overview-targets" class="font-semibold">
                        Targets this month
                    </h2>
                    <a
                        v-if="overview.canSetTargets"
                        href="/sales-targets"
                        class="view-link"
                        data-testid="overview-view-targets"
                        >View<ChevronRight class="size-3.5" aria-hidden="true"
                    /></a>
                </div>
                <ul class="mt-3 divide-y">
                    <li
                        v-for="row in overview.branches"
                        :key="row.branchId"
                        class="grid gap-1 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-3"
                        :data-testid="`overview-target-${row.code}`"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">{{ row.branch }}</p>
                            <template v-if="row.target.targetSen !== null">
                                <p class="text-sm tabular-nums">
                                    {{ myrNoBreak(row.target.achievedSen) }} of
                                    {{ myrNoBreak(row.target.targetSen) }} ({{
                                        row.target.percent ?? 0
                                    }}%)
                                </p>
                                <p
                                    v-if="
                                        row.target.reached ||
                                        row.target.neededPerDaySen !== null
                                    "
                                    class="text-sm whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{
                                        row.target.reached
                                            ? `Target reached, ${myrNoBreak(row.target.overSen ?? 0)} over`
                                            : `${myrNoBreak(row.target.neededPerDaySen ?? 0)} a day · ${row.target.daysLeft} ${row.target.daysLeft === 1 ? 'day' : 'days'} left`
                                    }}
                                </p>
                            </template>
                            <p v-else class="text-sm text-muted-foreground">
                                No target ·
                                {{ myrNoBreak(row.target.achievedSen) }} so far
                            </p>
                            <div
                                v-if="row.target.targetSen !== null"
                                class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                :aria-valuenow="
                                    Math.min(100, row.target.percent ?? 0)
                                "
                                aria-valuemin="0"
                                aria-valuemax="100"
                                :aria-label="`${row.branch}: ${row.target.percent ?? 0}% of the target reached`"
                            >
                                <div
                                    class="h-full bg-brand"
                                    :style="{
                                        width: `${Math.min(100, row.target.percent ?? 0)}%`,
                                    }"
                                />
                            </div>
                        </div>
                        <a
                            v-if="
                                row.target.targetSen === null &&
                                overview.canSetTargets
                            "
                            href="/sales-targets"
                            class="view-link justify-self-start sm:justify-self-end"
                            :data-testid="`overview-set-target-${row.code}`"
                            >Set target</a
                        >
                    </li>
                </ul>
            </section>

            <section
                class="min-w-0 rounded-xl border bg-card p-4"
                aria-labelledby="overview-waiting"
                data-testid="overview-waiting"
            >
                <h2 id="overview-waiting" class="font-semibold">
                    Waiting for a decision
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Everything waiting, including what you recorded yourself.
                    The Approvals inbox shows only what you may decide.
                </p>
                <ul v-if="waitingLines.length" class="mt-3 divide-y">
                    <li v-for="line in waitingLines" :key="line.key">
                        <a
                            :href="line.href"
                            class="-mx-2 flex min-h-11 items-center justify-between gap-3 rounded-md px-2 text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :data-testid="`overview-waiting-${line.key}`"
                        >
                            <span class="min-w-0"
                                >{{ line.label }}
                                <span class="text-muted-foreground"
                                    >· {{ line.where }}</span
                                ></span
                            >
                            <span
                                class="flex shrink-0 items-center gap-1 font-medium tabular-nums"
                                >{{ unit(line.kind, line.count)
                                }}<ChevronRight
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                            /></span>
                        </a>
                    </li>
                </ul>
                <p
                    v-else
                    class="mt-3 text-sm text-muted-foreground"
                    data-testid="overview-nothing-waiting"
                >
                    Nothing is waiting.
                </p>
                <h3 class="mt-4 text-sm font-medium">
                    Yesterday's daily close
                </h3>
                <ul class="mt-1 grid gap-1 text-sm">
                    <li
                        v-for="row in overview.branches"
                        :key="row.branchId"
                        class="flex justify-between gap-3"
                        :data-testid="`overview-close-${row.code}`"
                    >
                        <span>{{ row.branch }}</span>
                        <span
                            class="inline-flex items-center gap-1.5 text-muted-foreground"
                            ><span
                                class="size-2 rounded-full"
                                :class="
                                    closeTone[row.yesterdayClose] ??
                                    'bg-muted-foreground/40'
                                "
                                aria-hidden="true"
                            />{{
                                closeLabels[row.yesterdayClose] ??
                                row.yesterdayClose
                            }}</span
                        >
                    </li>
                </ul>
            </section>

            <section
                class="min-w-0 rounded-xl border bg-card p-4"
                aria-labelledby="overview-panel"
                data-testid="overview-panel"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2 id="overview-panel" class="font-semibold">
                        Panel money owed
                    </h2>
                    <a href="/panel-claims/follow-up" class="view-link"
                        >View<ChevronRight class="size-3.5" aria-hidden="true"
                    /></a>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    All Panel bills to date. The Panel summary report covers at
                    most 31 days.
                </p>
                <dl
                    class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3"
                    data-testid="overview-panel-total"
                >
                    <div
                        v-for="col in panelColumns"
                        :key="col.key"
                        class="flex items-baseline justify-between gap-3 sm:block"
                    >
                        <dt class="text-xs text-muted-foreground">
                            {{ col.label }}
                        </dt>
                        <dd class="font-medium tabular-nums">
                            {{ myrNoBreak(panelTotal[col.key]) }}
                        </dd>
                        <dd
                            v-if="panelTotal[col.over] > 0"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            over 90 days
                            {{ myrNoBreak(panelTotal[col.over]) }}
                        </dd>
                    </div>
                </dl>
                <details
                    class="group mt-3"
                    data-testid="overview-panel-branches"
                >
                    <summary
                        class="view-link cursor-pointer list-none [&::-webkit-details-marker]:hidden"
                    >
                        By branch ({{ overview.branches.length }})
                        <ChevronDown
                            class="size-3.5 transition-transform group-open:rotate-180"
                            aria-hidden="true"
                        />
                    </summary>
                    <div
                        class="mt-2 hidden gap-1 text-xs text-muted-foreground sm:grid sm:grid-cols-[8rem_repeat(3,minmax(0,1fr))]"
                        aria-hidden="true"
                    >
                        <span>Branch</span>
                        <span v-for="col in panelColumns" :key="col.key">{{
                            col.label
                        }}</span>
                    </div>
                    <ul class="mt-1 divide-y">
                        <li
                            v-for="row in overview.branches"
                            :key="row.branchId"
                            class="grid gap-1 py-2 text-sm sm:grid-cols-[8rem_repeat(3,minmax(0,1fr))] sm:items-start"
                            :data-testid="`overview-panel-${row.code}`"
                        >
                            <p class="font-medium">{{ row.branch }}</p>
                            <div
                                v-for="col in panelColumns"
                                :key="col.key"
                                class="flex justify-between gap-2 sm:block"
                            >
                                <span class="text-muted-foreground sm:hidden">{{
                                    col.label
                                }}</span>
                                <span
                                    class="text-right tabular-nums sm:text-left"
                                    >{{ myrNoBreak(row.panel[col.key])
                                    }}<span
                                        v-if="row.panel[col.over] > 0"
                                        class="block text-xs text-muted-foreground"
                                        >over 90 days
                                        {{
                                            myrNoBreak(row.panel[col.over])
                                        }}</span
                                    ></span
                                >
                            </div>
                        </li>
                    </ul>
                </details>
            </section>

            <section
                class="min-w-0 rounded-xl border bg-card p-4"
                aria-labelledby="overview-month"
                data-testid="overview-month"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2 id="overview-month" class="font-semibold">
                        Month so far
                    </h2>
                    <a href="/insights/sales" class="view-link"
                        >View<ChevronRight class="size-3.5" aria-hidden="true"
                    /></a>
                </div>
                <p
                    v-if="firstRow && firstRow.sales.lastMonthFrom"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Net sales. The comparison uses whole days: this month to
                    yesterday, against
                    {{ shortDate(firstRow.sales.lastMonthFrom) }}–{{
                        shortDate(
                            firstRow.sales.lastMonthTo ??
                                firstRow.sales.lastMonthFrom,
                        )
                    }}. Today is shown on its own.
                </p>
                <p v-else class="mt-1 text-xs text-muted-foreground">
                    No full day yet this month. Today is shown on its own.
                </p>
                <ul
                    class="mt-3 divide-y sm:hidden"
                    data-testid="overview-month-cards"
                >
                    <li
                        v-for="row in [
                            {
                                key: 'total',
                                name: 'Total',
                                today: totals.today,
                                month: totals.monthToYesterday,
                                last: totals.lastMonth,
                            },
                            ...overview.branches.map((b) => ({
                                key: b.code,
                                name: b.branch,
                                today: b.sales.todaySoFarSen,
                                month: b.sales.monthToYesterdaySen,
                                last: b.sales.lastMonthSameDaysSen,
                            })),
                        ]"
                        :key="row.key"
                        class="py-2 text-sm"
                    >
                        <p
                            :class="
                                row.key === 'total'
                                    ? 'font-semibold'
                                    : 'font-medium'
                            "
                        >
                            {{ row.name }}
                        </p>
                        <p class="tabular-nums">
                            {{
                                row.month === null ? '—' : myrNoBreak(row.month)
                            }}
                            <span class="text-muted-foreground"
                                >to yesterday ·
                                {{
                                    row.month === null || row.last === null
                                        ? 'no comparison'
                                        : `${signed(row.month - row.last)} on last month`
                                }}
                                · today
                                {{
                                    row.today === null
                                        ? '—'
                                        : myrNoBreak(row.today)
                                }}</span
                            >
                        </p>
                    </li>
                </ul>
                <div class="mt-3 overflow-x-auto max-sm:hidden">
                    <table class="w-full min-w-[30rem] text-sm">
                        <thead>
                            <tr class="text-left text-xs text-muted-foreground">
                                <th scope="col" class="py-2 font-medium">
                                    Branch
                                </th>
                                <th
                                    scope="col"
                                    class="py-2 text-right font-medium"
                                >
                                    Today so far
                                </th>
                                <th
                                    scope="col"
                                    class="py-2 text-right font-medium"
                                >
                                    Month to yesterday
                                </th>
                                <th
                                    scope="col"
                                    class="py-2 text-right font-medium"
                                >
                                    Same days last month
                                </th>
                                <th
                                    scope="col"
                                    class="py-2 text-right font-medium"
                                >
                                    Difference
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="row in overview.branches"
                                :key="row.branchId"
                                :data-testid="`overview-month-${row.code}`"
                            >
                                <th
                                    scope="row"
                                    class="py-2 text-left font-medium"
                                >
                                    {{ row.branch }}
                                </th>
                                <td class="py-2 text-right tabular-nums">
                                    {{ myrNoBreak(row.sales.todaySoFarSen) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        row.sales.monthToYesterdaySen === null
                                            ? '—'
                                            : myrNoBreak(
                                                  row.sales.monthToYesterdaySen,
                                              )
                                    }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        row.sales.lastMonthSameDaysSen === null
                                            ? '—'
                                            : myrNoBreak(
                                                  row.sales
                                                      .lastMonthSameDaysSen,
                                              )
                                    }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        row.sales.monthToYesterdaySen ===
                                            null ||
                                        row.sales.lastMonthSameDaysSen === null
                                            ? '—'
                                            : signed(
                                                  row.sales
                                                      .monthToYesterdaySen -
                                                      row.sales
                                                          .lastMonthSameDaysSen,
                                              )
                                    }}
                                </td>
                            </tr>
                            <tr class="font-medium">
                                <th scope="row" class="py-2 text-left">
                                    Total
                                </th>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        totals.today === null
                                            ? '—'
                                            : myr(totals.today)
                                    }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        totals.monthToYesterday === null
                                            ? '—'
                                            : myr(totals.monthToYesterday)
                                    }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        totals.lastMonth === null
                                            ? '—'
                                            : myr(totals.lastMonth)
                                    }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        totals.monthToYesterday === null ||
                                        totals.lastMonth === null
                                            ? '—'
                                            : signed(
                                                  totals.monthToYesterday -
                                                      totals.lastMonth,
                                              )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</template>

<style scoped>
.view-link {
    display: inline-flex;
    min-height: 2.75rem;
    align-items: center;
    gap: 0.25rem;
    border-radius: 0.375rem;
    border: 1px solid var(--border);
    padding: 0 0.75rem;
    font-size: 0.8125rem;
    font-weight: 500;
}
.view-link:hover {
    background: var(--muted);
}
.view-link:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
@media (min-width: 1024px) {
    .view-link {
        min-height: 1.75rem;
        padding: 0 0.5rem;
    }
}
</style>
