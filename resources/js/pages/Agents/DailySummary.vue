<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { EmptyState } from '@/components/ui/state';
import { formatDate, formatDateTime } from '@/lib/presentation';

type Stamp = { slot: '0700' | '1400'; builtAt: string | null };
type ClinicFigures = {
    registered: number;
    served: number;
    averageWaitMinutes: number;
    averageConsultationMinutes: number;
};
type MoneyFigures = {
    netSalesSen: number;
    totalReceivedSen: number;
    cashNetSen: number;
    nonCashNetSen: number;
    panelApprovedSen: number;
    payLaterDueSen: number;
};
type Lina = Stamp & {
    date: string;
    kind: 'whole_day' | 'so_far';
    comparedWith: string | null;
    clinic: { now: ClinicFigures; before: ClinicFigures | null } | null;
    doctors:
        | {
              doctorId: number;
              doctor: string;
              seen: number;
              averageConsultationMinutes: number;
              seenBefore: number | null;
          }[]
        | null;
    money: { now: MoneyFigures; before: MoneyFigures | null } | null;
    panel: {
        unclaimedInvoices: number;
        unclaimedSen: number;
        overdueUnclaimedInvoices: number;
        overdueUnclaimedSen: number;
        acceptedUnpaidClaims: number;
        acceptedOutstandingSen: number;
    } | null;
    stock: { lowStock: number; expiring: number; expired: number } | null;
};
type HanaCount = { total: number; pending: number };
type Hana = Stamp & {
    date: string;
    counts: Record<'restock' | 'expiry' | 'transfer' | 'slowMoving', HanaCount>;
};
type Rizal = Stamp & {
    countsDue: number;
    openCases: number;
    unresolvedVariances: number;
    due: {
        sku: string;
        skuCode: string;
        item: string;
        locationLabel: string;
        lastCompletedCountDate: string | null;
    }[];
};
type Faris = Stamp & { allClear: boolean; summary: string };
type BranchBlock = {
    code: string;
    name: string;
    linaMorning: Lina | null;
    linaAfternoon: Lina | null;
    hana: Hana | null;
    rizal: Rizal | null;
};

const props = defineProps<{
    summary: {
        date: string;
        today: string;
        earliest: string;
        canInbox: boolean;
        branches: BranchBlock[];
        faris: Faris | null;
    };
}>();

const chosen = ref(props.summary.date);
const show = () => {
    router.get(
        '/agents/daily-summary',
        { date: chosen.value },
        { preserveScroll: true },
    );
};

const ringgit = (sen: number) =>
    `RM ${(sen / 100).toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
const slotLabel = (slot: Stamp['slot']) =>
    slot === '0700' ? '07:00 run' : '14:00 update';
const builtLine = (stamp: Stamp) =>
    stamp.builtAt
        ? `${slotLabel(stamp.slot)}, built ${formatDateTime(stamp.builtAt)}`
        : slotLabel(stamp.slot);

// No green or red: "up" is not always good (a longer wait is up), so the words carry the direction.
type Trend = { symbol: string; words: string; tone: string };
const trend = (
    now: number,
    before: number | null | undefined,
): Trend | null => {
    if (before === null || before === undefined) {
        return null;
    }

    if (now > before) {
        return {
            symbol: '▲',
            words: `up from ${before}`,
            tone: 'text-foreground',
        };
    }

    if (now < before) {
        return {
            symbol: '▼',
            words: `down from ${before}`,
            tone: 'text-foreground',
        };
    }

    return {
        symbol: '=',
        words: `same as ${before}`,
        tone: 'text-muted-foreground',
    };
};
const moneyTrend = (
    now: number,
    before: number | null | undefined,
): Trend | null => {
    const result = trend(now, before);

    if (result === null || before === null || before === undefined) {
        return result;
    }

    return {
        ...result,
        words: result.words.replace(String(before), ringgit(before)),
    };
};

const clinicRows = (lina: Lina) => {
    const now = lina.clinic!.now;
    const before = lina.clinic!.before;

    return [
        {
            label: 'Registered',
            value: String(now.registered),
            trend: trend(now.registered, before?.registered),
        },
        {
            label: 'Seen by a doctor',
            value: String(now.served),
            trend: trend(now.served, before?.served),
        },
        {
            label: 'Average wait (minutes)',
            value: String(now.averageWaitMinutes),
            trend: trend(now.averageWaitMinutes, before?.averageWaitMinutes),
        },
        {
            label: 'Average consultation (minutes)',
            value: String(now.averageConsultationMinutes),
            trend: trend(
                now.averageConsultationMinutes,
                before?.averageConsultationMinutes,
            ),
        },
    ];
};
const moneyRows = (lina: Lina) => {
    const now = lina.money!.now;
    const before = lina.money!.before;

    return [
        {
            label: 'Net invoice sales',
            value: ringgit(now.netSalesSen),
            trend: moneyTrend(now.netSalesSen, before?.netSalesSen),
        },
        {
            label: 'Total received (Cash + Non-cash)',
            value: ringgit(now.totalReceivedSen),
            trend: moneyTrend(now.totalReceivedSen, before?.totalReceivedSen),
        },
        {
            label: 'Cash received (net)',
            value: ringgit(now.cashNetSen),
            trend: moneyTrend(now.cashNetSen, before?.cashNetSen),
        },
        {
            label: 'Non-cash received (net)',
            value: ringgit(now.nonCashNetSen),
            trend: moneyTrend(now.nonCashNetSen, before?.nonCashNetSen),
        },
        {
            label: 'Panel approved',
            value: ringgit(now.panelApprovedSen),
            trend: moneyTrend(now.panelApprovedSen, before?.panelApprovedSen),
        },
        {
            label: 'Pay later due',
            value: ringgit(now.payLaterDueSen),
            trend: moneyTrend(now.payLaterDueSen, before?.payLaterDueSen),
        },
    ];
};
const hanaLabels: Record<keyof Hana['counts'], string> = {
    restock: 'Restock',
    expiry: 'Expiry warning',
    transfer: 'Transfer',
    slowMoving: 'Slow-moving',
};
const linaHeading = (lina: Lina) =>
    lina.kind === 'whole_day'
        ? `Report for ${formatDate(lina.date)}`
        : `Today so far (${formatDate(lina.date)})`;
</script>

<template>
    <Head title="Daily summary" />
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Daily summary"
            description="Built by AI agents at 07:00 and updated at 14:00. Figures are totals only."
        />
        <p class="text-sm text-muted-foreground" data-test="summary-notice">
            AI agents read the clinic's totals and write this page; they change
            nothing and send no messages. You see only the parts your role can
            already see elsewhere in KPOne.
        </p>

        <form
            class="flex flex-wrap items-end gap-3"
            data-test="summary-date"
            @submit.prevent="show"
        >
            <div class="grid gap-1.5">
                <Label for="summary-date">Date</Label>
                <Input
                    id="summary-date"
                    v-model="chosen"
                    type="date"
                    class="w-44 max-lg:min-h-11"
                    :min="summary.earliest"
                    :max="summary.today"
                />
            </div>
            <Button type="submit" class="max-lg:min-h-11">Show</Button>
        </form>

        <section
            v-if="summary.faris"
            aria-labelledby="faris-heading"
            class="rounded-xl border bg-card p-4"
            data-test="summary-faris"
        >
            <h2 id="faris-heading" class="text-base font-semibold">
                System health · Faris (AI agent)
            </h2>
            <p
                class="mt-1 text-sm"
                :class="
                    summary.faris.allClear
                        ? 'text-muted-foreground'
                        : 'font-medium'
                "
            >
                {{ summary.faris.summary }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ builtLine(summary.faris) }}
            </p>
        </section>

        <EmptyState
            v-if="summary.branches.length === 0"
            title="No branch to show"
            description="The daily summary lists the branches you work at today."
        />

        <section
            v-for="branch in summary.branches"
            :key="branch.code"
            :aria-labelledby="`branch-${branch.code}`"
            class="flex flex-col gap-4 rounded-xl border bg-card p-4"
            data-test="summary-branch"
        >
            <h2 :id="`branch-${branch.code}`" class="text-lg font-semibold">
                {{ branch.name }}
            </h2>

            <template
                v-for="lina in [branch.linaMorning, branch.linaAfternoon]"
                :key="lina?.slot ?? 'none'"
            >
                <div
                    v-if="lina"
                    class="flex flex-col gap-3"
                    :data-test="`lina-${lina.slot}`"
                >
                    <div>
                        <h3 class="text-base font-semibold">
                            {{ linaHeading(lina) }} · Lina (AI agent)
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            {{ builtLine(lina) }}.
                            <template v-if="lina.comparedWith">
                                Arrows compare with
                                {{ formatDate(lina.comparedWith) }}, the same
                                weekday last week.
                            </template>
                            <template v-else>
                                Half a day, so no comparison is shown.
                            </template>
                        </p>
                    </div>

                    <dl
                        v-if="lina.clinic"
                        class="grid gap-2 sm:grid-cols-2"
                        data-test="lina-clinic"
                    >
                        <div
                            v-for="row in clinicRows(lina)"
                            :key="row.label"
                            class="rounded-lg border px-3 py-2"
                        >
                            <dt class="text-xs text-muted-foreground">
                                {{ row.label }}
                            </dt>
                            <dd
                                class="flex items-baseline gap-2 text-base font-semibold"
                            >
                                {{ row.value }}
                                <span
                                    v-if="row.trend"
                                    class="text-xs font-medium"
                                    :class="row.trend.tone"
                                >
                                    <span aria-hidden="true">{{
                                        row.trend.symbol
                                    }}</span>
                                    {{ row.trend.words }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="lina.doctors && lina.doctors.length > 0"
                        data-test="lina-doctors"
                    >
                        <h4 class="text-sm font-semibold">
                            Seen by each doctor
                        </h4>
                        <ul class="mt-1 grid gap-1 text-sm">
                            <li
                                v-for="doctor in lina.doctors"
                                :key="doctor.doctorId"
                                class="flex flex-wrap gap-x-2"
                            >
                                <span class="font-medium">{{
                                    doctor.doctor
                                }}</span>
                                <span
                                    >{{ doctor.seen }} seen, about
                                    {{ doctor.averageConsultationMinutes }} min
                                    each</span
                                >
                                <span
                                    v-if="trend(doctor.seen, doctor.seenBefore)"
                                    class="text-xs"
                                    :class="
                                        trend(doctor.seen, doctor.seenBefore)!
                                            .tone
                                    "
                                >
                                    <span aria-hidden="true">{{
                                        trend(doctor.seen, doctor.seenBefore)!
                                            .symbol
                                    }}</span>
                                    {{
                                        trend(doctor.seen, doctor.seenBefore)!
                                            .words
                                    }}
                                </span>
                            </li>
                        </ul>
                    </div>

                    <dl
                        v-if="lina.money"
                        class="grid gap-2 sm:grid-cols-2"
                        data-test="lina-money"
                    >
                        <div
                            v-for="row in moneyRows(lina)"
                            :key="row.label"
                            class="rounded-lg border px-3 py-2"
                        >
                            <dt class="text-xs text-muted-foreground">
                                {{ row.label }}
                            </dt>
                            <dd
                                class="flex flex-wrap items-baseline gap-x-2 text-base font-semibold"
                            >
                                {{ row.value }}
                                <span
                                    v-if="row.trend"
                                    class="text-xs font-medium"
                                    :class="row.trend.tone"
                                >
                                    <span aria-hidden="true">{{
                                        row.trend.symbol
                                    }}</span>
                                    {{ row.trend.words }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <p v-if="lina.panel" class="text-sm" data-test="lina-panel">
                        Panel, as of this run:
                        {{ lina.panel.unclaimedInvoices }} invoices not claimed
                        yet ({{ ringgit(lina.panel.unclaimedSen) }}),
                        {{ lina.panel.overdueUnclaimedInvoices }} of them
                        overdue; {{ lina.panel.acceptedUnpaidClaims }} accepted
                        claims waiting for payment ({{
                            ringgit(lina.panel.acceptedOutstandingSen)
                        }}).
                    </p>
                    <p v-if="lina.stock" class="text-sm" data-test="lina-stock">
                        Stock, as of this run: {{ lina.stock.lowStock }} low,
                        {{ lina.stock.expiring }} expiring within 90 days,
                        {{ lina.stock.expired }} expired on hand.
                    </p>
                </div>
            </template>
            <p
                v-if="!branch.linaMorning && !branch.linaAfternoon"
                class="text-sm text-muted-foreground"
                data-test="lina-missing"
            >
                No report from Lina for this date yet.
            </p>

            <div v-if="branch.hana" data-test="summary-hana">
                <h3 class="text-base font-semibold">
                    Stock suggestions · Hana (AI agent)
                </h3>
                <p class="text-xs text-muted-foreground">
                    {{ builtLine(branch.hana) }}.
                </p>
                <ul class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                    <li v-for="(label, key) in hanaLabels" :key="key">
                        {{ label }}: {{ branch.hana.counts[key].total }} made,
                        {{ branch.hana.counts[key].pending }} waiting for a
                        decision
                    </li>
                </ul>
                <Link
                    v-if="summary.canInbox"
                    href="/agents/inbox"
                    class="mt-2 inline-flex min-h-11 items-center text-sm font-medium text-primary underline-offset-4 hover:underline lg:min-h-0"
                    data-test="summary-inbox-link"
                >
                    Open the approval inbox (it lists your active branch)
                </Link>
            </div>

            <div v-if="branch.rizal" data-test="summary-rizal">
                <h3 class="text-base font-semibold">
                    Stock counts · Rizal (AI agent)
                </h3>
                <p class="text-xs text-muted-foreground">
                    {{ builtLine(branch.rizal) }}.
                </p>
                <p class="mt-1 text-sm">
                    {{ branch.rizal.countsDue }} counts due,
                    {{ branch.rizal.openCases }} open count cases,
                    {{ branch.rizal.unresolvedVariances }} differences not yet
                    resolved.
                </p>
                <ul
                    v-if="branch.rizal.due.length > 0"
                    class="mt-1 grid gap-1 text-sm"
                >
                    <li
                        v-for="item in branch.rizal.due"
                        :key="`${item.sku}-${item.locationLabel}`"
                    >
                        {{ item.item }} ({{ item.skuCode }}) at
                        {{ item.locationLabel }}:
                        {{
                            item.lastCompletedCountDate
                                ? `last counted ${formatDate(item.lastCompletedCountDate)}`
                                : 'never counted'
                        }}
                    </li>
                </ul>
            </div>
        </section>
    </main>
</template>
