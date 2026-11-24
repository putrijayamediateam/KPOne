<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { myr, myrNoBreak } from '@/types/billing';

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
    supplier_invoice: { label: 'Supplier invoices', href: '/inventory' },
    purchase_order: { label: 'Purchase orders', href: '/inventory' },
    panel_price_change: {
        label: 'Panel price changes',
        href: '/panel-price-changes',
    },
};

const waitingLines = computed(() => {
    const lines: {
        key: string;
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
                <div class="flex items-baseline justify-between gap-2">
                    <h2 id="overview-targets" class="font-semibold">
                        Targets this month
                    </h2>
                    <a
                        v-if="overview.canSetTargets"
                        href="/sales-targets"
                        class="inline-flex min-h-11 items-center text-sm text-muted-foreground underline-offset-4 hover:underline lg:min-h-0"
                        >View</a
                    >
                </div>
                <ul class="mt-3 divide-y">
                    <li
                        v-for="row in overview.branches"
                        :key="row.branchId"
                        class="grid gap-2 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
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
                                <div
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
                            </template>
                            <p v-else class="text-sm text-muted-foreground">
                                No target ·
                                {{ myrNoBreak(row.target.achievedSen) }} so far
                            </p>
                        </div>
                        <p
                            class="text-sm whitespace-nowrap text-muted-foreground tabular-nums sm:text-right"
                        >
                            <template v-if="row.target.targetSen === null">
                                <a
                                    v-if="overview.canSetTargets"
                                    href="/sales-targets"
                                    class="inline-flex min-h-11 items-center underline-offset-4 hover:underline lg:min-h-0"
                                    >Set target</a
                                >
                            </template>
                            <template v-else-if="row.target.reached"
                                >Target reached,
                                {{ myrNoBreak(row.target.overSen ?? 0) }}
                                over</template
                            >
                            <template
                                v-else-if="row.target.neededPerDaySen !== null"
                                >{{ myrNoBreak(row.target.neededPerDaySen) }} a
                                day · {{ row.target.daysLeft }}
                                {{ row.target.daysLeft === 1 ? 'day' : 'days' }}
                                left</template
                            >
                        </p>
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
                    <li
                        v-for="line in waitingLines"
                        :key="line.key"
                        class="flex items-center justify-between gap-3 py-2 text-sm"
                    >
                        <span class="min-w-0"
                            >{{ line.label }}
                            <span class="text-muted-foreground"
                                >· {{ line.where }}</span
                            ></span
                        >
                        <a
                            :href="line.href"
                            class="inline-flex min-h-11 min-w-11 items-center justify-end font-medium tabular-nums underline-offset-4 hover:underline lg:min-h-0"
                            :aria-label="`${line.label}, ${line.where}: ${line.count} waiting`"
                            >{{ line.count }}</a
                        >
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
                        <span class="text-muted-foreground">{{
                            closeLabels[row.yesterdayClose] ??
                            row.yesterdayClose
                        }}</span>
                    </li>
                </ul>
            </section>

            <section
                class="min-w-0 rounded-xl border bg-card p-4"
                aria-labelledby="overview-panel"
                data-testid="overview-panel"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <h2 id="overview-panel" class="font-semibold">
                        Panel money owed
                    </h2>
                    <a
                        href="/panel-claims/follow-up"
                        class="inline-flex min-h-11 items-center text-sm text-muted-foreground underline-offset-4 hover:underline lg:min-h-0"
                        >View</a
                    >
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    All Panel bills to date. The Panel summary report covers at
                    most 31 days.
                </p>
                <ul class="mt-3 divide-y">
                    <li
                        v-for="row in overview.branches"
                        :key="row.branchId"
                        class="py-3"
                        :data-testid="`overview-panel-${row.code}`"
                    >
                        <p class="font-medium">{{ row.branch }}</p>
                        <dl
                            class="mt-1 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3"
                        >
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Awaiting decision
                                </dt>
                                <dd class="tabular-nums">
                                    {{
                                        myrNoBreak(
                                            row.panel.awaitingDecisionSen,
                                        )
                                    }}
                                </dd>
                                <dd
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    over 90 days
                                    {{
                                        myrNoBreak(
                                            row.panel.awaitingDecisionOver90Sen,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Accepted not paid
                                </dt>
                                <dd class="tabular-nums">
                                    {{
                                        myrNoBreak(
                                            row.panel.acceptedOutstandingSen,
                                        )
                                    }}
                                </dd>
                                <dd
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    over 90 days
                                    {{
                                        myrNoBreak(
                                            row.panel
                                                .acceptedOutstandingOver90Sen,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Not yet claimed
                                </dt>
                                <dd class="tabular-nums">
                                    {{ myrNoBreak(row.panel.unclaimedSen) }}
                                </dd>
                                <dd
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    over 90 days
                                    {{
                                        myrNoBreak(row.panel.unclaimedOver90Sen)
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </li>
                </ul>
            </section>

            <section
                class="min-w-0 rounded-xl border bg-card p-4"
                aria-labelledby="overview-month"
                data-testid="overview-month"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <h2 id="overview-month" class="font-semibold">
                        Month so far
                    </h2>
                    <a
                        href="/insights/sales"
                        class="inline-flex min-h-11 items-center text-sm text-muted-foreground underline-offset-4 hover:underline lg:min-h-0"
                        >View</a
                    >
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
                <div class="mt-3 overflow-x-auto">
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
