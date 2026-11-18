<script setup lang="ts">
// HR-15g /hr/locum-pay (owner, 18 Nov 2026): a locum's month statement from approved shifts and confirmed rates. HR
// enters rates, marks shifts not worked and adds corrections; Finance confirms rates (never one they entered) and
// records that the clinic paid. KPOne pays nothing.
import { Head } from '@inertiajs/vue3';
import StatementView from '@/components/hr/locum-pay/StatementView.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';
import { useLocumPayPage } from '@/composables/hr/useLocumPayPage';
import type { LocumPayPageProps } from '@/composables/hr/useLocumPayPage';

const props = defineProps<LocumPayPageProps>();

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

const anyOpen = () =>
    rateForm.open ||
    rejectForm.open ||
    notWorked.open ||
    correction.open ||
    paid.open;
</script>

<template>
    <Head title="Locum pay" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4 lg:p-6">
        <PageHeader
            eyebrow="KPOne HR"
            title="Locum pay"
            :description="
                locumPay.tab === 'mine'
                    ? 'What your approved shifts add up to so far. The clinic pays you outside KPOne.'
                    : 'Monthly statements from approved shifts and confirmed rates. Payment is made outside KPOne and recorded here.'
            "
        />
        <SyntheticNote />
        <ErrorState v-if="failure && !anyOpen()" :message="failure" />

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="locumPay.tab"
            :tabs="tabs"
            label="Locum pay sections"
            class="min-w-0 max-lg:[&>button]:h-11"
            @update:model-value="selectTab"
        />

        <!-- Month switcher (statements and My pay) -->
        <nav
            v-if="locumPay.tab !== 'rates'"
            aria-label="Month"
            class="flex items-center gap-2"
            data-test="locum-month"
        >
            <Button
                variant="outline"
                class="max-lg:min-h-11"
                :aria-label="`Previous month`"
                @click="showMonth(locumPay.previousMonth)"
                >Previous</Button
            >
            <h2 class="min-w-36 text-center font-semibold">
                {{ locumPay.monthLabel }}
            </h2>
            <Button
                variant="outline"
                class="max-lg:min-h-11"
                :disabled="!locumPay.nextMonth"
                aria-label="Next month"
                @click="showMonth(locumPay.nextMonth)"
                >Next</Button
            >
        </nav>

        <!-- A locum: their own statement -->
        <template v-if="locumPay.tab === 'mine'">
            <EmptyState
                v-if="!locumPay.mine"
                title="Your sign-in is not linked to a locum file yet."
                description="Ask HR."
            />
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
                <StatementView :statement="locumPay.mine.statement" own />
            </template>
        </template>

        <!-- Statements: every locum for the month -->
        <template v-else-if="locumPay.tab === 'statements'">
            <EmptyState
                v-if="locumPay.locums.length === 0"
                title="No locums with a sign-in or a rate."
                description="Locums appear here once HR links their sign-in or enters a rate."
            />
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
                            {{ row.shifts === 1 ? 'shift' : 'shifts' }} ·
                            <span class="tabular-nums">{{
                                row.totalLabel
                            }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            :status="row.status"
                            :label="row.statusLabel"
                            :tone="statusTone(row.status)"
                        />
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Open the statement for ${row.name}`"
                            data-test="locum-open"
                            @click="openLocum(row.id)"
                            >Open statement</Button
                        >
                    </div>
                </li>
            </ul>
        </template>

        <!-- Rates -->
        <template v-else>
            <EmptyState
                v-if="locumPay.rates.length === 0"
                title="No locum files yet."
                description="Add a locum from Staff files first."
            />
            <ul v-else class="flex flex-col gap-3" data-test="locum-rates">
                <li
                    v-for="row in locumPay.rates"
                    :key="row.workerId"
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                >
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    row.current
                                        ? `${row.current.amountLabel}, from ${row.current.fromLabel}`
                                        : 'No confirmed rate'
                                }}
                            </p>
                        </div>
                        <Button
                            v-if="locumPay.canManage && !row.waiting"
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Enter a rate for ${row.name}`"
                            data-test="locum-rate-new"
                            @click="startRate(row)"
                            >Enter a rate</Button
                        >
                    </div>
                    <div
                        v-if="row.waiting"
                        class="flex flex-col gap-2 rounded-lg border border-dashed p-3"
                        data-test="locum-rate-waiting"
                    >
                        <p class="text-sm">
                            <StatusBadge
                                :status="row.waiting.status"
                                :label="row.waiting.statusLabel"
                                tone="warning"
                            />
                            <span class="ml-2 font-medium">{{
                                row.waiting.amountLabel
                            }}</span>
                            from {{ row.waiting.fromLabel }}, entered by
                            {{ row.waiting.enteredBy }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="locumPay.canFinance"
                                class="max-lg:min-h-11"
                                :disabled="
                                    !row.waiting.canDecide ||
                                    busy.id === row.waiting.id
                                "
                                :aria-label="`Confirm the rate for ${row.name}`"
                                data-test="locum-rate-confirm"
                                @click="startConfirm(row.waiting, row.name)"
                                >Confirm rate</Button
                            >
                            <Button
                                v-if="locumPay.canFinance"
                                variant="outline"
                                class="max-lg:min-h-11"
                                :disabled="!row.waiting.canDecide"
                                :aria-label="`Do not confirm the rate for ${row.name}`"
                                @click="startReject(row.waiting)"
                                >Do not confirm</Button
                            >
                            <Button
                                v-if="row.waiting.canWithdraw"
                                variant="outline"
                                class="max-lg:min-h-11"
                                :disabled="busy.id === row.waiting.id"
                                :aria-label="`Withdraw the rate for ${row.name}`"
                                @click="decideRate(row.waiting, 'withdraw')"
                                >Withdraw</Button
                            >
                        </div>
                        <p
                            v-if="locumPay.canFinance && row.waiting.ownEntry"
                            class="text-xs text-muted-foreground"
                        >
                            You entered this rate, so someone else confirms it.
                        </p>
                    </div>
                    <details v-if="row.history.length > 0" class="text-sm">
                        <summary class="cursor-pointer text-muted-foreground">
                            Earlier rates
                        </summary>
                        <ul class="mt-2 flex flex-col gap-1">
                            <li
                                v-for="h in row.history"
                                :key="h.id"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <StatusBadge
                                    :status="h.status"
                                    :label="h.statusLabel"
                                    :tone="rateTone(h.status)"
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
                        <ErrorState
                            v-if="failure && !anyOpen()"
                            :message="failure"
                        />
                        <ul
                            v-if="locumPay.statement.problems.length > 0"
                            class="flex flex-col gap-1 rounded-lg border border-warning/25 bg-warning/10 p-3 text-sm text-warning-strong"
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
                                <Button
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    :disabled="busy.id === mark.id"
                                    :aria-label="`Remove the old not-worked mark of ${mark.dateLabel}`"
                                    @click="removeMark(mark)"
                                    >Remove old mark</Button
                                >
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
                            <Button
                                v-if="locumPay.statement.canEdit"
                                variant="outline"
                                class="max-lg:min-h-11"
                                data-test="locum-correction-new"
                                @click="startCorrection"
                                >Add a correction</Button
                            >
                            <Button
                                v-if="locumPay.canFinance"
                                class="max-lg:min-h-11"
                                :disabled="!locumPay.statement.canMarkPaid"
                                data-test="locum-mark-paid"
                                @click="startPaid"
                                >Record payment</Button
                            >
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
        <OperationalConfirmDialog
            :open="rateForm.open"
            :title="`Enter a rate for ${rateForm.name}`"
            description="It counts once a Finance Officer or the Director who did not enter it confirms it. It prices shifts from its start date."
            confirm-label="Enter rate"
            processing-label="Entering…"
            :processing="busy.id === 'rate'"
            :error="failure"
            @update:open="(v: boolean) => (rateForm.open = v)"
            @confirm="saveRate"
        >
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <Label id="locum-rate-kind-label">How it is paid</Label>
                    <OperationalSelect
                        :model-value="rateForm.kind"
                        :options="locumPay.kinds"
                        label="How it is paid"
                        labelledby="locum-rate-kind-label"
                        trigger-class="max-lg:min-h-11"
                        @update:model-value="(v) => (rateForm.kind = String(v))"
                    />
                    <InputError :message="errors.kind" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="locum-rate-amount">{{
                        rateForm.kind === 'hour'
                            ? 'Amount an hour (RM)'
                            : 'Amount a session (RM)'
                    }}</Label>
                    <Input
                        id="locum-rate-amount"
                        v-model="rateForm.amount"
                        inputmode="decimal"
                        class="max-lg:min-h-11"
                        :aria-invalid="errors.amount ? 'true' : undefined"
                    />
                    <InputError :message="errors.amount" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="locum-rate-from">Starts on</Label>
                    <DateField
                        id="locum-rate-from"
                        v-model="rateForm.effective_from"
                        :aria-invalid="
                            errors.effective_from ? 'true' : undefined
                        "
                    />
                    <InputError :message="errors.effective_from" />
                </div>
            </div>
        </OperationalConfirmDialog>

        <!-- Confirm a rate -->
        <OperationalConfirmDialog
            :open="confirmForm.open"
            :title="`Confirm ${confirmForm.rate?.amountLabel ?? ''} for ${confirmForm.name}?`"
            :description="`From ${confirmForm.rate?.fromLabel ?? ''}, entered by ${confirmForm.rate?.enteredBy ?? ''}. You did not enter this rate: the person who enters a rate can never confirm it. Once confirmed, it prices shifts from that date in months not yet paid.`"
            confirm-label="Confirm rate"
            processing-label="Confirming…"
            :processing="busy.id === confirmForm.rate?.id"
            :error="failure"
            @update:open="(v: boolean) => (confirmForm.open = v)"
            @confirm="confirmRate"
        />

        <!-- Remove a correction -->
        <OperationalConfirmDialog
            :open="removing.open"
            :title="`Remove the correction ${removing.item?.reason ?? ''}, ${removing.item?.amountLabel ?? ''}?`"
            description="It no longer counts in this month. The record of it is kept."
            confirm-label="Remove correction"
            processing-label="Removing…"
            destructive
            :processing="busy.id === removing.item?.id"
            :error="failure"
            @update:open="(v: boolean) => (removing.open = v)"
            @confirm="removeCorrection"
        />

        <!-- Do not confirm a rate -->
        <OperationalConfirmDialog
            :open="rejectForm.open"
            title="Do not confirm this rate?"
            description="HR sees the reason and can enter the rate again."
            confirm-label="Do not confirm"
            processing-label="Saving…"
            :processing="busy.id === rejectForm.rate?.id"
            :error="errors.reason ?? failure"
            @update:open="(v: boolean) => (rejectForm.open = v)"
            :confirm-disabled="!rejectForm.reason"
            confirm-disabled-reason="Pick a reason to continue."
            @confirm="reject"
        >
            <div class="flex flex-col gap-2">
                <Label id="locum-reject-reason-label">Reason</Label>
                <OperationalSelect
                    :model-value="rejectForm.reason"
                    :options="locumPay.rejectReasons"
                    label="Reason"
                    labelledby="locum-reject-reason-label"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:min-h-11"
                    @update:model-value="(v) => (rejectForm.reason = String(v))"
                />
            </div>
        </OperationalConfirmDialog>

        <!-- Not worked -->
        <OperationalConfirmDialog
            :open="notWorked.open"
            :title="`Mark ${notWorked.line?.dateLabel ?? ''} not worked?`"
            description="The shift pays nothing this month. You can count it again until the month is paid."
            confirm-label="Mark not worked"
            processing-label="Saving…"
            :processing="busy.id === notWorked.line?.shiftId"
            :error="errors.reason ?? failure"
            @update:open="(v: boolean) => (notWorked.open = v)"
            :confirm-disabled="!notWorked.reason"
            confirm-disabled-reason="Pick a reason to continue."
            @confirm="saveNotWorked"
        >
            <div class="flex flex-col gap-2">
                <Label id="locum-not-worked-label">Reason</Label>
                <OperationalSelect
                    :model-value="notWorked.reason"
                    :options="locumPay.reasons"
                    label="Reason"
                    labelledby="locum-not-worked-label"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:min-h-11"
                    @update:model-value="(v) => (notWorked.reason = String(v))"
                />
            </div>
        </OperationalConfirmDialog>

        <!-- Add a correction -->
        <OperationalConfirmDialog
            :open="correction.open"
            :title="`Add a correction to ${locumPay.monthLabel}`"
            description="A paid month cannot change, so a correction for it goes into this month."
            confirm-label="Add correction"
            processing-label="Adding…"
            :processing="busy.id === 'correction'"
            :error="failure"
            @update:open="(v: boolean) => (correction.open = v)"
            :confirm-disabled="!correction.reason || !correction.amount"
            confirm-disabled-reason="Enter an amount and pick a reason to continue."
            @confirm="saveCorrection"
        >
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <Label id="locum-correction-direction-label"
                        >Adds or takes away</Label
                    >
                    <OperationalSelect
                        :model-value="correction.direction"
                        :options="[
                            { value: 'add', label: 'Adds to the pay' },
                            { value: 'deduct', label: 'Takes from the pay' },
                        ]"
                        label="Adds or takes away"
                        labelledby="locum-correction-direction-label"
                        trigger-class="max-lg:min-h-11"
                        @update:model-value="
                            (v) => (correction.direction = String(v))
                        "
                    />
                    <InputError :message="errors.direction" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="locum-correction-amount">Amount (RM)</Label>
                    <Input
                        id="locum-correction-amount"
                        v-model="correction.amount"
                        inputmode="decimal"
                        class="max-lg:min-h-11"
                        :aria-invalid="errors.amount ? 'true' : undefined"
                    />
                    <InputError :message="errors.amount" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label id="locum-correction-reason-label">Reason</Label>
                    <OperationalSelect
                        :model-value="correction.reason"
                        :options="locumPay.correctionReasons"
                        label="Reason"
                        labelledby="locum-correction-reason-label"
                        placeholder="Choose a reason"
                        trigger-class="max-lg:min-h-11"
                        @update:model-value="
                            (v) => (correction.reason = String(v))
                        "
                    />
                    <InputError :message="errors.reason" />
                </div>
                <div
                    v-if="correction.reason === 'earlier_month'"
                    class="flex flex-col gap-2"
                >
                    <Label for="locum-correction-for">For the month</Label>
                    <Input
                        id="locum-correction-for"
                        v-model="correction.for_month"
                        type="month"
                        :max="locumPay.previousMonth"
                        class="max-lg:min-h-11"
                        :aria-invalid="errors.for_month ? 'true' : undefined"
                    />
                    <InputError :message="errors.for_month" />
                </div>
            </div>
        </OperationalConfirmDialog>

        <!-- Record payment -->
        <OperationalConfirmDialog
            :open="paid.open"
            :title="`Record ${locumPay.statement?.totalLabel ?? ''} as paid?`"
            description="Record this only after the clinic has paid the locum outside KPOne. The month is then locked and cannot be undone."
            confirm-label="Record as paid"
            processing-label="Recording…"
            :processing="busy.id === 'paid'"
            :error="failure"
            @update:open="(v: boolean) => (paid.open = v)"
            @confirm="savePaid"
        >
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <Label for="locum-paid-on">Paid on</Label>
                    <DateField
                        id="locum-paid-on"
                        v-model="paid.paid_on"
                        :max="locumPay.today"
                        :aria-invalid="errors.paid_on ? 'true' : undefined"
                    />
                    <InputError :message="errors.paid_on" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="locum-paid-reference"
                        >Payment reference (optional)</Label
                    >
                    <Input
                        id="locum-paid-reference"
                        v-model="paid.reference"
                        maxlength="40"
                        class="max-lg:min-h-11"
                        aria-describedby="locum-paid-reference-help"
                        :aria-invalid="errors.reference ? 'true' : undefined"
                    />
                    <p
                        id="locum-paid-reference-help"
                        class="text-xs text-muted-foreground"
                    >
                        Shown afterwards with only its last 4 characters.
                    </p>
                    <InputError :message="errors.reference" />
                </div>
            </div>
        </OperationalConfirmDialog>
    </div>
</template>
