<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatementView from '@/components/hr/locum-pay/StatementView.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useLocumPayPage } from '@/composables/hr/useLocumPayPage';
import type { LocumPayPageProps } from '@/composables/hr/useLocumPayPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Locum pay (HR-15g; Classic is `pages/HR/LocumPay.vue`). Same props, routes, lock versions and idempotency
 * keys: every request comes from `useLocumPayPage`. The one filled action on a screen is in its dialog, or "Confirm rate"
 * for Finance and "Record payment" in the statement; everything else is outline. A reason always starts empty.
 */
const props = defineProps<LocumPayPageProps>();
usePageCrumbs(() => [{ title: 'Locum pay' }]);

const {
    errors,
    failure,
    tabs,
    selectTab,
    showMonth,
    openLocum,
    closeLocum,
    statusTone,
    rateTone,
    busy,
    rateForm,
    startRate,
    saveRate,
    decideRate,
    confirmForm,
    startConfirm,
    confirmRate,
    rejectForm,
    startReject,
    reject,
    notWorked,
    startNotWorked,
    saveNotWorked,
    countAgain,
    removeMark,
    correction,
    startCorrection,
    saveCorrection,
    removing,
    startRemoveCorrection,
    removeCorrection,
    paid,
    startPaid,
    savePaid,
} = useLocumPayPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-invalid:border-destructive md:text-[15px] lg:h-9';

const description = computed(() =>
    props.locumPay.tab === 'mine'
        ? 'What your approved shifts add up to so far. The clinic pays you outside KPOne.'
        : 'Monthly statements from approved shifts and confirmed rates. Payment is made outside KPOne and recorded here.',
);
const chip = (tone: string) =>
    (tone === 'accent' ? 'info' : tone) as
        'neutral' | 'info' | 'success' | 'warning' | 'danger';
const anyOpen = computed(
    () =>
        rateForm.open ||
        rejectForm.open ||
        notWorked.open ||
        correction.open ||
        paid.open,
);
const directions = [
    { value: 'add', label: 'Adds to the pay' },
    { value: 'deduct', label: 'Takes from the pay' },
];
</script>

<template>
    <Head title="Locum pay" />
    <PageFrame>
        <V2WorkLayout title="Locum pay" :description="description">
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <p
                        v-if="failure && !anyOpen && !locumPay.statement"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="locumPay.tab"
                        :tabs="tabs"
                        ariaLabel="Locum pay sections"
                        @update:model-value="selectTab"
                    />

                    <nav
                        v-if="locumPay.tab !== 'rates'"
                        aria-label="Month"
                        class="flex items-center gap-2"
                        data-test="locum-month"
                    >
                        <button
                            type="button"
                            :class="button"
                            aria-label="Previous month"
                            @click="showMonth(locumPay.previousMonth)"
                        >
                            Previous
                        </button>
                        <h2 class="min-w-36 text-center font-semibold">
                            {{ locumPay.monthLabel }}
                        </h2>
                        <button
                            type="button"
                            :class="button"
                            :disabled="!locumPay.nextMonth"
                            aria-label="Next month"
                            @click="showMonth(locumPay.nextMonth)"
                        >
                            Next
                        </button>
                    </nav>

                    <!-- A locum: their own statement -->
                    <template v-if="locumPay.tab === 'mine'">
                        <p
                            v-if="!locumPay.mine"
                            class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
                        >
                            Your sign-in is not linked to a locum file yet. Ask
                            HR.
                        </p>
                        <template v-else>
                            <p
                                class="text-sm text-muted-foreground"
                                data-test="locum-my-rate"
                            >
                                {{
                                    locumPay.mine.rate
                                        ? `Your rate: ${locumPay.mine.rate.amountLabel}.`
                                        : 'HR has not confirmed a rate for you yet.'
                                }}
                                Only approved shifts count.
                            </p>
                            <StatementView
                                :statement="locumPay.mine.statement"
                                own
                            />
                        </template>
                    </template>

                    <!-- Statements -->
                    <template v-else-if="locumPay.tab === 'statements'">
                        <p
                            v-if="locumPay.locums.length === 0"
                            class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
                        >
                            No locums with a sign-in or a rate yet.
                        </p>
                        <ul
                            v-else
                            class="divide-y rounded-xl border bg-card"
                            data-test="locum-list"
                        >
                            <li
                                v-for="row in locumPay.locums"
                                :key="row.id"
                                class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <p class="font-medium">{{ row.name }}</p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ row.shifts }}
                                        {{
                                            row.shifts === 1
                                                ? 'shift'
                                                : 'shifts'
                                        }}
                                        ·
                                        <span class="tabular-nums">{{
                                            row.totalLabel
                                        }}</span>
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <V2Chip
                                        :tone="chip(statusTone(row.status))"
                                        :label="row.statusLabel"
                                    />
                                    <button
                                        type="button"
                                        :class="button"
                                        :aria-label="`Open the statement for ${row.name}`"
                                        data-test="locum-open"
                                        @click="openLocum(row.id)"
                                    >
                                        Open statement
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </template>

                    <!-- Rates -->
                    <template v-else>
                        <p
                            v-if="locumPay.rates.length === 0"
                            class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
                        >
                            No locum files yet. Add a locum from Staff files
                            first.
                        </p>
                        <ul
                            v-else
                            class="flex flex-col gap-3"
                            data-test="locum-rates"
                        >
                            <li
                                v-for="row in locumPay.rates"
                                :key="row.workerId"
                                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                            >
                                <div
                                    class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div class="min-w-0">
                                        <p class="font-medium">
                                            {{ row.name }}
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                row.current
                                                    ? `${row.current.amountLabel}, from ${row.current.fromLabel}`
                                                    : 'No confirmed rate'
                                            }}
                                        </p>
                                    </div>
                                    <button
                                        v-if="
                                            locumPay.canManage && !row.waiting
                                        "
                                        type="button"
                                        :class="button"
                                        :aria-label="`Enter a rate for ${row.name}`"
                                        data-test="locum-rate-new"
                                        @click="startRate(row)"
                                    >
                                        Enter a rate
                                    </button>
                                </div>
                                <div
                                    v-if="row.waiting"
                                    class="flex flex-col gap-2 rounded-lg border border-dashed p-3"
                                    data-test="locum-rate-waiting"
                                >
                                    <p class="text-sm">
                                        <V2Chip
                                            tone="warning"
                                            :label="row.waiting.statusLabel"
                                        />
                                        <span class="ml-2 font-medium">{{
                                            row.waiting.amountLabel
                                        }}</span>
                                        from {{ row.waiting.fromLabel }},
                                        entered by {{ row.waiting.enteredBy }}
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-if="locumPay.canFinance"
                                            type="button"
                                            :class="primaryButton"
                                            :disabled="
                                                !row.waiting.canDecide ||
                                                busy.id === row.waiting.id
                                            "
                                            :aria-label="`Confirm the rate for ${row.name}`"
                                            data-test="locum-rate-confirm"
                                            @click="
                                                startConfirm(
                                                    row.waiting,
                                                    row.name,
                                                )
                                            "
                                        >
                                            Confirm rate
                                        </button>
                                        <button
                                            v-if="locumPay.canFinance"
                                            type="button"
                                            :class="button"
                                            :disabled="!row.waiting.canDecide"
                                            :aria-label="`Do not confirm the rate for ${row.name}`"
                                            @click="startReject(row.waiting)"
                                        >
                                            Do not confirm
                                        </button>
                                        <button
                                            v-if="row.waiting.canWithdraw"
                                            type="button"
                                            :class="button"
                                            :disabled="
                                                busy.id === row.waiting.id
                                            "
                                            :aria-label="`Withdraw the rate for ${row.name}`"
                                            @click="
                                                decideRate(
                                                    row.waiting,
                                                    'withdraw',
                                                )
                                            "
                                        >
                                            Withdraw
                                        </button>
                                    </div>
                                    <p
                                        v-if="
                                            locumPay.canFinance &&
                                            row.waiting.ownEntry
                                        "
                                        class="text-[13px] text-muted-foreground"
                                    >
                                        You entered this rate, so someone else
                                        confirms it.
                                    </p>
                                </div>
                                <details
                                    v-if="row.history.length > 0"
                                    class="text-sm"
                                >
                                    <summary
                                        class="cursor-pointer text-muted-foreground"
                                    >
                                        Earlier rates
                                    </summary>
                                    <ul class="mt-2 flex flex-col gap-1">
                                        <li
                                            v-for="h in row.history"
                                            :key="h.id"
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <V2Chip
                                                :tone="chip(rateTone(h.status))"
                                                :label="h.statusLabel"
                                            />
                                            <span
                                                >{{ h.amountLabel }} from
                                                {{ h.fromLabel }}</span
                                            >
                                            <span
                                                v-if="h.rejectReason"
                                                class="text-muted-foreground"
                                                >· {{ h.rejectReason }}</span
                                            >
                                        </li>
                                    </ul>
                                </details>
                            </li>
                        </ul>
                    </template>
                </div>
            </template>
        </V2WorkLayout>

        <!-- One locum's statement -->
        <Sheet
            :open="locumPay.statement !== null"
            @update:open="(v: boolean) => !v && closeLocum()"
        >
            <SheetContent
                side="right"
                class="w-full overflow-y-auto sm:max-w-3xl"
                data-test="locum-statement-sheet"
            >
                <template v-if="locumPay.statement">
                    <SheetHeader>
                        <SheetTitle
                            >{{ locumPay.statement.name }} ·
                            {{ locumPay.monthLabel }} ·
                            {{
                                locumPay.statement.paid
                                    ? 'Paid'
                                    : 'Not paid yet'
                            }}</SheetTitle
                        >
                        <SheetDescription
                            >Approved shifts × the confirmed rate on each
                            shift's date, plus corrections.</SheetDescription
                        >
                    </SheetHeader>
                    <div class="flex flex-col gap-4 px-4 pb-6">
                        <p
                            v-if="failure && !anyOpen"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ failure }}
                        </p>
                        <ul
                            v-if="locumPay.statement.problems.length > 0"
                            class="flex flex-col gap-1 rounded-[10px] border border-warning/25 bg-warning/10 p-3 text-sm text-warning-strong"
                            data-test="locum-statement-problems"
                        >
                            <li
                                v-for="problem in locumPay.statement.problems"
                                :key="problem"
                            >
                                {{ problem }}
                            </li>
                        </ul>
                        <ul
                            v-if="locumPay.statement.staleMarks.length > 0"
                            class="flex flex-col gap-2"
                            data-test="locum-stale-marks"
                        >
                            <li
                                v-for="mark in locumPay.statement.staleMarks"
                                :key="mark.id"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-dashed p-3 text-sm"
                            >
                                <span
                                    >Old mark: {{ mark.dateLabel }} ·
                                    {{ mark.reason }}</span
                                >
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="busy.id === mark.id"
                                    :aria-label="`Remove the old not-worked mark of ${mark.dateLabel}`"
                                    @click="removeMark(mark)"
                                >
                                    Remove old mark
                                </button>
                            </li>
                        </ul>
                        <StatementView
                            :statement="locumPay.statement"
                            :can-edit="locumPay.statement.canEdit"
                            :busy-id="busy.id"
                            @not-worked="startNotWorked"
                            @count-again="countAgain"
                            @remove-correction="startRemoveCorrection"
                        />
                        <div
                            v-if="!locumPay.statement.paid"
                            class="flex flex-wrap gap-2"
                        >
                            <button
                                v-if="locumPay.statement.canEdit"
                                type="button"
                                :class="button"
                                data-test="locum-correction-new"
                                @click="startCorrection"
                            >
                                Add a correction
                            </button>
                            <button
                                v-if="locumPay.canFinance"
                                type="button"
                                :class="primaryButton"
                                :disabled="!locumPay.statement.canMarkPaid"
                                data-test="locum-mark-paid"
                                @click="startPaid"
                            >
                                Record payment
                            </button>
                        </div>
                        <p
                            v-if="
                                locumPay.canFinance &&
                                !locumPay.statement.paid &&
                                !locumPay.monthEnded
                            "
                            class="text-sm text-muted-foreground"
                        >
                            A month can be recorded as paid once it has ended.
                        </p>
                    </div>
                </template>
            </SheetContent>
        </Sheet>

        <!-- Enter a rate -->
        <V2Dialog
            :open="rateForm.open"
            :title="`Enter a rate for ${rateForm.name}`"
            description="It counts once a Finance Officer or the Director who did not enter it confirms it. It prices shifts from its start date."
            :confirm-label="busy.id === 'rate' ? 'Entering…' : 'Enter rate'"
            :busy="busy.id === 'rate'"
            @update:open="(v: boolean) => (rateForm.open = v)"
            @confirm="saveRate"
        >
            <V2OptionGroup
                :model-value="rateForm.kind"
                :options="locumPay.kinds"
                label="How it is paid"
                required
                :error="errors.kind"
                @update:model-value="rateForm.kind = $event ?? 'hour'"
            />
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                :label="
                    rateForm.kind === 'hour'
                        ? 'Amount an hour (RM)'
                        : 'Amount a session (RM)'
                "
                required
                :error="errors.amount"
            >
                <input
                    :id="id"
                    v-model="rateForm.amount"
                    inputmode="decimal"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid || undefined"
                />
            </V2Field>
            <V2DateField
                v-model="rateForm.effective_from"
                label="Starts on"
                required
                :error="errors.effective_from"
            />
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>

        <!-- Confirm a rate -->
        <V2Dialog
            :open="confirmForm.open"
            :title="`Confirm ${confirmForm.rate?.amountLabel ?? ''} for ${confirmForm.name}?`"
            :description="`From ${confirmForm.rate?.fromLabel ?? ''}, entered by ${confirmForm.rate?.enteredBy ?? ''}. You did not enter this rate: the person who enters a rate can never confirm it. Once confirmed, it prices shifts from that date in months not yet paid.`"
            :confirm-label="
                busy.id === confirmForm.rate?.id
                    ? 'Confirming…'
                    : 'Confirm rate'
            "
            :busy="busy.id === confirmForm.rate?.id"
            @update:open="(v: boolean) => (confirmForm.open = v)"
            @confirm="confirmRate"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>

        <!-- Remove a correction -->
        <V2Dialog
            :open="removing.open"
            :title="`Remove the correction ${removing.item?.reason ?? ''}, ${removing.item?.amountLabel ?? ''}?`"
            description="It no longer counts in this month. The record of it is kept."
            tone="danger"
            :confirm-label="
                busy.id === removing.item?.id
                    ? 'Removing…'
                    : 'Remove correction'
            "
            :busy="busy.id === removing.item?.id"
            @update:open="(v: boolean) => (removing.open = v)"
            @confirm="removeCorrection"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>

        <!-- Do not confirm a rate -->
        <V2Dialog
            :open="rejectForm.open"
            title="Do not confirm this rate?"
            description="HR sees the reason and can enter the rate again."
            tone="danger"
            :confirm-label="
                busy.id === rejectForm.rate?.id ? 'Saving…' : 'Do not confirm'
            "
            :busy="busy.id === rejectForm.rate?.id"
            @update:open="(v: boolean) => (rejectForm.open = v)"
            :confirm-disabled="!rejectForm.reason"
            confirm-disabled-reason="Pick a reason to continue."
            @confirm="reject"
        >
            <V2OptionGroup
                :model-value="rejectForm.reason || null"
                :options="locumPay.rejectReasons"
                label="Reason"
                required
                :error="errors.reason"
                @update:model-value="rejectForm.reason = $event ?? ''"
            />
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>

        <!-- Not worked -->
        <V2Dialog
            :open="notWorked.open"
            :title="`Mark ${notWorked.line?.dateLabel ?? ''} not worked?`"
            description="The shift pays nothing this month. You can count it again until the month is paid."
            :confirm-label="
                busy.id === notWorked.line?.shiftId
                    ? 'Saving…'
                    : 'Mark not worked'
            "
            :busy="busy.id === notWorked.line?.shiftId"
            @update:open="(v: boolean) => (notWorked.open = v)"
            :confirm-disabled="!notWorked.reason"
            confirm-disabled-reason="Pick a reason to continue."
            @confirm="saveNotWorked"
        >
            <V2OptionGroup
                :model-value="notWorked.reason || null"
                :options="locumPay.reasons"
                label="Reason"
                required
                :error="errors.reason"
                @update:model-value="notWorked.reason = $event ?? ''"
            />
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>

        <!-- Add a correction -->
        <V2Dialog
            :open="correction.open"
            :title="`Add a correction to ${locumPay.monthLabel}`"
            description="A paid month cannot change, so a correction for it goes into this month."
            :confirm-label="
                busy.id === 'correction' ? 'Adding…' : 'Add correction'
            "
            :busy="busy.id === 'correction'"
            @update:open="(v: boolean) => (correction.open = v)"
            :confirm-disabled="!correction.reason || !correction.amount"
            confirm-disabled-reason="Enter an amount and pick a reason to continue."
            @confirm="saveCorrection"
        >
            <V2OptionGroup
                :model-value="correction.direction"
                :options="directions"
                label="Adds or takes away"
                required
                :error="errors.direction"
                @update:model-value="correction.direction = $event ?? 'add'"
            />
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Amount (RM)"
                required
                :error="errors.amount"
            >
                <input
                    :id="id"
                    v-model="correction.amount"
                    inputmode="decimal"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid || undefined"
                />
            </V2Field>
            <V2OptionGroup
                :model-value="correction.reason || null"
                :options="locumPay.correctionReasons"
                label="Reason"
                required
                :error="errors.reason"
                @update:model-value="correction.reason = $event ?? ''"
            />
            <V2Field
                v-if="correction.reason === 'earlier_month'"
                v-slot="{ id, describedBy, invalid }"
                label="For the month"
                required
                :error="errors.for_month"
            >
                <input
                    :id="id"
                    v-model="correction.for_month"
                    type="month"
                    :max="locumPay.previousMonth"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid || undefined"
                />
            </V2Field>
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>

        <!-- Record payment -->
        <V2Dialog
            :open="paid.open"
            :title="`Record ${locumPay.statement?.totalLabel ?? ''} as paid?`"
            description="Record this only after the clinic has paid the locum outside KPOne. The month is then locked and cannot be undone."
            :confirm-label="
                busy.id === 'paid' ? 'Recording…' : 'Record as paid'
            "
            :busy="busy.id === 'paid'"
            @update:open="(v: boolean) => (paid.open = v)"
            @confirm="savePaid"
        >
            <V2DateField
                v-model="paid.paid_on"
                label="Paid on"
                required
                :max="locumPay.today"
                :error="errors.paid_on"
            />
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Payment reference (optional)"
                hint="Shown afterwards with only its last 4 characters."
                :error="errors.reference"
            >
                <input
                    :id="id"
                    v-model="paid.reference"
                    maxlength="40"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid || undefined"
                />
            </V2Field>
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>
    </PageFrame>
</template>
