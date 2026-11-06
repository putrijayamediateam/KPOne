<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { PageHeader } from '@/components/ui/page-header';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { myr } from '@/types/billing';

type Status =
    | 'approved'
    | 'waiting'
    | 'returned'
    | 'not_counted'
    | 'open'
    | 'before_start';
type Flag = 'no_count' | 'not_approved' | 'changed_after_approval';
type Row = {
    date: string;
    status: Status;
    countedCashSen: number | null;
    expectedCashSen: number | null;
    differenceSen: number | null;
    countedBy: string | null;
    decision: 'approved' | 'returned' | null;
    decidedBy: string | null;
    flags: Flag[];
};

const props = defineProps<{
    cashClosingMonth: {
        branch: string;
        month: string;
        today: string;
        isCurrentMonth: boolean;
        previousMonth: string | null;
        nextMonth: string | null;
        summary: {
            approved: number;
            waiting: number;
            returned: number;
            notCounted: number;
            countedSen: number;
            expectedSen: number;
            differenceSen: number;
            countsShown: number;
        };
        hasClosings: boolean;
        startsOn: string | null;
        beforeStart: boolean;
        rows: Row[];
    };
}>();

const view = computed(() => props.cashClosingMonth);
const loading = ref(false);
const failed = ref(false);
const lastTried = ref<string | null>(null);
const failedBy = ref<'http' | 'network'>('network');
const lastDay = computed(
    () => view.value.rows.at(-1)?.date ?? view.value.today,
);

// BIL-6: refunds can make a day's cash below zero, which the shared myr() does not format (same as the daily page).
const money = (sen: number) => (sen < 0 ? `−${myr(-sen)}` : myr(sen));
const signed = (sen: number) =>
    sen === 0
        ? 'Matches'
        : `${myr(Math.abs(sen))} ${sen < 0 ? 'short' : 'over'}`;

const asDate = (iso: string) => {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d));
};
const monthTitle = computed(() =>
    asDate(`${view.value.month}-01`).toLocaleDateString('en-GB', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }),
);
const titleOf = (month: string | null) =>
    month === null
        ? 'this month'
        : asDate(`${month}-01`).toLocaleDateString('en-GB', {
              month: 'long',
              year: 'numeric',
              timeZone: 'UTC',
          });
const plainDate = (iso: string) =>
    asDate(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
const shortDate = (iso: string) =>
    asDate(iso).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
const longDate = (iso: string) =>
    asDate(iso).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });

// Explicit tones: the shared "returned" key is pink (accent), which reads as brand, not as a problem.
const statusInfo: Record<
    Status,
    { label: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }
> = {
    approved: { label: 'Approved', tone: 'success' },
    waiting: { label: 'Waiting for approval', tone: 'warning' },
    returned: { label: 'Returned', tone: 'danger' },
    not_counted: { label: 'Not counted', tone: 'danger' },
    open: { label: 'Open', tone: 'neutral' },
    before_start: { label: 'Not in use yet', tone: 'neutral' },
};
// Flags add only what the badge does not already say (design lead, 2026-11-06): "No count" and "Not approved"
// repeat the Not counted, Waiting and Returned badges, so those rows keep the warning border without the text.
const changedText =
    'Expected cash changed after approval. The approved figures are shown.';
const changed = (row: Row) => row.flags.includes('changed_after_approval');

const dayHref = (row: Row) => `/cash-closings?date=${row.date}`;
const decidedText = (row: Row) =>
    row.decidedBy === null
        ? null
        : `${row.decision === 'approved' ? 'Approved' : 'Returned'} by ${row.decidedBy}`;

const goTo = (month: string | null) => {
    if (month === null || loading.value) {
        return;
    }

    failed.value = false;
    lastTried.value = month;
    router.get(
        '/cash-closings/month',
        { month },
        {
            preserveScroll: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
            onHttpException: () => {
                failed.value = true;
                failedBy.value = 'http';

                return false;
            },
            onNetworkError: () => {
                failed.value = true;
                failedBy.value = 'network';

                return false;
            },
        },
    );
};
</script>

<template>
    <Head title="Monthly cash closing" />
    <main class="mx-auto w-full max-w-6xl space-y-4 px-4 py-6 md:px-6">
        <PageHeader
            title="Monthly cash closing"
            :description="`${view.branch} · One row per day. Open a day to count or approve.`"
        >
            <template #actions>
                <ActionLink
                    :href="`/cash-closings?date=${lastDay}`"
                    variant="outline"
                >
                    Daily view
                </ActionLink>
            </template>
        </PageHeader>

        <nav
            aria-label="Month"
            class="flex items-center justify-between gap-2 rounded-xl border bg-card p-2"
        >
            <button
                type="button"
                class="inline-flex min-h-11 items-center gap-1 rounded-md px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-disabled:cursor-not-allowed aria-disabled:text-muted-foreground aria-disabled:hover:bg-transparent"
                :aria-disabled="
                    view.previousMonth === null || loading ? 'true' : undefined
                "
                data-testid="month-previous"
                @click="goTo(view.previousMonth)"
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
                <span>Previous<span class="max-sm:sr-only"> month</span></span>
            </button>
            <h2 class="text-base font-semibold" data-testid="month-title">
                {{ monthTitle }}
            </h2>
            <button
                type="button"
                class="inline-flex min-h-11 items-center gap-1 rounded-md px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-disabled:cursor-not-allowed aria-disabled:text-muted-foreground aria-disabled:hover:bg-transparent"
                :aria-disabled="
                    view.nextMonth === null || loading ? 'true' : undefined
                "
                :title="
                    view.nextMonth === null
                        ? 'This is the current month'
                        : undefined
                "
                data-testid="month-next"
                @click="goTo(view.nextMonth)"
            >
                <span
                    >Next<span class="max-sm:sr-only"> month</span
                    ><span v-if="view.nextMonth === null" class="sr-only"
                        >, this is the current month</span
                    ></span
                >
                <ChevronRight class="size-4" aria-hidden="true" />
            </button>
        </nav>

        <ErrorState
            v-if="failed"
            :title="`Could not load ${titleOf(lastTried)}`"
            :message="`Still showing ${monthTitle}. ${failedBy === 'network' ? 'Check the connection, then try again.' : 'Try again in a moment.'}`"
            data-testid="month-error"
        >
            <template #action>
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center rounded-md border border-destructive/40 px-3 text-sm font-medium hover:bg-destructive/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    data-testid="month-retry"
                    @click="goTo(lastTried)"
                >
                    Try again
                </button>
            </template>
        </ErrorState>

        <EmptyState
            v-if="view.beforeStart"
            :title="
                view.startsOn
                    ? `Cash closing started on ${plainDate(view.startsOn)}.`
                    : 'No cash counts at this branch yet.'
            "
            :description="
                view.startsOn
                    ? `There is nothing to count for ${monthTitle}.`
                    : 'Open the daily view to record the first count.'
            "
            data-testid="month-empty"
        />

        <section
            v-if="!view.beforeStart"
            class="space-y-3"
            aria-labelledby="month-summary-title"
            data-testid="month-summary"
        >
            <h3 id="month-summary-title" class="text-sm font-semibold">
                {{
                    view.isCurrentMonth ? 'So far this month' : 'Month summary'
                }}
            </h3>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border bg-card p-3">
                    <p class="text-xs text-muted-foreground">Not counted</p>
                    <p
                        class="mt-1 text-2xl font-semibold tabular-nums"
                        data-testid="summary-not-counted"
                    >
                        {{ view.summary.notCounted }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3">
                    <p class="text-xs text-muted-foreground">
                        Waiting for approval
                    </p>
                    <p
                        class="mt-1 text-2xl font-semibold tabular-nums"
                        data-testid="summary-waiting"
                    >
                        {{ view.summary.waiting }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3">
                    <p class="text-xs text-muted-foreground">Returned</p>
                    <p
                        class="mt-1 text-2xl font-semibold tabular-nums"
                        data-testid="summary-returned"
                    >
                        {{ view.summary.returned }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3">
                    <p class="text-xs text-muted-foreground">Approved</p>
                    <p
                        class="mt-1 text-2xl font-semibold tabular-nums"
                        data-testid="summary-approved"
                    >
                        {{ view.summary.approved }}
                    </p>
                </div>
            </div>
            <dl
                class="grid grid-cols-3 gap-3 rounded-xl border bg-card p-3 text-sm md:grid-cols-4"
            >
                <div class="hidden self-center md:block">
                    <dt class="text-xs text-muted-foreground">Money</dt>
                    <dd class="text-xs text-muted-foreground">
                        From the counts shown
                    </dd>
                </div>
                <div class="text-right">
                    <dt class="text-xs text-muted-foreground">Counted</dt>
                    <dd
                        class="font-semibold tabular-nums"
                        data-testid="summary-counted"
                    >
                        {{
                            view.summary.countsShown === 0
                                ? '—'
                                : money(view.summary.countedSen)
                        }}
                    </dd>
                </div>
                <div class="text-right">
                    <dt class="text-xs text-muted-foreground">Expected</dt>
                    <dd
                        class="font-semibold tabular-nums"
                        data-testid="summary-expected"
                    >
                        {{
                            view.summary.countsShown === 0
                                ? '—'
                                : money(view.summary.expectedSen)
                        }}
                    </dd>
                </div>
                <div class="text-right">
                    <dt class="text-xs text-muted-foreground">Difference</dt>
                    <dd
                        class="font-semibold tabular-nums"
                        data-testid="summary-difference"
                    >
                        {{
                            view.summary.countsShown === 0
                                ? '—'
                                : signed(view.summary.differenceSen)
                        }}
                    </dd>
                </div>
            </dl>
            <p class="text-xs text-muted-foreground">
                <template v-if="view.summary.countsShown === 0"
                    >No counts this month.
                </template>
                Totals of the counts shown; days not counted add nothing.
                <template v-if="view.isCurrentMonth">
                    Today is still open: its cash is in the money totals but not
                    in the day counts above until the day ends.</template
                >
            </p>
        </section>

        <p
            v-if="!view.beforeStart && !view.hasClosings"
            class="text-sm text-muted-foreground"
            data-testid="month-no-counts"
        >
            No cash counts in {{ monthTitle }}.
        </p>

        <div v-if="!view.beforeStart" class="hidden lg:block">
            <OperationalTable
                :label="`Cash closings, ${monthTitle}`"
                :columns="9"
                :loading="loading"
                min-width="0"
            >
                <template #head>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Counted</th>
                        <th scope="col" class="text-right">Expected</th>
                        <th scope="col" class="text-right">Difference</th>
                        <th scope="col">Counted by</th>
                        <th scope="col">Approved or returned by</th>
                        <th scope="col">Flags</th>
                        <th scope="col">
                            <span class="sr-only">Open day</span>
                        </th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="row in view.rows"
                        :key="row.date"
                        :data-testid="`month-row-${row.date}`"
                    >
                        <th
                            scope="row"
                            class="font-medium whitespace-nowrap"
                            :class="
                                row.flags.length
                                    ? 'border-l-4 border-l-warning'
                                    : 'border-l-4 border-l-transparent'
                            "
                        >
                            {{ shortDate(row.date) }}
                        </th>
                        <td>
                            <StatusBadge
                                :status="row.status"
                                :label="statusInfo[row.status].label"
                                :tone="statusInfo[row.status].tone"
                            />
                            <p
                                v-if="row.status === 'open'"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                Day not ended.
                            </p>
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{
                                row.countedCashSen === null
                                    ? '—'
                                    : money(row.countedCashSen)
                            }}
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{
                                row.expectedCashSen === null
                                    ? '—'
                                    : money(row.expectedCashSen)
                            }}
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{
                                row.differenceSen === null
                                    ? '—'
                                    : signed(row.differenceSen)
                            }}
                        </td>
                        <td class="xl:min-w-36">{{ row.countedBy ?? '—' }}</td>
                        <td class="xl:min-w-36">{{ row.decidedBy ?? '—' }}</td>
                        <td class="w-40 xl:w-auto xl:max-w-56">
                            <p
                                v-if="changed(row)"
                                class="flex items-start gap-1 text-xs font-medium text-warning-strong"
                            >
                                <TriangleAlert
                                    class="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>{{ changedText }}</span>
                            </p>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <Link
                                :href="dayHref(row)"
                                class="font-medium text-brand-strong underline-offset-4 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :aria-label="`Open day, ${longDate(row.date)}`"
                                >Open day</Link
                            >
                        </td>
                    </tr>
                </template>
            </OperationalTable>
        </div>

        <ul
            v-if="!view.beforeStart"
            class="space-y-2 lg:hidden"
            :aria-busy="loading"
            :aria-label="`Cash closings, ${monthTitle}`"
        >
            <li v-for="row in view.rows" :key="row.date">
                <Link
                    :href="dayHref(row)"
                    class="block min-h-11 rounded-xl border bg-card p-3 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        row.flags.length
                            ? 'border-l-4 border-l-warning pl-[9px]'
                            : ''
                    "
                    :data-testid="`month-card-${row.date}`"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium">{{
                            shortDate(row.date)
                        }}</span>
                        <StatusBadge
                            :status="row.status"
                            :label="statusInfo[row.status].label"
                            :tone="statusInfo[row.status].tone"
                        />
                    </div>
                    <p
                        v-if="row.status === 'open'"
                        class="mt-0.5 text-right text-xs text-muted-foreground"
                    >
                        Day not ended.
                    </p>
                    <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Counted
                            </dt>
                            <dd class="tabular-nums">
                                {{
                                    row.countedCashSen === null
                                        ? '—'
                                        : money(row.countedCashSen)
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Expected
                            </dt>
                            <dd class="tabular-nums">
                                {{
                                    row.expectedCashSen === null
                                        ? '—'
                                        : money(row.expectedCashSen)
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Difference
                            </dt>
                            <dd class="tabular-nums">
                                {{
                                    row.differenceSen === null
                                        ? '—'
                                        : signed(row.differenceSen)
                                }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="row.countedBy"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Counted by {{ row.countedBy
                        }}<template v-if="decidedText(row)">
                            · {{ decidedText(row) }}</template
                        >
                    </p>
                    <p
                        v-if="changed(row)"
                        class="mt-2 flex items-start gap-1 text-xs font-medium text-warning-strong"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{{ changedText }}</span>
                    </p>
                    <p
                        class="mt-2 text-right text-sm font-medium text-brand-strong"
                    >
                        Open day <span aria-hidden="true">›</span>
                    </p>
                </Link>
            </li>
        </ul>
    </main>
</template>
