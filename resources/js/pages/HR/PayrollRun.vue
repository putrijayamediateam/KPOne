<script setup lang="ts">
// HR-7b one payroll run (HR-PLAN 16.4, 16.5 and 16.9, with Daniel's early look). Draft: prepare again, MTD, manual
// lines and Send. Sent: someone other than the preparer approves or returns it. Approved: the approver locks it. Errors
// stop sending; warnings never do. KPOne pays nothing and files nothing.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AccountantNote from '@/components/hr/payroll/AccountantNote.vue';
import RmInput from '@/components/hr/payroll/RmInput.vue';
import type { Option, RunStatus } from '@/components/hr/payroll/types';
import { on, rmText, runWords } from '@/components/hr/payroll/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { myr } from '@/types/billing';

type Totals = {
    earnings: number;
    deduction: number;
    refund: number;
    employee: number;
    employer: number;
    mtd: number;
    net: number;
};
type Line = {
    kind: string;
    section:
        'earnings' | 'deduction' | 'refund' | 'employee' | 'employer' | 'mtd';
    amountSen: number;
    explanation: string;
};
type RunPerson = {
    id: string;
    name: string;
    totals: Totals;
    mtdSen: number | null;
    needsMtd: boolean;
    errors: string[];
    warnings: string[];
    lines: Line[];
    manual: { id: string }[];
};
type Step = 'approve' | 'return' | 'lock';

const props = defineProps<{
    run: {
        id: string;
        kind: 'regular' | 'adjustment';
        status: RunStatus;
        month: string;
        group: string;
        payDay: number | null;
        lockVersion: number;
        preparedBy: string;
        sentBy: string | null;
        approvedBy: string | null;
        lockedAt: string | null;
        people: RunPerson[];
        total: Totals;
        errorCount: number;
        canEdit: boolean;
        sendRefusal: string | null;
        draftRefusal: string | null;
        steps: Record<Step, { refusal: string | null }>;
        options: {
            itemTypes: Option[];
            manualReasons: Option[];
            returnReasons: Option[];
        };
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(() => errors.value.payroll ?? errors.value.records);
const busy = ref(false);
const post = (
    step: string,
    data: Record<string, unknown>,
    done?: () => void,
) => {
    busy.value = true;
    router.post(
        `/hr/payroll/runs/${props.run.id}/${step}`,
        {
            ...data,
            expected_lock_version: props.run.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () => done?.(),
            onFinish: () => (busy.value = false),
        },
    );
};
const sections: { key: Line['section']; label: string }[] = [
    { key: 'earnings', label: 'Earnings' },
    { key: 'deduction', label: 'Deductions' },
    { key: 'refund', label: 'Refunds' },
    { key: 'employee', label: 'Employee contributions' },
    { key: 'mtd', label: 'MTD (PCB)' },
    { key: 'employer', label: 'Employer contributions (information)' },
];

// ---- one person's lines ----
const panel = reactive({ person: null as RunPerson | null, mtd: '' });
const openPerson = (p: RunPerson) =>
    Object.assign(panel, { person: p, mtd: rmText(p.mtdSen) });
const current = computed(
    () =>
        props.run.people.find((p) => p.id === panel.person?.id) ?? panel.person,
);
const saveMtd = () =>
    current.value &&
    post('mtd', { worker_id: current.value.id, mtd: panel.mtd });
const manual = reactive({ type: '', amount: '', reason: '' });
const addManual = () =>
    current.value &&
    post(
        'manual',
        {
            worker_id: current.value.id,
            item_type_id: manual.type,
            amount: manual.amount,
            reason_code: manual.reason,
        },
        () => Object.assign(manual, { type: '', amount: '', reason: '' }),
    );
const manualLines = computed(() =>
    (current.value?.lines ?? []).filter((l) => l.kind === 'manual'),
);
const removeManual = (index: number) => {
    const line = current.value?.manual[index];

    if (!line) {
        return;
    }

    busy.value = true;
    router.post(
        `/hr/payroll/runs/${props.run.id}/manual/${line.id}/remove`,
        {
            expected_lock_version: props.run.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
};

// ---- steps ----
const asking = ref<'send' | Step | null>(null);
const returnReason = ref('');
const confirm = () => {
    const step = asking.value;

    if (!step) {
        return;
    }

    post(
        step,
        step === 'return' ? { reason: returnReason.value } : {},
        () => (asking.value = null),
    );
};
const dialog = computed(() => {
    switch (asking.value) {
        case 'send':
            return {
                title: 'Send this run for approval?',
                description: `${props.run.group} · ${props.run.month}. Sending freezes it: nothing in it changes unless it is returned.`,
                label: 'Send for approval',
            };
        case 'approve':
            return {
                title: 'Approve this run?',
                description: `${props.run.group} · ${props.run.month} · net ${myr(props.run.total.net)}. You then lock it to finish.`,
                label: 'Approve',
            };
        case 'return':
            return {
                title: 'Return this run to draft?',
                description:
                    'The preparer can change it and send it again. The reason is kept.',
                label: 'Return to draft',
            };
        case 'lock':
            return {
                title: 'Lock this run?',
                description:
                    'A locked run never changes; a correction is an adjustment run. Staff claims in this run are recorded as paid through payroll.',
                label: 'Lock run',
            };
        default:
            return { title: '', description: '', label: '' };
    }
});
const statusTone = computed(() =>
    props.run.status === 'approved' || props.run.status === 'locked'
        ? 'success'
        : 'neutral',
);
</script>

<template>
    <Head title="Payroll run" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <Link
                href="/hr/payroll?tab=runs"
                class="inline-flex min-h-11 items-center self-start text-sm text-muted-foreground hover:underline lg:min-h-0"
                >← Payroll runs</Link
            >
            <PageHeader
                eyebrow="KPOne HR"
                :title="`${run.group} · ${run.month}${run.kind === 'adjustment' ? ' · adjustment' : ''}`"
                description="A payroll run works out each person's pay from approved records. Someone other than the preparer approves it. KPOne makes no payment and files nothing."
            />
            <AccountantNote />
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <StatusBadge
                :status="run.status"
                :label="runWords[run.status]"
                :tone="statusTone"
            />
            <span class="text-muted-foreground"
                >Prepared by {{ run.preparedBy
                }}<template v-if="run.sentBy">
                    · sent by {{ run.sentBy }}</template
                ><template v-if="run.approvedBy">
                    · approved by {{ run.approvedBy }}</template
                ><template v-if="run.lockedAt">
                    · locked {{ on(run.lockedAt) }}</template
                ><template v-if="run.payDay">
                    · pay day {{ run.payDay }}</template
                ></span
            >
        </div>

        <ErrorState v-if="failure && !asking" :message="failure" />

        <p
            v-if="run.draftRefusal"
            id="draft-refusal"
            class="text-sm text-muted-foreground"
            data-test="run-draft-refusal"
        >
            {{ run.draftRefusal }}
        </p>

        <div class="flex flex-wrap items-center gap-2" data-test="run-steps">
            <template v-if="run.status === 'draft'">
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    :disabled="busy || !!run.draftRefusal"
                    data-test="run-again"
                    @click="post('again', {})"
                    >Prepare again</Button
                >
                <Button
                    class="max-lg:min-h-11"
                    :disabled="busy || !!run.sendRefusal || !!run.draftRefusal"
                    :aria-describedby="
                        run.sendRefusal ? 'send-refusal' : undefined
                    "
                    data-test="run-send"
                    @click="asking = 'send'"
                    >Send for approval</Button
                >
                <p
                    v-if="run.sendRefusal && !run.draftRefusal"
                    id="send-refusal"
                    class="text-sm text-destructive"
                >
                    {{ run.sendRefusal }}
                </p>
            </template>
            <template v-else>
                <Button
                    v-if="run.status === 'sent' && !run.steps.approve.refusal"
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="run-approve"
                    @click="asking = 'approve'"
                    >Approve</Button
                >
                <Button
                    v-if="run.status === 'approved' && !run.steps.lock.refusal"
                    class="max-lg:min-h-11"
                    data-test="run-lock"
                    @click="asking = 'lock'"
                    >Lock run</Button
                >
                <Button
                    v-if="run.status !== 'locked' && !run.steps.return.refusal"
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="run-return"
                    @click="
                        returnReason = '';
                        asking = 'return';
                    "
                    >Return to draft</Button
                >
                <p
                    v-if="run.status === 'sent' && run.steps.approve.refusal"
                    class="text-sm text-muted-foreground"
                >
                    {{ run.steps.approve.refusal }}
                </p>
                <p
                    v-if="run.status === 'approved' && run.steps.lock.refusal"
                    class="text-sm text-muted-foreground"
                >
                    {{ run.steps.lock.refusal }}
                </p>
                <p
                    v-if="run.status === 'locked'"
                    class="text-sm text-muted-foreground"
                >
                    Locked. Payslips and exports come with HR-7c.
                </p>
            </template>
        </div>

        <p
            v-if="
                run.status === 'draft' &&
                run.people.some((p) => p.warnings.length)
            "
            class="text-sm text-muted-foreground"
        >
            Warnings don't stop you sending.
        </p>

        <EmptyState
            v-if="!run.people.length"
            title="Nobody is in this run."
            :description="
                run.kind === 'adjustment'
                    ? 'Add a difference line for someone in the locked run.'
                    : 'Only staff with a confirmed pay profile in this pay group are in a run.'
            "
        />

        <!-- 1024 px and wider: a table with a sticky person column (Daniel). -->
        <div
            v-if="run.people.length"
            class="hidden overflow-x-auto rounded-xl border bg-card lg:block"
            data-test="run-table"
        >
            <table class="w-full min-w-[60rem] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th
                            scope="col"
                            class="sticky left-0 z-10 bg-muted px-3 py-2 font-medium"
                        >
                            Person
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            Earnings
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            Deductions
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            Refunds
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            Employee contributions
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            MTD
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            Net
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            To check
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="p in run.people"
                        :key="p.id"
                        class="border-t tabular-nums"
                        data-test="run-person"
                    >
                        <th
                            scope="row"
                            class="sticky left-0 bg-card px-3 py-2 text-left font-normal"
                        >
                            <Button
                                variant="link"
                                class="h-auto p-0 text-left"
                                :aria-label="`Lines for ${p.name}`"
                                @click="openPerson(p)"
                                >{{ p.name }}</Button
                            >
                        </th>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(p.totals.earnings) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(p.totals.deduction) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(p.totals.refund) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(p.totals.employee) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(p.totals.mtd) }}
                        </td>
                        <td
                            class="px-3 py-2 text-right font-semibold whitespace-nowrap"
                        >
                            {{ myr(p.totals.net) }}
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                <StatusBadge
                                    v-for="e in p.errors"
                                    :key="e"
                                    status="error"
                                    :label="e"
                                    tone="danger"
                                />
                                <StatusBadge
                                    v-for="w in p.warnings"
                                    :key="w"
                                    status="warning"
                                    :label="w"
                                    tone="warning"
                                />
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 font-semibold tabular-nums">
                        <th
                            scope="row"
                            class="sticky left-0 bg-card px-3 py-2 text-left"
                        >
                            Total
                        </th>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(run.total.earnings) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(run.total.deduction) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(run.total.refund) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(run.total.employee) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(run.total.mtd) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ myr(run.total.net) }}
                        </td>
                        <td class="px-3 py-2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Below 1024 px: one card per person. -->
        <ul v-if="run.people.length" class="flex flex-col gap-3 lg:hidden">
            <li
                v-for="p in run.people"
                :key="p.id"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                data-test="run-person-card"
            >
                <div class="flex items-start justify-between gap-2">
                    <h3 class="font-semibold break-words">{{ p.name }}</h3>
                    <span class="font-semibold whitespace-nowrap tabular-nums"
                        >Net {{ myr(p.totals.net) }}</span
                    >
                </div>
                <p class="text-sm text-muted-foreground tabular-nums">
                    Earnings {{ myr(p.totals.earnings) }} · Deductions
                    {{ myr(p.totals.deduction)
                    }}<template v-if="p.totals.refund">
                        · Refunds {{ myr(p.totals.refund) }}</template
                    >
                    · Employee contributions {{ myr(p.totals.employee) }}
                </p>
                <div
                    v-if="p.errors.length || p.warnings.length"
                    class="flex flex-wrap gap-1"
                >
                    <StatusBadge
                        v-for="e in p.errors"
                        :key="e"
                        status="error"
                        :label="e"
                        tone="danger"
                    />
                    <StatusBadge
                        v-for="w in p.warnings"
                        :key="w"
                        status="warning"
                        :label="w"
                        tone="warning"
                    />
                </div>
                <Button
                    variant="outline"
                    class="min-h-11 self-start"
                    :aria-label="`Lines for ${p.name}`"
                    @click="openPerson(p)"
                    >Lines</Button
                >
            </li>
            <li
                class="flex justify-between rounded-xl border bg-muted/40 p-4 font-semibold tabular-nums"
            >
                <span>Total net</span><span>{{ myr(run.total.net) }}</span>
            </li>
        </ul>

        <Sheet
            :open="panel.person !== null"
            @update:open="(v: boolean) => !v && (panel.person = null)"
        >
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-lg"
                data-test="run-lines"
            >
                <SheetHeader>
                    <SheetTitle>{{ current?.name }}</SheetTitle>
                    <SheetDescription
                        >Every line with where it came from. Net
                        {{ myr(current?.totals.net ?? 0) }}.</SheetDescription
                    >
                </SheetHeader>
                <div v-if="current" class="flex flex-col gap-4 px-4 pb-6">
                    <ErrorState v-if="failure" :message="failure" />
                    <template v-for="s in sections" :key="s.key">
                        <div
                            v-if="
                                current.lines.some((l) => l.section === s.key)
                            "
                            class="flex flex-col gap-1"
                        >
                            <h4 class="text-sm font-semibold">{{ s.label }}</h4>
                            <ul class="flex flex-col gap-1 text-sm">
                                <li
                                    v-for="(l, i) in current.lines.filter(
                                        (x) => x.section === s.key,
                                    )"
                                    :key="i"
                                    class="flex justify-between gap-3"
                                >
                                    <span class="min-w-0 break-words">{{
                                        l.explanation
                                    }}</span>
                                    <span
                                        class="shrink-0 whitespace-nowrap tabular-nums"
                                        >{{ myr(l.amountSen) }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </template>

                    <form
                        v-if="run.canEdit && current.needsMtd"
                        class="flex flex-col gap-2 border-t pt-4"
                        @submit.prevent="saveMtd"
                    >
                        <Label for="run-mtd">MTD (PCB) for this run</Label>
                        <RmInput
                            id="run-mtd"
                            v-model="panel.mtd"
                            describedby="run-mtd-help"
                            :invalid="!!errors.mtd"
                        />
                        <p
                            id="run-mtd-help"
                            class="text-xs text-muted-foreground"
                        >
                            From the LHDN calculator or the accountant. KPOne
                            does not work out MTD yet.
                        </p>
                        <InputError :message="errors.mtd" />
                        <Button
                            type="submit"
                            variant="outline"
                            class="self-start max-lg:min-h-11"
                            :disabled="busy || !!run.draftRefusal"
                            data-test="run-mtd-save"
                            >Save MTD</Button
                        >
                    </form>

                    <div
                        v-if="run.canEdit"
                        class="flex flex-col gap-3 border-t pt-4"
                    >
                        <h4 class="text-sm font-semibold">Manual lines</h4>
                        <ul
                            v-if="manualLines.length"
                            class="flex flex-col gap-1 text-sm"
                        >
                            <li
                                v-for="(l, i) in manualLines"
                                :key="i"
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="min-w-0 break-words"
                                    >{{ l.explanation }} ·
                                    {{ myr(l.amountSen) }}</span
                                >
                                <Button
                                    variant="outline"
                                    class="min-h-11 lg:min-h-8"
                                    :aria-label="`Remove manual line ${i + 1}`"
                                    :disabled="busy || !!run.draftRefusal"
                                    @click="removeManual(i)"
                                    >Remove</Button
                                >
                            </li>
                        </ul>
                        <form
                            class="flex flex-col gap-2"
                            @submit.prevent="addManual"
                        >
                            <div class="grid gap-1.5">
                                <Label for="manual-type"
                                    ><span id="manual-type-label"
                                        >Pay item type *</span
                                    ></Label
                                >
                                <OperationalSelect
                                    id="manual-type"
                                    v-model="manual.type"
                                    labelledby="manual-type-label"
                                    label="Pay item type"
                                    placeholder="Choose a type"
                                    trigger-class="max-lg:h-11"
                                    :options="run.options.itemTypes"
                                    :invalid="!!errors.item_type_id"
                                />
                                <InputError :message="errors.item_type_id" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="manual-amount">Amount *</Label>
                                <RmInput
                                    id="manual-amount"
                                    v-model="manual.amount"
                                    :invalid="!!errors.amount"
                                />
                                <InputError :message="errors.amount" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="manual-reason"
                                    ><span id="manual-reason-label"
                                        >Why *</span
                                    ></Label
                                >
                                <OperationalSelect
                                    id="manual-reason"
                                    v-model="manual.reason"
                                    labelledby="manual-reason-label"
                                    label="Why"
                                    placeholder="Choose a reason"
                                    trigger-class="max-lg:h-11"
                                    :options="run.options.manualReasons"
                                    :invalid="!!errors.reason_code"
                                />
                                <InputError :message="errors.reason_code" />
                            </div>
                            <InputError :message="errors.worker_id" />
                            <Button
                                type="submit"
                                variant="outline"
                                class="self-start max-lg:min-h-11"
                                :disabled="busy || !!run.draftRefusal"
                                data-test="run-manual-add"
                                >Add line</Button
                            >
                        </form>
                    </div>
                </div>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="asking !== null"
            :title="dialog.title"
            :description="dialog.description"
            :confirm-label="dialog.label"
            processing-label="Saving…"
            :processing="busy"
            :error="failure"
            @update:open="(v: boolean) => !v && (asking = null)"
            @confirm="confirm"
        >
            <div v-if="asking === 'return'" class="grid gap-1.5">
                <Label for="run-return-reason"
                    ><span id="run-return-reason-label"
                        >Why it goes back *</span
                    ></Label
                >
                <OperationalSelect
                    id="run-return-reason"
                    v-model="returnReason"
                    labelledby="run-return-reason-label"
                    label="Why it goes back"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:h-11"
                    :options="run.options.returnReasons"
                    :invalid="!!errors.reason"
                />
                <InputError :message="errors.reason" />
            </div>
        </OperationalConfirmDialog>
    </div>
</template>
