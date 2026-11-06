<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    CircleSlash,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { PageHeader } from '@/components/ui/page-header';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { myr } from '@/types/billing';

type Status = 'approved' | 'waiting' | 'returned' | 'not_counted' | 'open';
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
        previousMonth: string;
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
        rows: Row[];
    };
}>();

const view = computed(() => props.cashClosingMonth);
const loading = ref(false);
const failed = ref(false);

// BIL-6: refunds can make a day's cash below zero, which the shared myr() does not format (same as the daily page).
const money = (sen: number) => (sen < 0 ? `−${myr(-sen)}` : myr(sen));
const signed = (sen: number) =>
    sen === 0 ? myr(0) : `${myr(Math.abs(sen))} ${sen < 0 ? 'short' : 'over'}`;

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
};
const flagText: Record<Flag, string> = {
    no_count: 'No count',
    not_approved: 'Not approved',
    changed_after_approval:
        'Expected cash changed after approval. The approved figures are shown.',
};

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

                return false;
            },
            onNetworkError: () => {
                failed.value = true;

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
                <ActionLink href="/cash-closings" variant="outline">
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
                class="inline-flex min-h-11 items-center gap-1 rounded-md px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                :aria-disabled="view.nextMonth === null ? 'true' : undefined"
                data-testid="month-next"
                @click="goTo(view.nextMonth)"
            >
                <span>Next<span class="max-sm:sr-only"> month</span></span>
                <ChevronRight class="size-4" aria-hidden="true" />
            </button>
        </nav>

        <ErrorState
            v-if="failed"
            title="Could not load this month"
            message="Try again."
            data-testid="month-error"
        />

        <section
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
                class="grid grid-cols-3 gap-3 rounded-xl border bg-card p-3 text-sm"
            >
                <div class="text-right">
                    <dt class="text-xs text-muted-foreground">Counted</dt>
                    <dd
                        class="font-semibold tabular-nums"
                        data-testid="summary-counted"
                    >
                        {{ money(view.summary.countedSen) }}
                    </dd>
                </div>
                <div class="text-right">
                    <dt class="text-xs text-muted-foreground">Expected</dt>
                    <dd
                        class="font-semibold tabular-nums"
                        data-testid="summary-expected"
                    >
                        {{ money(view.summary.expectedSen) }}
                    </dd>
                </div>
                <div class="text-right">
                    <dt class="text-xs text-muted-foreground">Difference</dt>
                    <dd
                        class="font-semibold tabular-nums"
                        data-testid="summary-difference"
                    >
                        {{ signed(view.summary.differenceSen) }}
                    </dd>
                </div>
            </dl>
            <p class="text-xs text-muted-foreground">
                Totals of the counts shown; days not counted add nothing.
            </p>
        </section>

        <EmptyState
            v-if="!view.hasClosings"
            title="No cash counts this month yet."
            description="Open a day to record a count."
            data-testid="month-empty"
        />

        <div class="hidden md:block">
            <OperationalTable
                :label="`Cash closings, ${monthTitle}`"
                :columns="9"
                :loading="loading"
                min-width="960px"
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
                        :class="
                            row.flags.length
                                ? 'border-l-4 border-l-warning'
                                : ''
                        "
                        :data-testid="`month-row-${row.date}`"
                    >
                        <th scope="row" class="font-medium whitespace-nowrap">
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
                        <td>{{ row.countedBy ?? '—' }}</td>
                        <td>{{ row.decidedBy ?? '—' }}</td>
                        <td class="max-w-56">
                            <ul v-if="row.flags.length" class="space-y-1">
                                <li
                                    v-for="flag in row.flags"
                                    :key="flag"
                                    class="flex items-start gap-1 text-xs font-medium text-warning-strong"
                                >
                                    <CircleSlash
                                        v-if="flag === 'no_count'"
                                        class="mt-0.5 size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <TriangleAlert
                                        v-else
                                        class="mt-0.5 size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span>{{ flagText[flag] }}</span>
                                </li>
                            </ul>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <a
                                :href="dayHref(row)"
                                class="font-medium text-brand-strong underline-offset-4 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :aria-label="`Open day, ${longDate(row.date)}`"
                                >Open day</a
                            >
                        </td>
                    </tr>
                </template>
            </OperationalTable>
        </div>

        <ul
            class="space-y-2 md:hidden"
            :aria-busy="loading"
            :aria-label="`Cash closings, ${monthTitle}`"
        >
            <li v-for="row in view.rows" :key="row.date">
                <a
                    :href="dayHref(row)"
                    class="block min-h-11 rounded-xl border bg-card p-3 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        row.flags.length ? 'border-l-4 border-l-warning' : ''
                    "
                    :aria-label="`Open day, ${longDate(row.date)}`"
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
                    <ul v-if="row.flags.length" class="mt-2 space-y-1">
                        <li
                            v-for="flag in row.flags"
                            :key="flag"
                            class="flex items-start gap-1 text-xs font-medium text-warning-strong"
                        >
                            <CircleSlash
                                v-if="flag === 'no_count'"
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <TriangleAlert
                                v-else
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{{ flagText[flag] }}</span>
                        </li>
                    </ul>
                    <p
                        class="mt-2 text-right text-sm font-medium text-brand-strong"
                    >
                        Open day ›
                    </p>
                </a>
            </li>
        </ul>
    </main>
</template>
