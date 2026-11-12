<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status';
import {
    batchesLine,
    refuseReasonLabels,
    returnConditionLabels,
    returnReasonLabels,
} from '@/lib/dispensary-returns';
import type {
    DispensaryReturnLine,
    DispensaryReturnRow,
    DispensaryReturnsPanel,
} from '@/lib/dispensary-returns';
import ReturnCheckPanel from './ReturnCheckPanel.vue';

// DSP-7 step 1 (owner decisions 2026-11-05): the CA or CA Supervisor records medicine the patient brought back.
// Recording moves no stock; a CA Supervisor who did not record it checks it next (v1). The server repeats every check.
type ReturnRow = DispensaryReturnRow;
type ReturnLine = DispensaryReturnLine;
const props = defineProps<{ returns: DispensaryReturnsPanel }>();

const reasonLabels = returnReasonLabels;
const conditionLabels = returnConditionLabels;

const openLine = ref<string | null>(null);
// UI-RESTYLE D2 (Daniel): while a record form or a check panel is open, the page's Continue to Billing button turns
// outline so the panel's own action is the one filled button. This only picks a button style; nothing else changes.
const emit = defineEmits<{ editing: [value: boolean] }>();
watch(
    () =>
        openLine.value !== null ||
        props.returns.lines.some((line) =>
            line.returns.some((row) => row.canCheck),
        ),
    (value) => emit('editing', value),
    { immediate: true },
);
const announcement = ref('');
const newRetryKey = () => crypto.randomUUID();
const form = useForm({
    quantity: '',
    reason: '',
    note: '',
    condition: '',
    retry_key: newRetryKey(),
});

// Clear a field's error as soon as the field is corrected, so a fixed field never keeps its message.
for (const field of ['quantity', 'reason', 'note', 'condition'] as const) {
    watch(
        () => form[field],
        () => form.clearErrors(field),
    );
}

const fieldClass =
    'mt-1 w-full rounded-[10px] border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40';
const tileClass =
    'flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] border border-input bg-card px-3 text-sm focus-within:ring-3 focus-within:ring-ring/40 has-[:checked]:border-primary lg:min-h-9';

const waitingCount = computed(
    () =>
        props.returns.lines
            .flatMap((line) => line.returns)
            .filter((row) => row.status === 'waiting_check').length,
);
const countsLine = computed(() => {
    const lines = `${props.returns.lines.length} medicine ${props.returns.lines.length === 1 ? 'line' : 'lines'}`;
    const total = props.returns.lines.reduce(
        (sum, line) => sum + line.returnCount,
        0,
    );

    if (total === 0) {
        return `${lines} · No returns recorded`;
    }

    return `${lines} · ${waitingCount.value} ${waitingCount.value === 1 ? 'return' : 'returns'} waiting for a check`;
});
const canStillReturn = (line: ReturnLine) => Number(line.returnable) > 0;
const fieldId = (line: ReturnLine, name: string) =>
    `return-${line.publicId}-${name}`;
const describedBy = (line: ReturnLine, name: string, error?: string) =>
    error
        ? `${fieldId(line, name)}-help ${fieldId(line, name)}-error`
        : `${fieldId(line, name)}-help`;

const openForm = async (line: ReturnLine) => {
    form.reset();
    form.clearErrors();
    form.retry_key = newRetryKey();
    openLine.value = line.publicId;
    await nextTick();
    document.getElementById(fieldId(line, 'quantity'))?.focus();
};
const closeForm = async (line: ReturnLine) => {
    openLine.value = null;
    form.reset();
    form.clearErrors();
    await nextTick();
    document.getElementById(`return-${line.publicId}-open`)?.focus();
};

const quantityProblem = (line: ReturnLine): string | null => {
    const text = form.quantity.trim();

    if (text === '') {
        return 'Enter the quantity returned.';
    }

    if (!/^\d+(\.\d+)?$/.test(text)) {
        return 'Enter a plain number, for example 2 or 1.5.';
    }

    if ((text.split('.')[1] ?? '').replace(/0+$/, '').length > 3) {
        return 'Use at most 3 decimal places.';
    }

    if (Number(text) <= 0) {
        return 'Enter a quantity more than 0.';
    }

    if (Number(text) > Number(line.returnable)) {
        return `Enter at most ${line.returnable} ${line.unit}: ${line.dispensed} were dispensed and ${line.returned} are already returned.`;
    }

    return null;
};

const focusFirstError = async (line: ReturnLine) => {
    await nextTick();
    const order = ['quantity', 'reason', 'note', 'condition'];
    const first = order.find(
        (key) => form.errors[key as keyof typeof form.errors],
    );

    if (first === 'reason' || first === 'condition') {
        document
            .querySelector<HTMLInputElement>(
                `input[name="${fieldId(line, first)}"]`,
            )
            ?.focus();
    } else {
        document.getElementById(fieldId(line, first ?? 'quantity'))?.focus();
    }
};

const submit = async (line: ReturnLine) => {
    form.clearErrors();
    const quantityError = quantityProblem(line);

    if (quantityError) {
        form.setError('quantity', quantityError);
    }

    if (!form.reason) {
        form.setError('reason', 'Choose a reason.');
    }

    if (form.reason === 'other' && form.note.trim() === '') {
        form.setError('note', 'Write a short note for Other.');
    }

    if (!form.condition) {
        form.setError('condition', 'Choose the condition you saw.');
    }

    if (Object.keys(form.errors).length > 0) {
        await focusFirstError(line);

        return;
    }

    form.transform((data) => ({
        ...data,
        quantity: data.quantity.trim(),
        note: data.reason === 'other' ? data.note.trim() : null,
        expected_branch_id: props.returns.branchId,
        expected_return_count: line.returnCount,
    })).post(
        `/dispensary/${props.returns.casePublicId}/items/${line.publicId}/returns`,
        {
            preserveScroll: true,
            onSuccess: async () => {
                openLine.value = null;
                form.reset();
                form.retry_key = newRetryKey();
                announcement.value =
                    'Return recorded. It now waits for a check by someone else.';
                await nextTick();
                document
                    .querySelector<HTMLElement>(
                        `[data-line="${line.publicId}"] [data-return-item]:last-of-type`,
                    )
                    ?.focus();
            },
            onError: () => focusFirstError(line),
        },
    );
};

const serverAlert = computed(() => {
    const keys = Object.keys(form.errors);
    const general = keys.find((key) =>
        [
            'expected_return_count',
            'expected_branch_id',
            'retry_key',
            'state',
        ].includes(key),
    );

    if (general) {
        return form.errors[general as keyof typeof form.errors];
    }

    return keys.length > 0
        ? 'Check the fields marked below, then record the return again.'
        : null;
});
const waitingText = (row: ReturnRow) =>
    row.recordedByMe
        ? 'Waiting for a check by someone else. You recorded this return, so you cannot check it.'
        : 'Waiting for a check by a CA Supervisor.';
const outcomeLine = (row: ReturnRow) => {
    if (!row.check) {
        return '';
    }

    if (row.check.outcome === 'quarantine') {
        return `Moved to ${row.check.locationName ?? 'a returns location'} by ${row.check.checkedBy} · ${row.check.checkedAt}`;
    }

    return `${row.check.outcome === 'refuse' ? 'Refused' : 'Disposed'} by ${row.check.checkedBy} · ${row.check.checkedAt}`;
};
const onChecked = async (row: ReturnRow, message: string) => {
    announcement.value = message;
    await nextTick();
    document
        .querySelector<HTMLElement>(`[data-return-id="${row.publicId}"]`)
        ?.focus();
};
</script>

<template>
    <section
        id="medicine-returns"
        aria-labelledby="returns-heading"
        class="mt-8 grid gap-4 print:hidden"
        data-testid="medicine-returns"
    >
        <div
            class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
        >
            <h2 id="returns-heading" class="text-xl font-semibold">
                Medicine returns
            </h2>
            <p class="text-[13px] text-muted-foreground tabular-nums">
                {{ countsLine }}
            </p>
        </div>
        <p class="-mt-2 text-[13px] text-muted-foreground">
            Record medicine the patient brings back. Nothing goes back into
            stock until someone else checks the return.
        </p>
        <p
            role="note"
            class="rounded-[10px] border border-warning/25 bg-warning/10 px-3 py-2 text-sm text-warning-strong"
            data-testid="returns-no-refund"
        >
            <span class="font-semibold"
                >Recording a return gives no money back.</span
            >
            If the patient should get money back, a CA Supervisor or Finance
            Officer records it under Refunds on the visit's billing page, and
            someone else approves it.
        </p>
        <p class="sr-only" aria-live="polite">{{ announcement }}</p>

        <ul class="grid gap-4" aria-label="Dispensed medicine lines">
            <li v-for="line in returns.lines" :key="line.publicId">
                <article
                    :id="`line-${line.publicId}`"
                    :aria-labelledby="`return-${line.publicId}-heading`"
                    :data-line="line.publicId"
                    class="grid gap-4 rounded-[14px] border bg-card p-4 lg:grid-cols-[minmax(220px,1fr)_minmax(0,2fr)] lg:p-5"
                >
                    <div class="grid content-start gap-2">
                        <div>
                            <h3
                                :id="`return-${line.publicId}-heading`"
                                class="text-base font-semibold"
                            >
                                {{ line.name }}
                            </h3>
                            <p class="text-[13px] text-muted-foreground">
                                {{
                                    [line.strength, line.dosageForm, line.code]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </p>
                        </div>
                        <Badge
                            v-if="line.controlled"
                            variant="outline"
                            data-testid="return-controlled"
                            >Controlled</Badge
                        >
                        <dl
                            class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[13px] tabular-nums"
                        >
                            <dt class="text-muted-foreground">Dispensed</dt>
                            <dd>{{ line.dispensed }} {{ line.unit }}</dd>
                            <dt class="text-muted-foreground">
                                Returned so far
                            </dt>
                            <dd>
                                {{ line.returned }} {{ line.unit }}
                                <span
                                    v-if="
                                        line.returns.some(
                                            (row) =>
                                                row.status === 'waiting_check',
                                        )
                                    "
                                    class="text-muted-foreground"
                                    >(waiting for a check)</span
                                >
                            </dd>
                            <dt class="text-muted-foreground">
                                Can still return
                            </dt>
                            <dd>{{ line.returnable }} {{ line.unit }}</dd>
                        </dl>
                        <Button
                            v-if="
                                returns.canRecord &&
                                canStillReturn(line) &&
                                openLine !== line.publicId
                            "
                            :id="`return-${line.publicId}-open`"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="w-full sm:w-fit lg:h-9"
                            :aria-controls="`return-${line.publicId}-form`"
                            aria-expanded="false"
                            data-action="open-return-form"
                            @click="openForm(line)"
                        >
                            Record a return
                        </Button>
                        <p
                            v-else-if="!canStillReturn(line)"
                            class="text-xs text-muted-foreground"
                        >
                            Everything dispensed on this line has been recorded
                            as returned.
                        </p>
                    </div>

                    <div class="grid min-w-0 content-start gap-4">
                        <form
                            v-if="openLine === line.publicId"
                            :id="`return-${line.publicId}-form`"
                            :aria-labelledby="`return-${line.publicId}-form-title`"
                            class="grid gap-4 rounded-[10px] bg-muted/50 p-4"
                            novalidate
                            data-testid="return-form"
                            :aria-busy="form.processing"
                            @submit.prevent="submit(line)"
                        >
                            <p
                                :id="`return-${line.publicId}-form-title`"
                                class="text-[15px] font-semibold"
                            >
                                Record a return: {{ line.name }}
                            </p>
                            <p
                                v-if="serverAlert"
                                role="alert"
                                class="rounded-[10px] border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
                            >
                                {{ serverAlert }}
                            </p>

                            <div class="text-sm">
                                <label
                                    :for="fieldId(line, 'quantity')"
                                    class="font-medium"
                                    >Quantity returned
                                    <span class="text-muted-foreground"
                                        >(required)</span
                                    ></label
                                >
                                <div class="flex items-center gap-2">
                                    <input
                                        :id="fieldId(line, 'quantity')"
                                        v-model="form.quantity"
                                        type="text"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        maxlength="20"
                                        :disabled="form.processing"
                                        :aria-invalid="
                                            form.errors.quantity
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="
                                            describedBy(
                                                line,
                                                'quantity',
                                                form.errors.quantity,
                                            )
                                        "
                                        :class="[
                                            fieldClass,
                                            'h-11 max-w-40 tabular-nums lg:h-9',
                                        ]"
                                        data-field="return-quantity"
                                    />
                                    <span class="mt-1 text-muted-foreground">{{
                                        line.unit
                                    }}</span>
                                </div>
                                <span
                                    :id="`${fieldId(line, 'quantity')}-help`"
                                    class="mt-1 block text-xs text-muted-foreground"
                                    >More than 0 and at most
                                    {{ line.returnable }} {{ line.unit }}. Up to
                                    3 decimal places.</span
                                >
                                <span
                                    v-if="form.errors.quantity"
                                    :id="`${fieldId(line, 'quantity')}-error`"
                                    class="mt-1 block text-xs text-destructive"
                                    >{{ form.errors.quantity }}</span
                                >
                            </div>

                            <fieldset
                                class="grid gap-2 text-sm"
                                :aria-describedby="
                                    form.errors.reason
                                        ? `${fieldId(line, 'reason')}-error`
                                        : undefined
                                "
                            >
                                <legend class="mb-1 font-medium">
                                    Reason
                                    <span class="text-muted-foreground"
                                        >(required)</span
                                    >
                                </legend>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <label
                                        v-for="reason in returns.reasons"
                                        :key="reason"
                                        :class="tileClass"
                                    >
                                        <input
                                            v-model="form.reason"
                                            type="radio"
                                            :name="fieldId(line, 'reason')"
                                            :value="reason"
                                            :disabled="form.processing"
                                            class="accent-primary"
                                            data-field="return-reason"
                                        />
                                        {{ reasonLabels[reason] ?? reason }}
                                    </label>
                                </div>
                                <span
                                    v-if="form.errors.reason"
                                    :id="`${fieldId(line, 'reason')}-error`"
                                    class="text-xs text-destructive"
                                    >{{ form.errors.reason }}</span
                                >
                            </fieldset>

                            <div v-if="form.reason === 'other'" class="text-sm">
                                <label
                                    :for="fieldId(line, 'note')"
                                    class="font-medium"
                                    >Note
                                    <span class="text-muted-foreground"
                                        >(required)</span
                                    ></label
                                >
                                <textarea
                                    :id="fieldId(line, 'note')"
                                    v-model="form.note"
                                    rows="2"
                                    :maxlength="returns.noteMax"
                                    :disabled="form.processing"
                                    :aria-invalid="
                                        form.errors.note ? 'true' : undefined
                                    "
                                    :aria-describedby="
                                        describedBy(
                                            line,
                                            'note',
                                            form.errors.note,
                                        )
                                    "
                                    :class="[fieldClass, 'py-2']"
                                    data-field="return-note"
                                />
                                <span
                                    :id="`${fieldId(line, 'note')}-help`"
                                    class="mt-1 block text-xs text-muted-foreground"
                                    >Say briefly what happened, up to
                                    {{ returns.noteMax }} characters. No
                                    clinical or identity details.</span
                                >
                                <span
                                    v-if="form.errors.note"
                                    :id="`${fieldId(line, 'note')}-error`"
                                    class="mt-1 block text-xs text-destructive"
                                    >{{ form.errors.note }}</span
                                >
                            </div>

                            <fieldset
                                class="grid gap-2 text-sm"
                                :aria-describedby="
                                    form.errors.condition
                                        ? `${fieldId(line, 'condition')}-error`
                                        : undefined
                                "
                            >
                                <legend class="mb-1 font-medium">
                                    Condition seen
                                    <span class="text-muted-foreground"
                                        >(required)</span
                                    >
                                </legend>
                                <div class="grid gap-2 sm:grid-cols-3">
                                    <label
                                        v-for="condition in returns.conditions"
                                        :key="condition"
                                        :class="tileClass"
                                    >
                                        <input
                                            v-model="form.condition"
                                            type="radio"
                                            :name="fieldId(line, 'condition')"
                                            :value="condition"
                                            :disabled="form.processing"
                                            class="accent-primary"
                                            data-field="return-condition"
                                        />
                                        {{
                                            conditionLabels[condition] ??
                                            condition
                                        }}
                                    </label>
                                </div>
                                <span
                                    v-if="form.errors.condition"
                                    :id="`${fieldId(line, 'condition')}-error`"
                                    class="text-xs text-destructive"
                                    >{{ form.errors.condition }}</span
                                >
                            </fieldset>

                            <p class="text-xs text-muted-foreground">
                                <template v-if="line.controlled"
                                    >Nothing goes back into stock now. A CA
                                    Supervisor (not you) checks the return next,
                                    and a returned controlled medicine is always
                                    disposed.</template
                                ><template v-else
                                    >Nothing goes back into stock now. A CA
                                    Supervisor (not you) checks the return
                                    next.</template
                                >
                                A saved return cannot be edited; if it is wrong,
                                the checker refuses it.
                            </p>
                            <div
                                class="flex flex-col-reverse gap-2 lg:flex-row"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="h-11 w-full lg:h-9 lg:w-auto"
                                    :disabled="form.processing"
                                    data-action="cancel-return-form"
                                    @click="closeForm(line)"
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    class="h-11 w-full lg:h-9 lg:w-auto"
                                    :disabled="form.processing"
                                    data-action="record-return"
                                >
                                    {{
                                        form.processing
                                            ? 'Recording…'
                                            : 'Record return'
                                    }}
                                </Button>
                            </div>
                        </form>

                        <div v-if="line.returns.length" class="grid gap-2">
                            <h4
                                class="text-[13px] font-semibold text-muted-foreground"
                            >
                                Returns on this line ({{ line.returns.length }})
                            </h4>
                            <ol class="divide-y rounded-[10px] border">
                                <li
                                    v-for="row in line.returns"
                                    :key="row.publicId"
                                    tabindex="-1"
                                    data-return-item
                                    :data-return-id="row.publicId"
                                    class="grid gap-1 p-3 text-xs outline-none first:rounded-t-[10px] last:rounded-b-[10px] focus-visible:ring-3 focus-visible:ring-ring/40 focus-visible:ring-inset"
                                >
                                    <p
                                        class="flex flex-wrap items-center gap-2 text-sm font-medium tabular-nums"
                                    >
                                        {{ row.quantity }} {{ line.unit }}
                                        <StatusBadge :status="row.status" />
                                    </p>
                                    <p>
                                        {{
                                            reasonLabels[row.reason] ??
                                            row.reason
                                        }}
                                        ·
                                        {{
                                            conditionLabels[row.condition] ??
                                            row.condition
                                        }}
                                    </p>
                                    <p v-if="row.note" class="break-words">
                                        Note: “{{ row.note }}”
                                    </p>
                                    <p class="text-muted-foreground">
                                        Recorded by {{ row.recordedBy }} ·
                                        {{ row.recordedAt }}
                                    </p>
                                    <p
                                        v-if="row.check"
                                        class="text-muted-foreground"
                                    >
                                        {{ outcomeLine(row) }}
                                    </p>
                                    <p
                                        v-if="
                                            row.check &&
                                            batchesLine(
                                                row.check.batches,
                                                line.unit,
                                            )
                                        "
                                        class="text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                        data-testid="return-check-batches"
                                    >
                                        {{
                                            batchesLine(
                                                row.check.batches,
                                                line.unit,
                                            )
                                        }}
                                    </p>
                                    <p
                                        v-if="row.check?.refuseReason"
                                        class="break-words"
                                    >
                                        Refused because:
                                        {{
                                            refuseReasonLabels[
                                                row.check.refuseReason
                                            ] ?? row.check.refuseReason
                                        }}<template v-if="row.check.refuseNote"
                                            >: “{{
                                                row.check.refuseNote
                                            }}”</template
                                        >
                                    </p>
                                    <ReturnCheckPanel
                                        v-if="row.canCheck"
                                        :returns="returns"
                                        :line="line"
                                        :row="row"
                                        @checked="onChecked(row, $event)"
                                    />
                                    <p
                                        v-else-if="
                                            row.status === 'waiting_check'
                                        "
                                        class="text-muted-foreground"
                                    >
                                        {{ waitingText(row) }}
                                    </p>
                                </li>
                            </ol>
                        </div>
                    </div>
                </article>
            </li>
        </ul>
    </section>
</template>
