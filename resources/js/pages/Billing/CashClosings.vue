<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { myr } from '@/types/billing';

type Closing = {
    publicId: string;
    revision: number;
    expectedReceiptCount: number;
    expectedCashSen: number;
    countedCashSen: number;
    varianceSen: number;
    varianceReason: string | null;
    countedBy: string;
    countedAt: string;
    state: 'waiting' | 'approved' | 'returned' | 'replaced';
    decisionReason: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    canDecide: boolean;
    canApproveNow: boolean;
    countedByYou: boolean;
};

const props = defineProps<{
    cashClosing: {
        branch: string;
        branchId: number;
        businessDate: string;
        today: string;
        expected: { receiptCount: number; cashSen: number };
        refunds: { count: number; sen: number; waiting: number };
        status: 'not_counted' | 'waiting' | 'returned' | 'approved';
        dayEnded: boolean;
        changedSinceApproval: boolean;
        can: { count: boolean; approve: boolean };
        closings: Closing[];
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const day = computed(() => props.cashClosing);
const selectedDate = reactive({ value: props.cashClosing.businessDate });

const form = useForm({
    expected_branch_id: props.cashClosing.branchId,
    business_date: props.cashClosing.businessDate,
    counted_cash: '',
    variance_reason: '',
    idempotency_key: crypto.randomUUID(),
});

const parseSen = (value: string): number | null => {
    const match = /^(-?)(0|[1-9]\d{0,9})(?:\.(\d{1,2}))?$/.exec(
        value.trim().replace('\u2212', '-'),
    );

    if (!match) {
        return null;
    }

    const sen =
        Number(match[2]) * 100 + Number((match[3] ?? '').padEnd(2, '0'));

    return match[1] === '-' ? -sen : sen;
};
// BIL-6: refunds can make a day's cash below zero, which the shared myr() does not format.
const money = (sen: number) => (sen < 0 ? `−${myr(-sen)}` : myr(sen));
const difference = computed(() => {
    const counted = parseSen(form.counted_cash);

    return counted === null ? null : counted - day.value.expected.cashSen;
});
const signed = (sen: number) =>
    sen === 0 ? myr(0) : `${myr(Math.abs(sen))} ${sen < 0 ? 'short' : 'over'}`;

const statusText = computed(
    () =>
        ({
            not_counted: 'Not counted yet',
            waiting: 'Counted, waiting for approval',
            returned: 'Returned, count again',
            approved: 'Closed and approved',
        })[day.value.status],
);
const stateText: Record<Closing['state'], string> = {
    waiting: 'Waiting for approval',
    approved: 'Approved',
    returned: 'Returned',
    replaced: 'Replaced by a newer count',
};

const loadDate = () =>
    router.get(
        '/cash-closings',
        { date: selectedDate.value },
        { preserveScroll: true },
    );

const recordCount = () =>
    form
        .transform((data) => ({
            ...data,
            counted_cash: data.counted_cash.trim(),
            variance_reason: data.variance_reason.trim() || null,
        }))
        .post('/cash-closings', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('counted_cash', 'variance_reason');
                form.idempotency_key = crypto.randomUUID();
            },
        });

const returnReasons = reactive<Record<string, string>>({});
const busy = reactive<Record<string, boolean>>({});
const decide = (closing: Closing, decision: 'approved' | 'returned') => {
    busy[closing.publicId] = true;
    router.post(
        `/cash-closings/${closing.publicId}/${decision}`,
        {
            expected_branch_id: day.value.branchId,
            business_date: day.value.businessDate,
            reason:
                decision === 'returned'
                    ? (returnReasons[closing.publicId] ?? '').trim() || null
                    : null,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                busy[closing.publicId] = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Daily cash closing" />
    <main class="mx-auto w-full max-w-6xl space-y-4 px-4 py-6 md:px-6">
        <PageHeader
            title="Daily cash closing"
            :description="`${day.branch} · Count the day's cash takings, then someone else approves the closing`"
        >
            <template #actions>
                <ActionLink href="/payment-reconciliations" variant="secondary">
                    Terminal Reconciliation
                </ActionLink>
                <ActionLink
                    :href="`/cash-closings/month?month=${day.businessDate.slice(0, 7)}`"
                    variant="secondary"
                >
                    Month view
                </ActionLink>
            </template>
        </PageHeader>

        <section
            class="flex flex-wrap items-end justify-between gap-4 rounded-xl border bg-card p-4"
        >
            <form class="flex items-end gap-2" @submit.prevent="loadDate">
                <label class="grid gap-1 text-sm font-medium" for="cash-date">
                    Business date
                    <Input
                        id="cash-date"
                        v-model="selectedDate.value"
                        type="date"
                        :max="day.today"
                    />
                </label>
                <Button type="submit" variant="outline">Load day</Button>
            </form>
            <p
                class="rounded-full border px-3 py-1 text-sm font-medium"
                :class="
                    day.status === 'approved'
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-100'
                        : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100'
                "
                data-testid="cash-closing-status"
            >
                {{ statusText }}
            </p>
            <InputError class="w-full" :message="errors.business_date" />
        </section>

        <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div
                class="rounded-xl border bg-card p-4"
                data-testid="cash-expected"
            >
                <p class="text-xs text-muted-foreground">
                    Expected cash for {{ day.businessDate }}
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ money(day.expected.cashSen) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ day.expected.receiptCount }} cash receipt(s) counted.
                    Reversed receipts are left out unless their money was
                    refunded. Only payment methods marked Cash are counted.
                </p>
                <p
                    v-if="day.refunds.count"
                    class="mt-1 text-xs text-muted-foreground"
                    data-testid="cash-refunds"
                >
                    Includes {{ day.refunds.count }} cash refund(s) paid out:
                    −{{ myr(day.refunds.sen) }}.
                    <span
                        v-if="day.refunds.waiting"
                        class="font-medium text-amber-700 dark:text-amber-300"
                        >{{ day.refunds.waiting }} still waiting for approval{{
                            day.status === 'approved'
                                ? '.'
                                : '; the day cannot be approved until they are decided.'
                        }}</span
                    >
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    A refund recorded later for one of this day's receipts
                    changes this figure; an approved day then shows "changed
                    after approval".
                </p>
            </div>

            <form
                v-if="day.can.count"
                class="grid gap-3 rounded-xl border bg-card p-4"
                data-testid="cash-count-form"
                @submit.prevent="recordCount"
            >
                <div>
                    <h2 class="font-semibold">Record the cash count</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Take out the opening float first, then count only the
                        day's takings. If more was refunded than taken, enter a
                        minus amount (for example -20.00).
                    </p>
                </div>
                <label class="grid gap-1 text-sm font-medium" for="counted">
                    Counted cash takings (RM)
                    <Input
                        id="counted"
                        v-model="form.counted_cash"
                        inputmode="text"
                        placeholder="0.00"
                        required
                    />
                    <InputError :message="form.errors.counted_cash" />
                </label>
                <p
                    v-if="difference !== null"
                    class="text-sm font-medium"
                    data-testid="cash-difference"
                >
                    Difference:
                    <span
                        :class="
                            difference === 0
                                ? 'text-emerald-700 dark:text-emerald-300'
                                : 'text-amber-700 dark:text-amber-300'
                        "
                        >{{ signed(difference) }}</span
                    >
                </p>
                <label
                    v-if="difference !== null && difference !== 0"
                    class="grid gap-1 text-sm font-medium"
                    for="variance-reason"
                >
                    Reason for the difference
                    <textarea
                        id="variance-reason"
                        v-model="form.variance_reason"
                        maxlength="1000"
                        rows="2"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        required
                    />
                    <span class="text-xs font-normal text-muted-foreground">
                        Do not enter patient names or identifiers.
                    </span>
                    <InputError :message="form.errors.variance_reason" />
                </label>
                <Button
                    type="submit"
                    :disabled="form.processing || difference === null"
                    >Record count</Button
                >
            </form>
            <p
                v-else
                class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
            >
                {{
                    day.status === 'approved'
                        ? 'This day is closed and approved. It cannot be counted again.'
                        : 'You can view this day but not record a count.'
                }}
            </p>
        </section>

        <InputError
            :message="
                errors.cash_closing ??
                errors.reason ??
                errors.expected_branch_id ??
                errors.idempotency_key
            "
        />
        <p
            v-if="day.changedSinceApproval"
            class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
            data-testid="cash-changed-since-approval"
        >
            Cash receipts for this day changed after the closing was approved
            (for example, a receipt was reversed or a refund was recorded). The
            approved figures are kept; check Terminal Reconciliation for the
            change.
        </p>

        <section class="space-y-3" aria-label="Counts for this day">
            <h2 class="font-semibold">Counts for this day</h2>
            <p
                v-if="day.closings.length === 0"
                class="text-sm text-muted-foreground"
            >
                No count recorded for this day.
            </p>
            <article
                v-for="closing in day.closings"
                :key="closing.publicId"
                class="rounded-xl border bg-card p-4"
                data-testid="cash-closing-row"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium">
                            Count {{ closing.revision }} ·
                            {{ stateText[closing.state] }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Counted by {{ closing.countedBy }},
                            {{ closing.countedAt }}
                        </p>
                    </div>
                    <dl
                        class="grid grid-cols-3 gap-x-4 text-right text-sm tabular-nums"
                    >
                        <dt class="text-xs text-muted-foreground">Expected</dt>
                        <dt class="text-xs text-muted-foreground">Counted</dt>
                        <dt class="text-xs text-muted-foreground">
                            Difference
                        </dt>
                        <dd>{{ money(closing.expectedCashSen) }}</dd>
                        <dd>{{ money(closing.countedCashSen) }}</dd>
                        <dd
                            :class="
                                closing.varianceSen === 0
                                    ? ''
                                    : 'font-semibold text-amber-700 dark:text-amber-300'
                            "
                        >
                            {{ signed(closing.varianceSen) }}
                        </dd>
                    </dl>
                </div>
                <p v-if="closing.varianceReason" class="mt-2 text-sm">
                    Reason: {{ closing.varianceReason }}
                </p>
                <p
                    v-if="closing.decidedBy"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    {{ closing.state === 'approved' ? 'Approved' : 'Returned' }}
                    by {{ closing.decidedBy }}, {{ closing.decidedAt
                    }}{{
                        closing.decisionReason
                            ? ` · ${closing.decisionReason}`
                            : ''
                    }}
                </p>
                <div
                    v-if="closing.canDecide"
                    class="mt-3 grid gap-2 border-t pt-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end"
                >
                    <label
                        class="grid gap-1 text-sm font-medium"
                        :for="`return-${closing.publicId}`"
                    >
                        Reason to return (only for Return)
                        <Input
                            :id="`return-${closing.publicId}`"
                            v-model="returnReasons[closing.publicId]"
                            maxlength="1000"
                        />
                    </label>
                    <Button
                        variant="outline"
                        :disabled="busy[closing.publicId]"
                        @click="decide(closing, 'returned')"
                        >Return for recount</Button
                    >
                    <Button
                        :disabled="
                            busy[closing.publicId] || !closing.canApproveNow
                        "
                        @click="decide(closing, 'approved')"
                        >Approve closing</Button
                    >
                    <p
                        v-if="!closing.canApproveNow"
                        class="text-xs text-muted-foreground sm:col-span-3"
                    >
                        Approval opens after this day ends (from midnight,
                        branch time), so any cash taken later today is included.
                        You can return it for a recount now.
                    </p>
                </div>
                <p
                    v-else-if="
                        day.can.approve &&
                        closing.state === 'waiting' &&
                        closing.countedByYou
                    "
                    class="mt-3 border-t pt-3 text-xs text-muted-foreground"
                >
                    You counted this cash. Someone else must approve or return
                    it.
                </p>
            </article>
        </section>
    </main>
</template>
