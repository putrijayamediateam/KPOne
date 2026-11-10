<script setup lang="ts">
// HR-3b Overtime tab (Director, HR Manager): requests waiting with outline buttons, the decision inside the dialog
// (the HR-2a pattern), and decided requests for a month.
import { router } from '@inertiajs/vue3';
import { CalendarCheck, CalendarX, Undo2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { MonthField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import type {
    OvertimeOptions,
    OvertimeRequestRow,
    OvertimeTabData,
} from './types';
import { requestTone, weekTone } from './types';

const props = defineProps<{
    data: OvertimeTabData;
    options: OvertimeOptions;
    error?: string;
}>();

const monthQuery = computed(() => ({ overtime_month: props.data.month }));
const visitMonth = (month: string) =>
    router.get(
        '/hr/attendance',
        { tab: 'overtime', month },
        { preserveScroll: true },
    );

// Daniel: two buttons per card. The Approve dialog starts at the minutes asked; lowering them makes it
// "Approve fewer minutes" with a required reason, and the primary label follows the minutes.
type Mode = 'approve' | 'reject' | 'reverse';
const dialog = reactive({
    open: false,
    mode: 'approve' as Mode,
    target: null as OvertimeRequestRow | null,
    minutes: '',
    reason: '',
    key: '',
    error: '',
    processing: false,
});
const open = (row: OvertimeRequestRow, mode: Mode) =>
    Object.assign(dialog, {
        open: true,
        mode,
        target: row,
        minutes: String(row.minutesRequested),
        reason: '',
        key: crypto.randomUUID(),
        error: '',
    });
const minutesValue = computed(() => {
    const n = Number(dialog.minutes);

    return /^\d+$/.test(dialog.minutes.trim()) ? n : null;
});
const fewer = computed(
    () =>
        dialog.mode === 'approve' &&
        !!dialog.target &&
        minutesValue.value !== null &&
        minutesValue.value < dialog.target.minutesRequested,
);
const title = computed(() =>
    dialog.mode === 'reverse'
        ? 'Reverse this approved overtime?'
        : dialog.mode === 'reject'
          ? 'Reject this overtime?'
          : fewer.value
            ? 'Approve fewer minutes?'
            : 'Approve this overtime?',
);
const confirmLabel = computed(() =>
    dialog.mode === 'reverse'
        ? 'Reverse overtime'
        : dialog.mode === 'reject'
          ? 'Reject overtime'
          : `Approve ${minutesValue.value ?? dialog.target?.minutesRequested ?? ''} min`,
);
const needsReason = computed(() => dialog.mode !== 'approve' || fewer.value);
const reasonOptions = computed(() =>
    dialog.mode === 'approve'
        ? props.options.lowerReasons
        : dialog.mode === 'reject'
          ? props.options.rejectReasons
          : props.options.reverseReasons,
);
const confirm = () => {
    const target = dialog.target;

    if (!target) {
        return;
    }

    if (dialog.mode === 'approve' && minutesValue.value === null) {
        dialog.error = `Enter whole minutes from 1 to ${target.minutesRequested}.`;

        return;
    }

    if (needsReason.value && dialog.reason === '') {
        dialog.error = fewer.value
            ? 'Choose why fewer minutes are approved.'
            : 'Choose a reason.';

        return;
    }

    const url =
        dialog.mode === 'reverse'
            ? `/hr/attendance/overtime/${target.publicId}/reverse`
            : `/hr/attendance/overtime/${target.publicId}/${dialog.mode}`;
    dialog.processing = true;
    router.post(
        url,
        {
            ...monthQuery.value,
            expected_lock_version: target.lockVersion,
            minutes: dialog.mode === 'approve' ? dialog.minutes : undefined,
            reason: needsReason.value ? dialog.reason : undefined,
            idempotency_key: dialog.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (dialog.open = false),
            onError: (e) =>
                (dialog.error =
                    e.overtime ??
                    e.attendance ??
                    e.minutes ??
                    e.reason ??
                    'This could not be saved.'),
            onFinish: () => (dialog.processing = false),
        },
    );
};

const withdrawing = ref('');
const withdraw = (row: OvertimeRequestRow) => {
    if (withdrawing.value !== '') {
        return;
    }

    withdrawing.value = row.publicId;
    router.post(
        `/hr/attendance/overtime/${row.publicId}/withdraw`,
        {
            tab: 'overtime',
            ...monthQuery.value,
            expected_lock_version: row.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true, onFinish: () => (withdrawing.value = '') },
    );
};
const description = computed(() => {
    const t = dialog.target;

    return t
        ? `${t.person} · ${t.dayLabel} · ${t.dayTypeLabel} · asked for ${t.requestedLabel}`
        : '';
});
</script>

<template>
    <section
        aria-labelledby="overtime-title"
        class="flex flex-col gap-4"
        data-test="overtime-tab"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="overtime-title" class="text-base font-semibold">
                Overtime
            </h2>
            <p
                class="text-sm text-muted-foreground"
                data-test="overtime-summary"
            >
                {{ data.summary }}
            </p>
        </div>
        <ErrorState v-if="error && !dialog.open" :message="error" />
        <p v-if="!data.ready" class="text-sm text-muted-foreground">
            Overtime is not set up yet. Save the overtime settings first.
        </p>

        <div
            class="flex flex-col gap-2 rounded-xl border bg-card p-4"
            data-test="overtime-waiting"
        >
            <h3 class="text-base font-semibold">Waiting for a decision</h3>
            <EmptyState
                v-if="data.pending.length === 0"
                title="No overtime is waiting."
                description="Requests appear here when staff ask for overtime. You can ask on someone's behalf from their day on Review."
            />
            <ul v-else class="flex flex-col divide-y">
                <li
                    v-for="r in data.pending"
                    :key="r.publicId"
                    class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="font-medium"
                            >{{ r.person }} · {{ r.dayLabel }} ·
                            {{ r.branch }}</span
                        >
                        <span
                            >{{ r.dayTypeLabel }} · asked for
                            <span class="tabular-nums">{{
                                r.requestedLabel
                            }}</span></span
                        >
                        <span class="text-muted-foreground">
                            {{ r.reason
                            }}<span v-if="r.note">: {{ r.note }}</span
                            ><span v-if="r.onBehalf">
                                · Asked by {{ r.requestedBy }}</span
                            >
                        </span>
                        <span v-if="r.week" class="flex flex-wrap gap-1">
                            <span class="text-muted-foreground tabular-nums"
                                >That week: {{ r.week.label }}</span
                            >
                            <StatusBadge
                                v-if="r.week.chip"
                                status="week"
                                :label="r.week.chip"
                                :tone="weekTone(r.week.level)"
                            />
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <template v-if="r.canDecide">
                            <Button
                                variant="outline"
                                class="min-h-11 sm:min-h-8"
                                @click="open(r, 'approve')"
                            >
                                Approve<span class="sr-only">
                                    overtime for {{ r.person }}</span
                                >
                            </Button>
                            <Button
                                variant="outline"
                                class="min-h-11 sm:min-h-8"
                                @click="open(r, 'reject')"
                            >
                                Reject<span class="sr-only">
                                    overtime for {{ r.person }}</span
                                >
                            </Button>
                        </template>
                        <Button
                            v-else-if="r.canWithdraw"
                            variant="outline"
                            class="min-h-11 sm:min-h-8"
                            :disabled="withdrawing !== ''"
                            @click="withdraw(r)"
                        >
                            Withdraw<span class="sr-only">
                                overtime for {{ r.person }}</span
                            >
                        </Button>
                        <span v-else class="text-xs text-muted-foreground"
                            >Someone else decides this one.</span
                        >
                    </div>
                </li>
            </ul>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-base font-semibold">
                Decided in {{ data.monthLabel }}
            </h3>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    aria-label="Previous month"
                    @click="visitMonth(data.prevMonth)"
                    >‹</Button
                >
                <Label for="overtime-month" class="sr-only">Month</Label>
                <MonthField
                    id="overtime-month"
                    :model-value="data.month"
                    @change="(value: string) => visitMonth(value)"
                />
                <Button
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    aria-label="Next month"
                    @click="visitMonth(data.nextMonth)"
                    >›</Button
                >
            </div>
        </div>
        <EmptyState
            v-if="data.decided.length === 0"
            title="No decided overtime in this month."
            description="Approved, rejected, withdrawn and reversed requests appear here."
        />
        <template v-else>
            <div class="hidden lg:block">
                <OperationalTable
                    label="Decided overtime"
                    :columns="6"
                    min-width="960px"
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
                            <td class="font-medium">{{ r.person }}</td>
                            <td>{{ r.dayLabel }} · {{ r.branch }}</td>
                            <td>{{ r.dayTypeLabel }}</td>
                            <td class="tabular-nums">
                                {{ r.approvedLabel ?? r.requestedLabel }}
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    <StatusBadge
                                        :status="r.status"
                                        :label="r.statusLabel"
                                        :tone="requestTone(r)"
                                    />
                                    <StatusBadge
                                        v-if="r.hoursChanged"
                                        status="changed"
                                        label="Hours changed after approval"
                                        tone="warning"
                                    />
                                </div>
                                <p
                                    v-if="r.changedLabel"
                                    class="mt-1 text-xs tabular-nums"
                                >
                                    {{ r.changedLabel }}
                                </p>
                                <p
                                    v-if="r.decisionReason"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{ r.decisionReason }}
                                </p>
                            </td>
                            <td class="text-right">
                                <Button
                                    v-if="r.canReverse"
                                    variant="outline"
                                    size="sm"
                                    @click="open(r, 'reverse')"
                                >
                                    Reverse<span class="sr-only">
                                        overtime for {{ r.person }}</span
                                    >
                                </Button>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>
            <ul class="flex flex-col gap-2 lg:hidden">
                <li
                    v-for="r in data.decided"
                    :key="r.publicId"
                    class="rounded-xl border bg-card p-4 text-sm"
                >
                    <p class="font-semibold">{{ r.person }}</p>
                    <p>{{ r.dayLabel }} · {{ r.branch }}</p>
                    <p>
                        {{ r.dayTypeLabel }} ·
                        <span class="tabular-nums">{{
                            r.approvedLabel ?? r.requestedLabel
                        }}</span>
                    </p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <StatusBadge
                            :status="r.status"
                            :label="r.statusLabel"
                            :tone="requestTone(r)"
                        />
                        <StatusBadge
                            v-if="r.hoursChanged"
                            status="changed"
                            label="Hours changed after approval"
                            tone="warning"
                        />
                    </div>
                    <p v-if="r.changedLabel" class="mt-1 text-xs tabular-nums">
                        {{ r.changedLabel }}
                    </p>
                    <p
                        v-if="r.decisionReason"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ r.decisionReason }}
                    </p>
                    <Button
                        v-if="r.canReverse"
                        variant="outline"
                        class="mt-2 min-h-11 w-full"
                        @click="open(r, 'reverse')"
                    >
                        Reverse<span class="sr-only">
                            overtime for {{ r.person }}</span
                        >
                    </Button>
                </li>
            </ul>
        </template>

        <OperationalConfirmDialog
            v-model:open="dialog.open"
            :title="title"
            :description="description"
            :confirm-label="confirmLabel"
            processing-label="Saving…"
            :processing="dialog.processing"
            :error="dialog.error"
            @confirm="confirm"
        >
            <template #icon>
                <CalendarCheck
                    v-if="dialog.mode === 'approve'"
                    class="size-5"
                />
                <Undo2 v-else-if="dialog.mode === 'reverse'" class="size-5" />
                <CalendarX v-else class="size-5" />
            </template>
            <div
                v-if="dialog.mode === 'approve'"
                class="grid gap-1"
                data-test="overtime-approve-minutes"
            >
                <Label for="overtime-approve-minutes"
                    >Minutes to approve *</Label
                >
                <Input
                    id="overtime-approve-minutes"
                    v-model="dialog.minutes"
                    inputmode="numeric"
                    class="min-h-11 w-40 sm:min-h-9"
                    aria-describedby="overtime-approve-help"
                />
                <p
                    id="overtime-approve-help"
                    class="text-xs text-muted-foreground"
                >
                    Asked for {{ dialog.target?.minutesRequested }} min. Lower
                    it to approve fewer minutes; it is never raised.
                </p>
            </div>
            <div
                v-if="needsReason"
                class="grid gap-2"
                data-test="overtime-reason"
            >
                <Label for="overtime-decision-reason">Reason *</Label>
                <OperationalSelect
                    id="overtime-decision-reason"
                    v-model="dialog.reason"
                    :options="reasonOptions"
                    label="Reason"
                    placeholder="Choose a reason"
                />
            </div>
        </OperationalConfirmDialog>
    </section>
</template>
