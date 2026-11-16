<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';

/**
 * Version 2 Controlled register for one medicine (inventory V2 page 8b). Everything from `type Line` to `formError` is a
 * verbatim copy of Classic, and so is the STK-40b reason block (a test compares them). Only the layout and plain sentences
 * are new. As in Classic, a checker counts blind, the count is always recorded, and the reason for a difference is added
 * afterwards (here in a sheet). The check stays inline: it is the page's main task. DRAFT pending pharmacist review.
 */
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

// ---- V2 only below this line ----
// STK-40: the count less the register in words: "no difference", "difference +6 units", "difference \u22121.5 units" (Daniel).
const difference = (value: string | null) =>
    value === null
        ? '—'
        : value === '0'
          ? 'no difference'
          : value.startsWith('-')
            ? `difference \u2212${quantityWithUnit(value.slice(1), unit.value)}`
            : `difference +${quantityWithUnit(value, unit.value)}`;
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Controlled register', href: '/inventory/controlled-register' },
    { title: props.register.sku.medicine },
]);
/** A code never breaks across lines. */
const unbroken = (code: string) => code.replaceAll('-', '‑');
const unit = computed(() => props.register.sku.stockUnit);
const words = (value: string | null) =>
    value === null || value === '' ? '—' : quantityWithUnit(value, unit.value);
const reasonOptions = computed(() =>
    Object.entries(reasons).map(([value, label]) => ({ value, label })),
);
/** Why Record check is off, in words; null when it can be pressed. */
const blocked = computed<string | null>(() => {
    if (form.counted.trim() === '') {
        return 'Enter the counted quantity to continue.';
    }

    if (milli(form.counted) === null) {
        return 'Enter a number, with up to 3 decimal places.';
    }

    return null;
});
const dirty = computed(() =>
    Boolean(form.counted.trim() || reasonForm.reason || reasonForm.note.trim()),
);
// STK-40b: "Add the reason" opens a sheet for the checker's own differing check.
const reasonOpen = ref(false);
const reasonBlocked = computed<string | null>(() =>
    reasonForm.reason === ''
        ? 'Pick a reason for the difference to continue.'
        : reasonForm.reason === 'other' && reasonForm.note.trim() === ''
          ? 'Add a note to explain why.'
          : null,
);
// The sheet closes once the reason is in (the check no longer needs one).
watch(ownReasonNeeded, (next) => {
    if (next === null) {
        reasonOpen.value = false;
    }
});
/** "Other" needs a note: bring the box into view and put the cursor in it (Daniel, page 6). */
const pickReason = (value: string | null) => {
    reasonForm.reason = value ?? '';

    if (reasonForm.reason === 'other') {
        void nextTick(() => {
            const note = document.getElementById('cd-note');
            note?.scrollIntoView({ block: 'nearest' });
            note?.focus();
        });
    }
};
// Day-first date boxes (Daniel, page 8): an unreadable date sends nothing, so Show waits until both are usable.
const fromInvalid = ref(false);
const toInvalid = ref(false);
const rangeError = computed(() =>
    from.value && to.value && from.value > to.value
        ? 'From must be on or before To.'
        : undefined,
);
const rangeReady = computed(
    () =>
        !fromInvalid.value &&
        !toInvalid.value &&
        rangeError.value === undefined,
);
// A polite line once a new check arrives after recording one.
const recorded = ref<number | null>(null);
watch(
    () => props.register.newestCheckSequence,
    (next, before) => {
        if (next > before) {
            recorded.value = next;
        }
    },
);
</script>

<template>
    <Head :title="`Controlled register: ${register.sku.medicine}`" />
    <PageFrame>
        <Link
            href="/inventory/controlled-register"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Controlled register
        </Link>
        <V2LeaveGuard :dirty="dirty" />
        <V2WorkLayout
            :title="register.sku.medicine"
            :description="
                register.blind
                    ? `${register.sku.skuCode} · ${register.branchName} · on hand shown after your count`
                    : `${register.sku.skuCode} · ${register.branchName} · on hand now ${quantityWithUnit(register.balanceNow, register.sku.stockUnit)}`
            "
        >
            <template #main>
                <p
                    class="flex items-start gap-2 rounded-[14px] border border-warning bg-warning/10 p-3 text-sm font-medium text-warning-strong"
                    data-testid="cd-draft-banner"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span
                        >Draft register — pending pharmacist review. Not a
                        statutory register until a pharmacist approves its
                        format.</span
                    >
                </p>

                <!-- Top-aligned, so an error under To hangs below and the two boxes stay level (Daniel). -->
                <form
                    class="flex min-w-0 flex-wrap items-start gap-2"
                    aria-label="Register dates"
                    @submit.prevent="visit(1)"
                >
                    <div class="min-w-0 flex-1 basis-56">
                        <V2DateField
                            v-model="from"
                            label="From"
                            subject="From"
                            @update:invalid="(value) => (fromInvalid = value)"
                        />
                    </div>
                    <div class="min-w-0 flex-1 basis-56">
                        <V2DateField
                            v-model="to"
                            label="To"
                            subject="To"
                            :error="rangeError"
                            @update:invalid="(value) => (toInvalid = value)"
                        />
                    </div>
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <!-- An invisible label line, so the buttons sit level with the date boxes. -->
                        <span
                            aria-hidden="true"
                            class="invisible hidden text-sm font-medium sm:block"
                            >Show</span
                        >
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="submit"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9"
                                :disabled="!rangeReady"
                            >
                                Show
                            </button>
                            <p
                                v-if="register.blind"
                                class="flex min-h-11 items-center text-sm text-muted-foreground lg:min-h-9"
                                data-testid="cd-print-after"
                            >
                                Print the register after your count.
                            </p>
                            <a
                                v-else
                                :href="printHref"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                data-testid="cd-print"
                                >Print register</a
                            >
                        </div>
                    </div>
                </form>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="cd-movements"
                >
                    <h2
                        id="cd-movements"
                        class="v2-section-title text-lg font-semibold"
                    >
                        Movements ({{ register.lines.total }})
                    </h2>
                    <p
                        v-if="register.blind"
                        class="rounded-[14px] border bg-muted/40 p-3 text-sm"
                        data-testid="cd-blind-note"
                    >
                        Quantities are hidden until you record your count, so
                        your check does not depend on the register.
                        <span class="block text-xs text-muted-foreground">{{
                            register.filters.label
                        }}</span>
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        Balance at the start:
                        <span
                            class="font-medium text-foreground tabular-nums"
                            >{{ words(register.opening) }}</span
                        >
                        · at the end:
                        <span
                            class="font-medium text-foreground tabular-nums"
                            >{{ words(register.closing) }}</span
                        >
                        <span class="block text-xs">{{
                            register.filters.label
                        }}</span>
                    </p>
                    <V2Table
                        label="Movements with a running balance"
                        :columns="register.blind ? 6 : 9"
                        cards="xl"
                        min-width="1040px"
                        :empty="register.lines.rows.length === 0"
                        empty-message="No movements in these dates."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Date and time</th>
                                <th scope="col">What happened</th>
                                <th
                                    v-if="!register.blind"
                                    scope="col"
                                    class="text-right"
                                >
                                    In
                                </th>
                                <th
                                    v-if="!register.blind"
                                    scope="col"
                                    class="text-right"
                                >
                                    Out
                                </th>
                                <th
                                    v-if="!register.blind"
                                    scope="col"
                                    class="text-right"
                                >
                                    Balance
                                </th>
                                <th scope="col">Batch</th>
                                <th scope="col">Location</th>
                                <th scope="col">Reference</th>
                                <th
                                    scope="col"
                                    class="min-w-32 whitespace-nowrap"
                                >
                                    Recorded by
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="line in register.lines.rows"
                                :key="line.id"
                            >
                                <td
                                    data-label="Date and time"
                                    class="text-sm whitespace-nowrap"
                                >
                                    {{ line.when }}
                                </td>
                                <td
                                    data-label="What happened"
                                    data-primary
                                    class="text-sm"
                                >
                                    {{ line.what }}
                                </td>
                                <td
                                    v-if="!register.blind"
                                    data-label="In"
                                    class="text-right text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ words(line.in) }}
                                </td>
                                <td
                                    v-if="!register.blind"
                                    data-label="Out"
                                    class="text-right text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ words(line.out) }}
                                </td>
                                <td
                                    v-if="!register.blind"
                                    data-label="Balance"
                                    class="text-right text-sm font-medium whitespace-nowrap tabular-nums"
                                >
                                    {{ words(line.balance) }}
                                </td>
                                <td
                                    data-label="Batch"
                                    class="font-mono text-xs [overflow-wrap:anywhere]"
                                >
                                    {{
                                        line.batch ? unbroken(line.batch) : '—'
                                    }}
                                </td>
                                <td data-label="Location" class="text-sm">
                                    {{ line.location }}
                                </td>
                                <td
                                    data-label="Reference"
                                    class="font-mono text-xs [overflow-wrap:anywhere]"
                                >
                                    {{ unbroken(line.reference) }}
                                </td>
                                <td data-label="Recorded by" class="text-sm">
                                    {{ line.recordedBy }}
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <p
                        v-if="register.lines.total > 0"
                        class="text-sm text-muted-foreground"
                        data-testid="cd-showing"
                    >
                        Showing {{ register.lines.rows.length }} of
                        {{ register.lines.total }}
                        {{
                            register.lines.total === 1
                                ? 'movement'
                                : 'movements'
                        }}
                    </p>
                    <V2Pager
                        v-if="register.lines.lastPage > 1"
                        :current-page="register.lines.page"
                        :last-page="register.lines.lastPage"
                        :total="register.lines.total"
                        :per-page="25"
                        @change="visit"
                    />
                </section>

                <section
                    class="flex min-w-0 flex-col gap-3 rounded-[14px] border p-4"
                    aria-labelledby="cd-check-heading"
                    data-testid="cd-check"
                >
                    <h2 id="cd-check-heading" class="text-lg font-semibold">
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
                            >Count what is physically on hand at this branch.
                            The register's balance appears after you record your
                            count.</template
                        >
                        <template v-else
                            >Count what is physically on hand at this branch and
                            compare it with the register:
                            <span
                                class="font-medium text-foreground tabular-nums"
                                >{{ words(register.balanceNow) }}</span
                            >.</template
                        >
                    </p>
                    <p
                        v-if="recorded !== null"
                        role="status"
                        class="text-success-strong text-sm"
                        data-testid="cd-recorded"
                    >
                        Check {{ recorded }} recorded.
                    </p>
                    <div
                        v-if="ownReasonNeeded"
                        class="flex flex-col gap-3 rounded-[14px] border border-warning bg-warning/10 p-3 text-sm text-warning-strong sm:flex-row sm:items-center"
                        data-testid="cd-own-reason-needed"
                    >
                        <p
                            class="flex min-w-0 flex-1 items-start gap-2 font-medium"
                        >
                            <TriangleAlert
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span
                                >Your count differs from the register. Add the
                                reason. Recording it did not change stock;
                                correct stock with a governed stock count.</span
                            >
                        </p>
                        <button
                            type="button"
                            class="inline-flex h-11 w-full shrink-0 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-fit lg:h-9"
                            data-testid="cd-add-reason-open"
                            @click="reasonOpen = true"
                        >
                            Add the reason
                        </button>
                    </div>
                    <p
                        v-if="register.checkBlockedReason"
                        class="flex items-start gap-2 rounded-[14px] border border-warning bg-warning/10 p-3 text-sm text-warning-strong"
                        data-testid="cd-check-blocked"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{{ register.checkBlockedReason }}</span>
                    </p>
                    <form
                        v-else-if="register.can.check"
                        class="flex max-w-xl min-w-0 flex-col gap-3"
                        novalidate
                        @submit.prevent="submit"
                    >
                        <p
                            v-if="formError"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ formError }}
                        </p>
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            :label="`Quantity counted (${register.sku.stockUnit})`"
                            required
                            :error="errors.counted"
                        >
                            <input
                                :id="id"
                                v-model="form.counted"
                                inputmode="decimal"
                                autocomplete="off"
                                :aria-invalid="invalid"
                                :aria-describedby="describedBy"
                                class="h-11 w-full max-w-xs min-w-0 rounded-[10px] border bg-card px-3 text-right text-base tabular-nums md:text-[15px] lg:h-9"
                                :class="
                                    invalid
                                        ? 'border-destructive'
                                        : 'border-input'
                                "
                            />
                        </V2Field>
                        <div
                            class="flex flex-col gap-2 rounded-[14px] bg-muted/40 p-3"
                        >
                            <p
                                v-if="blocked"
                                class="text-sm text-muted-foreground"
                                data-testid="cd-blocked-reason"
                            >
                                {{ blocked }}
                            </p>
                            <p class="text-sm" data-testid="cd-check-sentence">
                                Records a second-person check of the register. A
                                check never changes stock. If your count
                                differs, you add the reason next.
                            </p>
                            <button
                                type="submit"
                                class="inline-flex h-11 w-full items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 sm:w-fit lg:h-9"
                                :disabled="busy || blocked !== null"
                            >
                                {{ busy ? 'Recording…' : 'Record check' }}
                            </button>
                        </div>
                    </form>
                </section>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="cd-checks-heading"
                >
                    <h2 id="cd-checks-heading" class="text-lg font-semibold">
                        Earlier checks
                    </h2>
                    <p
                        v-if="!register.checks.length"
                        class="text-sm text-muted-foreground"
                    >
                        This register has not been checked yet.
                    </p>
                    <ul
                        v-else
                        class="flex flex-col gap-2"
                        aria-label="Earlier checks"
                    >
                        <li
                            v-for="check in register.checks"
                            :key="check.publicId"
                            class="flex flex-col gap-1 rounded-[14px] border p-3 text-sm"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium"
                                    >Check {{ check.sequence }}</span
                                >
                                <V2Chip
                                    class="whitespace-nowrap"
                                    :tone="
                                        check.outcome === 'matches'
                                            ? 'success'
                                            : 'warning'
                                    "
                                    :label="
                                        check.outcome === 'matches'
                                            ? 'Matches'
                                            : check.reasonNeeded
                                              ? 'Difference: reason needed'
                                              : 'Differs'
                                    "
                                />
                                <span class="text-muted-foreground"
                                    >{{ check.checkedOn }} ·
                                    {{ check.checkedBy }}</span
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
                                Register {{ words(check.ledger) }} · counted
                                {{ words(check.counted) }} ·
                                {{ difference(check.difference) }}
                            </p>
                            <p
                                v-if="check.reason"
                                class="text-muted-foreground"
                            >
                                {{ reasons[check.reason] ?? check.reason
                                }}<template v-if="check.note"
                                    >: {{ check.note }}</template
                                >
                            </p>
                            <p
                                v-else-if="
                                    check.reasonNeeded && !check.canAddReason
                                "
                                class="text-muted-foreground"
                                data-testid="cd-reason-waiting"
                            >
                                The person who recorded this check adds the
                                reason.
                            </p>
                        </li>
                    </ul>
                </section>
            </template>
            <template
                v-if="register.can.check && !register.checkBlockedReason"
                #actions
            />
        </V2WorkLayout>

        <V2Dialog
            :open="reasonOpen && ownReasonNeeded !== null"
            title="Add the reason for the difference"
            :description="
                ownReasonNeeded
                    ? `Check ${ownReasonNeeded.sequence}: register ${words(ownReasonNeeded.ledger)}, counted ${words(ownReasonNeeded.counted)}, ${difference(ownReasonNeeded.difference)}.`
                    : undefined
            "
            confirm-label="Add reason"
            :busy="reasonBusy"
            :confirm-disabled="reasonBlocked !== null"
            :confirm-disabled-reason="reasonBlocked ?? undefined"
            @update:open="(value) => (reasonOpen = value)"
            @confirm="ownReasonNeeded && submitReason(ownReasonNeeded)"
        >
            <div class="flex flex-col gap-4 pb-4" data-testid="cd-add-reason">
                <p
                    v-if="reasonFormError"
                    role="alert"
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    {{ reasonFormError }}
                </p>
                <V2OptionGroup
                    :model-value="reasonForm.reason || null"
                    label="Reason"
                    required
                    :options="reasonOptions"
                    :error="reasonErrors.reason"
                    @update:model-value="pickReason"
                />
                <label
                    class="flex flex-col gap-1.5 text-sm font-medium"
                    for="cd-note"
                    >Note{{
                        reasonForm.reason === 'other'
                            ? ' (required)'
                            : ' (optional)'
                    }}
                    <span
                        id="cd-note-hint"
                        class="text-xs font-normal text-muted-foreground"
                        >Do not enter patient or prescriber details.</span
                    >
                    <input
                        id="cd-note"
                        v-model="reasonForm.note"
                        maxlength="200"
                        :aria-invalid="reasonErrors.note ? 'true' : undefined"
                        aria-describedby="cd-note-hint"
                        class="h-11 w-full min-w-0 rounded-[10px] border bg-card px-3 text-base md:text-[15px]"
                        :class="
                            reasonErrors.note
                                ? 'border-destructive'
                                : 'border-input'
                        "
                    />
                    <span
                        v-if="reasonErrors.note"
                        role="alert"
                        class="text-sm font-normal text-destructive"
                        >{{ reasonErrors.note }}</span
                    >
                </label>
            </div>
            <template #footer-note>
                <p class="text-sm" role="status">
                    The reason is added once and cannot be changed. No stock
                    changes.
                </p>
            </template>
        </V2Dialog>
    </PageFrame>
</template>
