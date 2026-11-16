<script setup lang="ts">
// Version 2 Overtime tab (Director, HR Manager), HR V2 Attendance PR 2. The same state and requests as Classic, through
// useOvertimeTab. Daniel: waiting requests are cards at every width with outline Approve and Reject, the filled action
// lives in the dialog; decided requests by month are a table from 1280 px with cards below and a month stepper.
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Table from '@/components/v2/V2Table.vue';
import { useOvertimeTab } from '@/composables/hr/useOvertimeTab';
import { glueTimes } from '@/lib/hr-attendance-v2';
import type { OvertimeOptions, OvertimeTabData } from '../types';
import { requestTone, weekTone } from '../types';
import { button, numberClass } from './classes';

const props = defineProps<{
    data: OvertimeTabData;
    options: OvertimeOptions;
    error?: string;
}>();

const {
    visitMonth,
    dialog,
    open,
    fewer,
    title,
    confirmLabel,
    needsReason,
    reasonOptions,
    confirm,
    withdrawing,
    withdraw,
    description,
} = useOvertimeTab(props);
</script>

<template>
    <section
        aria-labelledby="v2-overtime-title"
        class="flex flex-col gap-4"
        data-test="v2-overtime-tab"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="v2-overtime-title" class="text-base font-semibold">
                Overtime
            </h2>
            <p
                class="text-sm text-muted-foreground"
                data-test="overtime-summary"
            >
                {{ data.summary }}
            </p>
        </div>
        <p
            v-if="error && !dialog.open"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ error }}
        </p>
        <p v-if="!data.ready" class="text-sm text-muted-foreground">
            Overtime is not set up yet. Save the overtime rules first.
        </p>

        <!-- Waiting: cards at every width (Daniel), outline Approve and Reject on each -->
        <div class="flex flex-col gap-2" data-test="overtime-waiting">
            <h3 class="text-base font-semibold">Waiting for a decision</h3>
            <div
                v-if="data.pending.length === 0"
                class="rounded-[14px] border border-dashed bg-card p-4 text-sm text-muted-foreground"
            >
                <p class="font-medium text-foreground">
                    No overtime is waiting.
                </p>
                <p>
                    Requests appear here when staff ask for overtime. You can
                    ask on someone's behalf from their day on Review.
                </p>
            </div>
            <ul v-else class="flex flex-col gap-3">
                <li
                    v-for="r in data.pending"
                    :key="r.publicId"
                    class="flex flex-col gap-3 rounded-[14px] border bg-card p-4 text-sm"
                    data-test="overtime-waiting-card"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <p class="font-semibold">
                            {{ r.person }} · {{ glueTimes(r.dayLabel) }} ·
                            {{ r.branch }}
                        </p>
                        <p>
                            {{ r.dayTypeLabel }} · asked for
                            <span class="whitespace-nowrap tabular-nums">{{
                                glueTimes(r.requestedLabel)
                            }}</span>
                        </p>
                        <p class="text-muted-foreground">
                            {{ r.reason
                            }}<span v-if="r.note">: {{ r.note }}</span
                            ><span v-if="r.onBehalf">
                                · Asked by {{ r.requestedBy }}</span
                            >
                        </p>
                        <p
                            v-if="r.week"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <span
                                class="whitespace-nowrap text-muted-foreground tabular-nums"
                                >That week: {{ glueTimes(r.week.label) }}</span
                            >
                            <V2Chip
                                v-if="r.week.chip"
                                :tone="weekTone(r.week.level)"
                                :label="r.week.chip"
                                class="text-nowrap!"
                            />
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <template v-if="r.canDecide">
                            <button
                                type="button"
                                :class="button"
                                @click="open(r, 'approve')"
                            >
                                Approve<span class="sr-only">
                                    overtime for {{ r.person }}</span
                                >
                            </button>
                            <button
                                type="button"
                                :class="button"
                                @click="open(r, 'reject')"
                            >
                                Reject<span class="sr-only">
                                    overtime for {{ r.person }}</span
                                >
                            </button>
                        </template>
                        <button
                            v-else-if="r.canWithdraw"
                            type="button"
                            :class="button"
                            :disabled="withdrawing !== ''"
                            @click="withdraw(r)"
                        >
                            Withdraw<span class="sr-only">
                                overtime for {{ r.person }}</span
                            >
                        </button>
                        <p v-else class="text-[13px] text-muted-foreground">
                            Someone else decides this one.
                        </p>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Decided in a month: stepper, a table from 1280 px, cards below -->
        <div class="flex flex-col gap-3" data-test="overtime-decided">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-base font-semibold">
                    Decided in {{ data.monthLabel }}
                </h3>
                <div class="flex flex-nowrap items-center gap-2">
                    <button
                        type="button"
                        :class="[button, 'gap-1.5']"
                        @click="visitMonth(data.prevMonth)"
                    >
                        <span aria-hidden="true">‹</span
                        ><span class="max-sm:sr-only">Previous month</span>
                    </button>
                    <span
                        class="min-w-0 px-1 text-sm font-medium whitespace-nowrap"
                        aria-live="polite"
                        data-test="overtime-month"
                        >{{ data.monthLabel }}</span
                    >
                    <button
                        type="button"
                        :class="[button, 'gap-1.5']"
                        @click="visitMonth(data.nextMonth)"
                    >
                        <span class="max-sm:sr-only">Next month</span
                        ><span aria-hidden="true">›</span>
                    </button>
                </div>
            </div>
            <V2Table
                label="Decided overtime"
                :columns="6"
                cards="xl"
                :empty="data.decided.length === 0"
                empty-message="No decided overtime in this month. Approved, rejected, withdrawn and reversed requests appear here."
            >
                <template #head>
                    <tr>
                        <th scope="col">Person</th>
                        <th scope="col">Day</th>
                        <th scope="col">Day type</th>
                        <th scope="col">Minutes</th>
                        <th scope="col">Status</th>
                        <th scope="col">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="r in data.decided"
                        :key="r.publicId"
                        data-test="overtime-decided-row"
                    >
                        <td data-primary data-label="Person">
                            <span class="font-medium">{{ r.person }}</span>
                        </td>
                        <td data-label="Day">
                            {{ glueTimes(r.dayLabel) }} · {{ r.branch }}
                        </td>
                        <td data-label="Day type">{{ r.dayTypeLabel }}</td>
                        <td
                            data-label="Minutes"
                            class="whitespace-nowrap tabular-nums"
                        >
                            {{ glueTimes(r.approvedLabel ?? r.requestedLabel) }}
                        </td>
                        <td data-label="Status">
                            <div
                                class="flex flex-col items-end gap-1 xl:items-start"
                            >
                                <div
                                    class="flex flex-wrap justify-end gap-1.5 xl:justify-start"
                                >
                                    <V2Chip
                                        :tone="requestTone(r)"
                                        :label="r.statusLabel"
                                        class="text-nowrap!"
                                    />
                                    <V2Chip
                                        v-if="r.fewer"
                                        tone="neutral"
                                        label="Fewer minutes"
                                        class="text-nowrap!"
                                    />
                                    <V2Chip
                                        v-if="r.hoursChanged"
                                        tone="warning"
                                        label="Hours changed after approval"
                                        class="text-nowrap!"
                                    />
                                </div>
                                <p
                                    v-if="r.changedLabel"
                                    class="text-[13px] tabular-nums"
                                >
                                    {{ r.changedLabel }}
                                </p>
                                <p
                                    v-if="r.decisionReason"
                                    class="text-[13px] text-muted-foreground"
                                >
                                    {{ r.decisionReason }}
                                </p>
                            </div>
                        </td>
                        <td
                            data-actions
                            :class="r.canReverse ? '' : 'max-xl:hidden!'"
                        >
                            <div class="flex justify-end">
                                <button
                                    v-if="r.canReverse"
                                    type="button"
                                    :class="button"
                                    @click="open(r, 'reverse')"
                                >
                                    Reverse<span class="sr-only">
                                        overtime for {{ r.person }}</span
                                    >
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </V2Table>
        </div>

        <!-- The decision: Approve starts at the minutes asked; fewer minutes, Reject and Reverse need a reason (chips that
             start empty); Reject and Reverse are red; the filled button stays off until a reason is picked (Daniel). -->
        <V2Dialog
            :open="dialog.open"
            :title="title"
            :description="description"
            :confirm-label="confirmLabel"
            :tone="dialog.mode === 'approve' ? 'default' : 'danger'"
            :busy="dialog.processing"
            :confirm-disabled="needsReason && dialog.reason === ''"
            confirm-disabled-reason="Pick a reason to continue."
            @update:open="dialog.open = $event"
            @confirm="confirm"
        >
            <div class="flex flex-col gap-4" data-test="overtime-decision">
                <V2Field
                    v-if="dialog.mode === 'approve'"
                    v-slot="{ id, describedBy, invalid }"
                    label="Minutes to approve"
                    required
                    :hint="
                        dialog.target
                            ? `Asked for ${dialog.target.minutesRequested} min. Lower it to approve fewer.`
                            : undefined
                    "
                >
                    <input
                        :id="id"
                        v-model="dialog.minutes"
                        inputmode="numeric"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="numberClass"
                    />
                </V2Field>
                <p
                    v-if="fewer"
                    class="text-sm text-muted-foreground"
                    data-test="overtime-fewer-line"
                >
                    Fewer minutes than asked: choose why.
                </p>
                <p
                    v-if="dialog.mode === 'reverse' && dialog.target?.paidIn"
                    class="text-sm"
                    data-test="overtime-paid-in"
                >
                    Paid in the locked {{ dialog.target.paidIn }}. Reversing it
                    does not change that run or its payslip: add a recovery on
                    an adjustment run.
                </p>
                <V2OptionGroup
                    v-if="needsReason"
                    v-model="dialog.reason"
                    :options="reasonOptions"
                    label="Reason"
                    required
                />
                <p
                    v-if="dialog.error"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ dialog.error }}
                </p>
            </div>
        </V2Dialog>
    </section>
</template>
