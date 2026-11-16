<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, nextTick, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status';

// STK-24: one controlled medicine's register, read from the stock ledger, with its second-person checks.
type Line = {
    id: number;
    when: string;
    what: string;
    in: string | null;
    out: string | null;
    balance: string | null;
    batch: string | null;
    location: string;
    reference: string;
    recordedBy: string;
};
type Check = {
    publicId: string;
    sequence: number;
    outcome: 'matches' | 'differs';
    reason: string | null;
    note: string | null;
    ledger: string | null;
    counted: string | null;
    difference: string | null;
    /** STK-40: refused "differs" tries before this check (only checks recorded before STK-40b can have any). */
    attempts: number;
    /** STK-40b: it differs and its reason has not been added yet; only its checker may add it. */
    reasonNeeded: boolean;
    canAddReason: boolean;
    checkedBy: string;
    checkedOn: string;
};
type Register = {
    expectedBranchId: number;
    branchName: string;
    sku: {
        publicId: string;
        medicine: string;
        skuCode: string;
        stockUnit: string;
    };
    filters: { from: string; to: string; label: string };
    opening: string | null;
    closing: string | null;
    lines: { page: number; lastPage: number; total: number; rows: Line[] };
    balanceNow: string | null;
    blind: boolean;
    controlled: boolean;
    newestMovementId: number;
    newestCheckSequence: number;
    movementsSinceCheck: number;
    checks: Check[];
    can: { check: boolean };
    checkBlockedReason: string | null;
};

const props = defineProps<{ register: Register }>();

const reasons: Record<string, string> = {
    count_to_follow: 'A governed stock count will follow',
    stock_elsewhere: 'Stock is held somewhere else',
    investigate: 'Difference to investigate',
    other: 'Other (add a note)',
};

const from = ref(props.register.filters.from);
const to = ref(props.register.filters.to);
const base = computed(
    () => `/inventory/controlled-register/${props.register.sku.publicId}`,
);
const visit = (page = 1) =>
    router.get(
        base.value,
        { from: from.value, to: to.value, page },
        { preserveScroll: true, preserveState: true },
    );
const printHref = computed(
    () =>
        `${base.value}/print?from=${encodeURIComponent(props.register.filters.from)}&to=${encodeURIComponent(props.register.filters.to)}`,
);

// Exact thousandths from the typed text, so "10" and "10.000" compare equal with no float maths.
const milli = (text: string): number | null => {
    const match = /^(\d{1,9})(?:\.(\d{1,3}))?$/.exec(text.trim());

    return match
        ? Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'))
        : null;
};

// STK-40b (owner, 16 Nov 2026): the count is always recorded, matching or not, so nothing says "differs" before it is
// saved. The reason for a difference is added afterwards by the same checker (below).
const form = reactive({
    counted: '',
    key: crypto.randomUUID(),
});
const errors = ref<Record<string, string>>({});
const busy = ref(false);

const submit = () => {
    errors.value = {};

    if (form.counted.trim() === '') {
        errors.value = { counted: 'Enter the quantity you counted.' };
    } else if (milli(form.counted) === null) {
        errors.value = {
            counted: 'Enter a number, with up to 3 decimal places.',
        };
    }

    if (Object.keys(errors.value).length) {
        void nextTick(() =>
            document
                .querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus(),
        );

        return;
    }

    router.post(
        `${base.value}/checks`,
        {
            expected_branch_id: props.register.expectedBranchId,
            idempotency_key: form.key,
            seen_movement_id: props.register.newestMovementId,
            seen_check_sequence: props.register.newestCheckSequence,
            counted: form.counted.trim(),
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => {
                form.counted = '';
                form.key = crypto.randomUUID();
            },
            onError: (bag) => {
                errors.value = bag as Record<string, string>;

                // A stale page or an earlier key needs a fresh one after the reload.
                if (
                    bag.seen_movement_id ||
                    bag.seen_check_sequence ||
                    bag.idempotency_key
                ) {
                    form.key = crypto.randomUUID();
                }
            },
        },
    );
};
// Errors with no field of their own (a stale page, someone else's check, a branch change) show above the form.
const fieldKeys = ['counted'];
const formError = computed(
    () =>
        Object.entries(errors.value).find(
            ([key]) => !fieldKeys.includes(key),
        )?.[1] ?? null,
);
// STK-40: the count less the register, with a sign and a real minus ("+2", "\u22121.5", "0").
const difference = (value: string | null) =>
    value === null
        ? '—'
        : value === '0'
          ? 'no difference'
          : value.startsWith('-')
            ? `difference \u2212${value.slice(1)}`
            : `difference +${value}`;

// STK-40b: the reason for a check that differs, added once by the person who recorded it (no second person).
const reasonForm = reactive({
    reason: '',
    note: '',
    key: crypto.randomUUID(),
});
const reasonErrors = ref<Record<string, string>>({});
const reasonBusy = ref(false);
const submitReason = (check: Check) => {
    reasonErrors.value = {};

    if (reasonForm.reason === '') {
        reasonErrors.value = { reason: 'Choose a reason.' };
    } else if (reasonForm.reason === 'other' && reasonForm.note.trim() === '') {
        reasonErrors.value = { note: 'Add a note to explain the difference.' };
    }

    if (Object.keys(reasonErrors.value).length) {
        void nextTick(() =>
            document
                .querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus(),
        );

        return;
    }

    router.post(
        `${base.value}/checks/${check.publicId}/reason`,
        {
            expected_branch_id: props.register.expectedBranchId,
            idempotency_key: reasonForm.key,
            reason: reasonForm.reason,
            note: reasonForm.note.trim() || null,
        },
        {
            preserveScroll: true,
            onStart: () => (reasonBusy.value = true),
            onFinish: () => (reasonBusy.value = false),
            onSuccess: () => {
                reasonForm.reason = '';
                reasonForm.note = '';
                reasonForm.key = crypto.randomUUID();
            },
            onError: (bag) => {
                reasonErrors.value = bag as Record<string, string>;

                if (bag.idempotency_key) {
                    reasonForm.key = crypto.randomUUID();
                }
            },
        },
    );
};
const reasonFormError = computed(
    () =>
        Object.entries(reasonErrors.value).find(
            ([key]) => !['reason', 'note'].includes(key),
        )?.[1] ?? null,
);
/** The check whose reason this viewer still has to add, if any (their own latest differing check). */
const ownReasonNeeded = computed(
    () => props.register.checks.find((check) => check.canAddReason) ?? null,
);
/** STK-40b (owner, 16 Nov 2026): an earlier differing check whose reason only its recorder can add; later checks still go ahead. */
const waitingReason = computed(
    () =>
        props.register.checks.find(
            (check) => check.reasonNeeded && !check.canAddReason,
        ) ?? null,
);
</script>

<template>
    <Head :title="`Controlled register: ${register.sku.medicine}`" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory/controlled-register"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" /> Controlled
            register</ActionLink
        >
        <PageHeader
            :title="register.sku.medicine"
            :description="
                register.blind
                    ? `${register.sku.skuCode} · ${register.branchName} · on hand shown after your count`
                    : `${register.sku.skuCode} · ${register.branchName} · on hand now ${register.balanceNow} ${register.sku.stockUnit}`
            "
        />
        <p
            class="rounded-md border border-warning bg-warning/10 p-3 text-sm font-medium text-warning-strong"
            data-testid="cd-draft-banner"
        >
            Draft register — pending pharmacist review. Not a statutory register
            until a pharmacist approves its format.
        </p>

        <form
            class="flex flex-wrap items-end gap-3"
            aria-label="Register dates"
            @submit.prevent="visit(1)"
        >
            <label class="min-w-0 text-sm"
                ><span class="font-medium">From</span
                ><DateField v-model="from" class="mt-1"
            /></label>
            <label class="min-w-0 text-sm"
                ><span class="font-medium">To</span
                ><DateField v-model="to" class="mt-1"
            /></label>
            <Button type="submit" variant="outline" class="min-h-11"
                >Show</Button
            >
            <ActionLink
                v-if="!register.blind"
                :href="printHref"
                variant="outline"
                class="min-h-11"
                data-testid="cd-print"
                >Print register</ActionLink
            >
            <p
                v-else
                class="text-sm text-muted-foreground"
                data-testid="cd-print-after"
            >
                Print the register after your count.
            </p>
        </form>

        <section aria-labelledby="cd-lines-heading" class="space-y-3">
            <h2 id="cd-lines-heading" class="text-xl font-semibold">
                Movements ({{ register.lines.total }})
            </h2>
            <p
                v-if="register.blind"
                class="rounded-md border p-3 text-sm"
                data-testid="cd-blind-note"
            >
                Quantities are hidden until you record your count, so your check
                does not depend on the register.
            </p>
            <p v-else class="text-sm">
                Balance at the start:
                <span class="font-medium tabular-nums">{{
                    register.opening
                }}</span>
                · at the end:
                <span class="font-medium tabular-nums">{{
                    register.closing
                }}</span>
                {{ register.sku.stockUnit }}
            </p>
            <p
                v-if="!register.lines.rows.length"
                class="text-sm text-muted-foreground"
            >
                No movements in these dates.
            </p>
            <template v-else>
                <ul class="space-y-2 lg:hidden" aria-label="Movements">
                    <li
                        v-for="line in register.lines.rows"
                        :key="line.id"
                        class="space-y-1 rounded-md border p-3 text-sm"
                    >
                        <div class="flex flex-wrap justify-between gap-2">
                            <p class="font-medium">{{ line.what }}</p>
                            <p class="text-muted-foreground">{{ line.when }}</p>
                        </div>
                        <p v-if="!register.blind" class="tabular-nums">
                            <span v-if="line.in">In {{ line.in }}</span>
                            <span v-if="line.in && line.out"> · </span>
                            <span v-if="line.out">Out {{ line.out }}</span>
                            · Balance
                            <span class="font-medium">{{ line.balance }}</span>
                        </p>
                        <p class="break-words text-muted-foreground">
                            {{ line.location
                            }}<template v-if="line.batch">
                                · batch {{ line.batch }}</template
                            >
                        </p>
                        <p class="text-muted-foreground">
                            {{ line.reference }} · {{ line.recordedBy }}
                        </p>
                    </li>
                </ul>
                <div class="hidden overflow-x-auto rounded-md border lg:block">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            Movements with a running balance
                        </caption>
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th scope="col" class="p-3 font-medium">
                                    Date and time
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    What happened
                                </th>
                                <th
                                    v-if="!register.blind"
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    In
                                </th>
                                <th
                                    v-if="!register.blind"
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    Out
                                </th>
                                <th
                                    v-if="!register.blind"
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    Balance
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Batch
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Location
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Reference
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Recorded by
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in register.lines.rows"
                                :key="line.id"
                                class="border-t align-top"
                            >
                                <td class="p-3 whitespace-nowrap">
                                    {{ line.when }}
                                </td>
                                <td class="p-3">{{ line.what }}</td>
                                <td
                                    v-if="!register.blind"
                                    class="p-3 text-right tabular-nums"
                                >
                                    {{ line.in ?? '' }}
                                </td>
                                <td
                                    v-if="!register.blind"
                                    class="p-3 text-right tabular-nums"
                                >
                                    {{ line.out ?? '' }}
                                </td>
                                <td
                                    v-if="!register.blind"
                                    class="p-3 text-right font-medium tabular-nums"
                                >
                                    {{ line.balance }}
                                </td>
                                <td class="p-3 whitespace-nowrap">
                                    {{ line.batch ?? '—' }}
                                </td>
                                <td class="p-3">{{ line.location }}</td>
                                <td class="p-3 break-words">
                                    {{ line.reference }}
                                </td>
                                <td class="p-3 break-words">
                                    {{ line.recordedBy }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    class="text-sm text-muted-foreground"
                    data-testid="cd-showing"
                >
                    Showing {{ register.lines.rows.length }} of
                    {{ register.lines.total }}
                    {{ register.lines.total === 1 ? 'movement' : 'movements' }}
                </p>
                <CompactPagination
                    v-if="register.lines.lastPage > 1"
                    :current-page="register.lines.page"
                    :last-page="register.lines.lastPage"
                    :total="register.lines.total"
                    @change="visit"
                />
            </template>
        </section>

        <section
            aria-labelledby="cd-check-heading"
            class="space-y-3 rounded-md border p-4"
            data-testid="cd-check"
        >
            <h2 id="cd-check-heading" class="text-xl font-semibold">
                Check this register
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    register.movementsSinceCheck === 1
                        ? '1 movement'
                        : `${register.movementsSinceCheck} movements`
                }}
                since the last check.
                <template v-if="register.blind"
                    >Count what is physically on hand at this branch. The
                    register's balance appears after you record your
                    count.</template
                >
                <template v-else
                    >Count what is physically on hand at this branch and compare
                    it with the register:
                    <span class="font-medium text-foreground tabular-nums"
                        >{{ register.balanceNow }}
                        {{ register.sku.stockUnit }}</span
                    >.</template
                >
            </p>
            <p
                v-if="ownReasonNeeded"
                class="rounded-md border border-warning bg-warning/10 p-3 text-sm font-medium text-warning-strong"
                data-testid="cd-own-reason-needed"
            >
                Your count differs from the register. Add the reason under
                Earlier checks. Recording it did not change stock; correct stock
                with a governed stock count.
            </p>
            <p
                v-if="register.checkBlockedReason"
                class="rounded-md border border-warning bg-warning/10 p-3 text-sm text-warning-strong"
                data-testid="cd-check-blocked"
            >
                {{ register.checkBlockedReason }}
            </p>
            <form
                v-else-if="register.can.check"
                class="max-w-xl space-y-3"
                novalidate
                @submit.prevent="submit"
            >
                <p
                    v-if="waitingReason"
                    class="rounded-md border border-warning bg-warning/10 p-3 text-sm text-warning-strong"
                    data-testid="cd-earlier-reason-waiting"
                >
                    Check {{ waitingReason.sequence }} differs from the register
                    and still waits for its reason from the person who recorded
                    it. You can still record your check.
                </p>
                <p
                    v-if="formError"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ formError }}
                </p>
                <label class="block text-sm font-medium" for="cd-counted"
                    >Quantity counted ({{ register.sku.stockUnit }})</label
                >
                <Input
                    id="cd-counted"
                    v-model="form.counted"
                    inputmode="decimal"
                    autocomplete="off"
                    class="min-h-11 max-w-xs"
                    :aria-invalid="errors.counted ? 'true' : undefined"
                    :aria-describedby="
                        errors.counted ? 'cd-counted-error' : undefined
                    "
                />
                <InputError id="cd-counted-error" :message="errors.counted" />
                <p class="text-sm text-muted-foreground">
                    Recording your count never changes stock. If it differs from
                    the register, you add the reason next.
                </p>
                <Button
                    type="submit"
                    :variant="ownReasonNeeded ? 'outline' : 'default'"
                    class="min-h-11 w-full lg:min-h-9 lg:w-auto"
                    :disabled="busy"
                    >Record check</Button
                >
            </form>
        </section>

        <section aria-labelledby="cd-checks-heading" class="space-y-3">
            <h2 id="cd-checks-heading" class="text-xl font-semibold">
                Earlier checks
            </h2>
            <p
                v-if="!register.checks.length"
                class="text-sm text-muted-foreground"
            >
                This register has not been checked yet.
            </p>
            <ul v-else class="space-y-2" aria-label="Earlier checks">
                <li
                    v-for="check in register.checks"
                    :key="check.publicId"
                    class="space-y-1 rounded-md border p-3 text-sm"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            :status="check.outcome"
                            :label="
                                check.outcome === 'matches'
                                    ? 'Matches'
                                    : check.reasonNeeded
                                      ? 'Difference: reason needed'
                                      : 'Differs'
                            "
                            :tone="
                                check.outcome === 'matches'
                                    ? 'success'
                                    : 'warning'
                            "
                        />
                        <span
                            >{{ check.checkedOn }} · {{ check.checkedBy }}</span
                        >
                    </div>
                    <!-- STK-40: refused "differs" tries before this check, so guessing is visible to whoever reads it. -->
                    <p
                        v-if="check.attempts > 0"
                        class="text-muted-foreground"
                        data-testid="cd-earlier-attempts"
                    >
                        {{
                            check.attempts === 1
                                ? '1 earlier attempt before this check'
                                : `${check.attempts} earlier attempts before this check`
                        }}
                    </p>
                    <p
                        v-if="check.ledger === null"
                        class="text-muted-foreground"
                    >
                        Quantities shown after your count.
                    </p>
                    <p v-else class="tabular-nums">
                        Register {{ check.ledger }} · counted
                        {{ check.counted }} {{ register.sku.stockUnit }} ·
                        {{ difference(check.difference) }}
                    </p>
                    <p v-if="check.reason" class="text-muted-foreground">
                        {{ reasons[check.reason] ?? check.reason
                        }}<template v-if="check.note"
                            >: {{ check.note }}</template
                        >
                    </p>
                    <form
                        v-if="check.canAddReason"
                        class="max-w-xl space-y-3 border-t pt-3"
                        novalidate
                        data-testid="cd-add-reason"
                        @submit.prevent="submitReason(check)"
                    >
                        <p
                            v-if="reasonFormError"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ reasonFormError }}
                        </p>
                        <label class="block text-sm font-medium" for="cd-reason"
                            >Reason for the difference</label
                        >
                        <select
                            id="cd-reason"
                            v-model="reasonForm.reason"
                            class="min-h-11 w-full rounded-md border bg-background px-3 text-sm text-foreground"
                            :aria-invalid="
                                reasonErrors.reason ? 'true' : undefined
                            "
                            :aria-describedby="
                                reasonErrors.reason
                                    ? 'cd-reason-error'
                                    : undefined
                            "
                        >
                            <option value="" disabled>Choose a reason</option>
                            <option
                                v-for="(label, value) in reasons"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                        <InputError
                            id="cd-reason-error"
                            :message="reasonErrors.reason"
                        />
                        <label class="block text-sm font-medium" for="cd-note"
                            >Note{{
                                reasonForm.reason === 'other'
                                    ? ''
                                    : ' (optional)'
                            }}</label
                        >
                        <p
                            id="cd-note-hint"
                            class="text-sm text-muted-foreground"
                        >
                            Do not enter patient or prescriber details.
                        </p>
                        <Input
                            id="cd-note"
                            v-model="reasonForm.note"
                            maxlength="200"
                            class="min-h-11"
                            :aria-invalid="
                                reasonErrors.note ? 'true' : undefined
                            "
                            :aria-describedby="
                                reasonErrors.note
                                    ? 'cd-note-hint cd-note-error'
                                    : 'cd-note-hint'
                            "
                        />
                        <InputError
                            id="cd-note-error"
                            :message="reasonErrors.note"
                        />
                        <p class="text-sm text-muted-foreground">
                            The reason is added once and cannot be changed.
                        </p>
                        <Button
                            type="submit"
                            class="min-h-11 w-full lg:min-h-9 lg:w-auto"
                            :disabled="reasonBusy"
                            >Add reason</Button
                        >
                    </form>
                    <p
                        v-else-if="check.reasonNeeded"
                        class="text-muted-foreground"
                        data-testid="cd-reason-waiting"
                    >
                        The person who recorded this check adds the reason.
                    </p>
                </li>
            </ul>
        </section>
    </main>
</template>
