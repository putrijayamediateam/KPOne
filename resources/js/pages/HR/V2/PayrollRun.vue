<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountantNote from '@/components/hr/payroll/AccountantNote.vue';
import { on, runWords } from '@/components/hr/payroll/types';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2MoneyInput from '@/components/v2/V2MoneyInput.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePayrollRunPage } from '@/composables/hr/usePayrollRunPage';
import type { PayrollRunPageProps } from '@/composables/hr/usePayrollRunPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { myr } from '@/types/billing';

/**
 * Version 2 payroll run (shown only in V2; Classic is `pages/HR/PayrollRun.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `usePayrollRunPage`, which Classic uses too,
 * so this page sends none of its own. Draft: prepare again, MTD, manual lines and Send. Sent: someone other than the
 * preparer approves or returns it. Approved: the approver locks it. Errors stop sending; warnings never do. Late items
 * show kinds and changes in words, never money. KPOne pays nothing and files nothing.
 */
const props = defineProps<PayrollRunPageProps>();
usePageCrumbs(() => [
    { title: 'KPOne HR', href: '/hr' },
    { title: 'Payroll', href: '/hr/payroll?tab=runs' },
    { title: `${props.run.group} · ${props.run.month}` },
]);

const {
    errors,
    failure,
    busy,
    post,
    sections,
    shownLines,
    mtdNote,
    panel,
    openPerson,
    current,
    saveMtd,
    manual,
    addManual,
    manualLines,
    removeManual,
    asking,
    returnReason,
    addLate,
    removeLate,
    lateOnlyOvertime,
    startAdjustment,
    confirm,
    dialog,
    statusTone,
} = usePayrollRunPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const dangerButton = `${button} text-destructive`;
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const card = 'rounded-[14px] border bg-card';

const title = computed(
    () =>
        `${props.run.group} · ${props.run.month}${props.run.kind === 'adjustment' ? ' · adjustment' : ''}`,
);
const askReturn = () => {
    returnReason.value = '';
    asking.value = 'return';
};
const returnMissing = computed(() =>
    asking.value === 'return' && returnReason.value === ''
        ? 'Pick a reason to continue.'
        : '',
);
// Names every missing item in one sentence (Daniel), for example
// "Choose a pay item type, enter the amount and choose why to add a line."
const manualMissing = computed(() => {
    const missing = [
        manual.type === '' ? 'choose a pay item type' : '',
        manual.amount.trim() === '' ? 'enter the amount' : '',
        manual.reason === '' ? 'choose why' : '',
    ].filter((part) => part !== '');

    if (missing.length === 0) {
        return '';
    }

    const list =
        missing.length === 1
            ? missing[0]
            : `${missing.slice(0, -1).join(', ')} and ${missing[missing.length - 1]}`;

    return `${list.charAt(0).toUpperCase()}${list.slice(1)} to add a line.`;
});
const anyWarnings = computed(() =>
    props.run.people.some((p) => p.warnings.length),
);
const lateLabel = (item: { name: string; kindLabel: string; date: string }) =>
    `${item.name}, ${item.kindLabel}, ${item.date}`;
</script>

<template>
    <Head title="Payroll run" />
    <PageFrame>
        <V2WorkLayout
            eyebrow="Payroll run"
            :title="title"
            description="A payroll run works out each person's pay from approved records. Someone other than the preparer approves it. KPOne makes no payment and files nothing."
        >
            <template #actions>
                <template v-if="run.status === 'draft'">
                    <button
                        type="button"
                        :class="button"
                        :disabled="busy || !!run.draftRefusal"
                        data-test="run-again"
                        @click="post('again', {})"
                    >
                        Prepare again
                    </button>
                    <button
                        type="button"
                        :class="primaryButton"
                        :disabled="
                            busy || !!run.sendRefusal || !!run.draftRefusal
                        "
                        :aria-describedby="
                            run.sendRefusal ? 'v2-send-refusal' : undefined
                        "
                        data-test="run-send"
                        @click="asking = 'send'"
                    >
                        Send for approval
                    </button>
                </template>
                <template v-else>
                    <button
                        v-if="
                            run.status !== 'locked' && !run.steps.return.refusal
                        "
                        type="button"
                        :class="button"
                        data-test="run-return"
                        @click="askReturn"
                    >
                        Return to draft
                    </button>
                    <button
                        v-if="
                            run.status === 'sent' && !run.steps.approve.refusal
                        "
                        type="button"
                        :class="primaryButton"
                        data-test="run-approve"
                        @click="asking = 'approve'"
                    >
                        Approve
                    </button>
                    <button
                        v-if="
                            run.status === 'approved' && !run.steps.lock.refusal
                        "
                        type="button"
                        :class="primaryButton"
                        data-test="run-lock"
                        @click="asking = 'lock'"
                    >
                        Lock run
                    </button>
                </template>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <AccountantNote />
                    <div
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                        data-test="run-steps"
                    >
                        <V2Chip
                            :tone="statusTone"
                            :label="runWords[run.status]"
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

                    <p
                        v-if="failure && !asking && !panel.person"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>
                    <p
                        v-if="run.draftRefusal"
                        class="text-sm text-muted-foreground"
                        data-test="run-draft-refusal"
                    >
                        {{ run.draftRefusal }}
                    </p>
                    <p
                        v-if="
                            run.status === 'draft' &&
                            run.sendRefusal &&
                            !run.draftRefusal
                        "
                        id="v2-send-refusal"
                        class="text-sm text-muted-foreground"
                    >
                        {{ run.sendRefusal }}
                    </p>
                    <p
                        v-if="
                            run.status === 'sent' && run.steps.approve.refusal
                        "
                        class="text-sm text-muted-foreground"
                    >
                        {{ run.steps.approve.refusal }}
                    </p>
                    <p
                        v-if="
                            run.status === 'approved' && run.steps.lock.refusal
                        "
                        class="text-sm text-muted-foreground"
                    >
                        {{ run.steps.lock.refusal }}
                    </p>
                    <p
                        v-if="run.status === 'locked'"
                        class="text-sm text-muted-foreground"
                    >
                        Locked. Each person's payslip is ready on My HR details.
                    </p>
                    <p
                        v-if="run.status === 'draft' && anyWarnings"
                        class="text-sm text-muted-foreground"
                    >
                        Warnings don't stop you sending.
                    </p>
                    <p
                        v-if="run.lateHoursUnknown > 0"
                        class="text-sm text-muted-foreground"
                        data-test="run-late-hours-unknown"
                    >
                        Hours changed after this lock can't be checked for
                        {{
                            run.lateHoursUnknown === 1
                                ? '1 person paid by the hour'
                                : `${run.lateHoursUnknown} people paid by the hour`
                        }}: add any correction as a manual line on an adjustment
                        run.
                    </p>

                    <!-- Late items (G2a, G2b): kinds and changes in words, never money. -->
                    <section
                        v-if="run.late.length"
                        :class="card"
                        class="flex flex-col gap-3 p-4"
                        aria-labelledby="v2-run-late-heading"
                        data-test="run-late"
                    >
                        <div class="flex flex-col gap-1">
                            <V2Chip
                                class="self-start"
                                tone="neutral"
                                :label="
                                    lateOnlyOvertime
                                        ? 'Late overtime'
                                        : 'Late changes'
                                "
                            />
                            <h2
                                id="v2-run-late-heading"
                                class="text-base font-semibold"
                            >
                                {{
                                    lateOnlyOvertime
                                        ? 'Approved after this run was locked'
                                        : 'Changed after this run was locked'
                                }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                <template v-if="lateOnlyOvertime">{{
                                    run.kind === 'regular'
                                        ? 'This overtime is in this month but was approved after the lock, so this run does not pay it. Pay is worked out on an adjustment run.'
                                        : 'Overtime approved after the locked run. Nothing is added unless you add it here.'
                                }}</template
                                ><template v-else>{{
                                    run.kind === 'regular'
                                        ? 'These changes are in this month but came after the lock, so this run does not include them. They are worked out on an adjustment run.'
                                        : 'Changes made after the locked run. Nothing is added or taken unless you add it here.'
                                }}</template>
                            </p>
                        </div>
                        <div
                            v-if="run.lateAdjustment"
                            class="flex flex-wrap items-center gap-2"
                            data-test="run-late-next"
                        >
                            <template v-if="run.lateAdjustment.id === null">
                                <p class="text-sm">
                                    Start an adjustment run to add these.
                                </p>
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="busy"
                                    data-test="run-late-start"
                                    @click="startAdjustment"
                                >
                                    Start adjustment run
                                </button>
                            </template>
                            <template v-else-if="run.lateAdjustment.draft">
                                <p class="text-sm">
                                    An adjustment run for this month is open:
                                    add these there.
                                </p>
                                <Link
                                    :href="`/hr/payroll/runs/${run.lateAdjustment.id}`"
                                    :class="button"
                                    data-test="run-late-open"
                                    >Open adjustment run</Link
                                >
                            </template>
                            <p v-else class="text-sm text-muted-foreground">
                                An adjustment run for this month is waiting for
                                approval. Once it is locked, start another to
                                add these.
                            </p>
                        </div>
                        <ul
                            class="flex flex-col divide-y rounded-[10px] border"
                        >
                            <li
                                v-for="item in run.late"
                                :key="`${item.kind}:${item.id}`"
                                class="flex flex-col gap-2 p-3 lg:flex-row lg:items-center lg:justify-between"
                                data-test="run-late-item"
                            >
                                <div class="flex min-w-0 flex-col gap-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span class="font-medium break-words">{{
                                            item.name
                                        }}</span>
                                        <V2Chip
                                            tone="neutral"
                                            :label="item.kindLabel"
                                            data-test="run-late-kind"
                                        />
                                    </div>
                                    <span
                                        class="text-sm"
                                        data-test="run-late-change"
                                        >{{ item.change }}</span
                                    >
                                    <span class="text-sm text-muted-foreground"
                                        >For {{ item.date
                                        }}<template v-if="item.approvedAt">
                                            · decided on
                                            {{ on(item.approvedAt) }}</template
                                        ></span
                                    >
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        v-if="
                                            run.kind === 'adjustment' &&
                                            run.canEdit &&
                                            item.state === 'waiting'
                                        "
                                        type="button"
                                        :class="button"
                                        :disabled="busy"
                                        data-test="run-late-add"
                                        @click="addLate(item)"
                                    >
                                        Add to this adjustment<span
                                            class="sr-only"
                                            >: {{ lateLabel(item) }}</span
                                        >
                                    </button>
                                    <template v-else-if="item.state === 'here'">
                                        <V2Chip
                                            tone="success"
                                            label="Added to this adjustment"
                                            data-test="run-late-added"
                                        />
                                        <button
                                            v-if="run.canEdit"
                                            type="button"
                                            :class="dangerButton"
                                            :disabled="busy"
                                            data-test="run-late-remove"
                                            @click="removeLate(item)"
                                        >
                                            Take off<span class="sr-only"
                                                >: {{ lateLabel(item) }}</span
                                            >
                                        </button>
                                    </template>
                                    <V2Chip
                                        v-else-if="item.state === 'elsewhere'"
                                        tone="neutral"
                                        label="On an adjustment run"
                                        data-test="run-late-elsewhere"
                                    />
                                </div>
                            </li>
                        </ul>
                    </section>

                    <div
                        v-if="!run.people.length"
                        :class="card"
                        class="p-6 text-center"
                    >
                        <p class="font-medium">Nobody is in this run.</p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                run.kind === 'adjustment'
                                    ? 'Add a difference line for someone in the locked run.'
                                    : 'Only staff with a confirmed pay profile in this pay group are in a run.'
                            }}
                        </p>
                    </div>

                    <!-- 1024 px and wider: a table with a sticky person column. -->
                    <div
                        v-if="run.people.length"
                        :class="card"
                        class="hidden overflow-x-auto lg:block"
                        data-test="run-table"
                    >
                        <table class="w-full min-w-[60rem] text-sm">
                            <caption class="sr-only">
                                Pay for each person in this run
                            </caption>
                            <thead class="text-left text-muted-foreground">
                                <tr>
                                    <th
                                        scope="col"
                                        class="sticky left-0 z-10 bg-card px-3 py-2 font-medium"
                                    >
                                        Person
                                    </th>
                                    <th
                                        v-for="h in [
                                            'Earnings',
                                            'Deductions',
                                            'Refunds',
                                            'Employee contributions',
                                            'MTD',
                                            'Net',
                                        ]"
                                        :key="h"
                                        scope="col"
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        {{ h }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 font-medium"
                                    >
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
                                        <button
                                            type="button"
                                            class="min-h-9 text-left font-medium text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :aria-label="`Lines for ${p.name}`"
                                            @click="openPerson(p)"
                                        >
                                            {{ p.name }}
                                        </button>
                                    </th>
                                    <td
                                        v-for="key in [
                                            'earnings',
                                            'deduction',
                                            'refund',
                                            'employee',
                                            'mtd',
                                        ] as const"
                                        :key="key"
                                        class="px-3 py-2 text-right whitespace-nowrap"
                                    >
                                        {{ myr(p.totals[key]) }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right font-semibold whitespace-nowrap"
                                    >
                                        {{ myr(p.totals.net) }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-1">
                                            <V2Chip
                                                v-for="e in p.errors"
                                                :key="e"
                                                tone="danger"
                                                :label="e"
                                            />
                                            <V2Chip
                                                v-for="w in p.warnings"
                                                :key="w"
                                                tone="warning"
                                                :label="w"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr
                                    class="border-t-2 font-semibold tabular-nums"
                                >
                                    <th
                                        scope="row"
                                        class="sticky left-0 bg-card px-3 py-2 text-left"
                                    >
                                        Total
                                    </th>
                                    <td
                                        v-for="key in [
                                            'earnings',
                                            'deduction',
                                            'refund',
                                            'employee',
                                            'mtd',
                                            'net',
                                        ] as const"
                                        :key="key"
                                        class="px-3 py-2 text-right whitespace-nowrap"
                                    >
                                        {{ myr(run.total[key]) }}
                                    </td>
                                    <td class="px-3 py-2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Below 1024 px: one card per person. -->
                    <ul
                        v-if="run.people.length"
                        class="flex flex-col gap-3 lg:hidden"
                    >
                        <li
                            v-for="p in run.people"
                            :key="p.id"
                            :class="card"
                            class="flex flex-col gap-2 p-4"
                            data-test="run-person-card"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="font-semibold break-words">
                                    {{ p.name }}
                                </h3>
                                <span
                                    class="font-semibold whitespace-nowrap tabular-nums"
                                    >Net {{ myr(p.totals.net) }}</span
                                >
                            </div>
                            <p
                                class="text-sm text-muted-foreground tabular-nums"
                            >
                                Earnings {{ myr(p.totals.earnings) }} ·
                                Deductions {{ myr(p.totals.deduction)
                                }}<template v-if="p.totals.refund">
                                    · Refunds
                                    {{ myr(p.totals.refund) }}</template
                                >
                                · Employee contributions
                                {{ myr(p.totals.employee) }}
                            </p>
                            <div
                                v-if="p.errors.length || p.warnings.length"
                                class="flex flex-wrap gap-1"
                            >
                                <V2Chip
                                    v-for="e in p.errors"
                                    :key="e"
                                    tone="danger"
                                    :label="e"
                                />
                                <V2Chip
                                    v-for="w in p.warnings"
                                    :key="w"
                                    tone="warning"
                                    :label="w"
                                />
                            </div>
                            <button
                                type="button"
                                :class="button"
                                class="self-start"
                                :aria-label="`Lines for ${p.name}`"
                                @click="openPerson(p)"
                            >
                                Lines
                            </button>
                        </li>
                        <li
                            class="flex justify-between rounded-[14px] border bg-muted/40 p-4 font-semibold tabular-nums"
                        >
                            <span>Total net</span
                            ><span>{{ myr(run.total.net) }}</span>
                        </li>
                    </ul>
                </div>
            </template>
        </V2WorkLayout>

        <!-- One person's lines: every line with where it came from; MTD and manual lines on a draft. -->
        <V2Dialog
            :open="panel.person !== null"
            size="tall"
            :title="current?.name ?? ''"
            :description="`Every line with where it came from. Net ${myr(current?.totals.net ?? 0)}.`"
            confirm-label="Close"
            single-action
            single-action-style="outline"
            @update:open="(v: boolean) => !v && (panel.person = null)"
            @confirm="panel.person = null"
        >
            <div
                v-if="current"
                class="flex flex-col gap-4"
                data-test="run-lines"
            >
                <p v-if="failure" role="alert" class="text-sm text-destructive">
                    {{ failure }}
                </p>
                <template v-for="s in sections" :key="s.key">
                    <div
                        v-if="shownLines(current.lines, s.key).length"
                        class="flex flex-col gap-1"
                    >
                        <h3 class="text-sm font-semibold">{{ s.label }}</h3>
                        <ul class="flex flex-col gap-1 text-sm">
                            <li
                                v-for="(l, i) in shownLines(
                                    current.lines,
                                    s.key,
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
                        <p
                            v-if="s.key === 'mtd' && mtdNote(current.lines)"
                            class="text-sm text-muted-foreground"
                            data-test="run-mtd-note"
                        >
                            {{ mtdNote(current.lines) }}
                        </p>
                    </div>
                </template>

                <form
                    v-if="run.canEdit && current.needsMtd"
                    class="flex flex-col gap-3 border-t pt-4"
                    @submit.prevent="saveMtd"
                >
                    <V2MoneyInput
                        v-model="panel.mtd"
                        label="MTD (PCB) for this run"
                        hint="KPOne works out MTD when the pay profile has MTD details and the year's MTD schedule is checked. A figure saved here replaces it for this run; leave it empty to use the worked-out figure."
                        :error="errors.mtd"
                    />
                    <button
                        type="submit"
                        :class="button"
                        class="self-start"
                        :disabled="busy || !!run.draftRefusal"
                        data-test="run-mtd-save"
                    >
                        Save MTD
                    </button>
                </form>

                <div
                    v-if="run.canEdit"
                    class="flex flex-col gap-3 border-t pt-4"
                >
                    <h3 class="text-sm font-semibold">Manual lines</h3>
                    <ul
                        v-if="manualLines.length"
                        class="flex flex-col divide-y rounded-[10px] border text-sm"
                    >
                        <li
                            v-for="(l, i) in manualLines"
                            :key="i"
                            class="flex items-center justify-between gap-2 p-2"
                        >
                            <span class="min-w-0 break-words"
                                >{{ l.explanation }} ·
                                {{ myr(l.amountSen) }}</span
                            >
                            <button
                                type="button"
                                :class="dangerButton"
                                :aria-label="`Remove manual line ${i + 1}`"
                                :disabled="busy || !!run.draftRefusal"
                                @click="removeManual(i)"
                            >
                                Remove
                            </button>
                        </li>
                    </ul>
                    <form
                        class="flex flex-col gap-3"
                        @submit.prevent="manualMissing === '' && addManual()"
                    >
                        <div class="grid gap-1.5">
                            <label
                                id="v2-manual-type-label"
                                class="text-sm font-medium"
                                >Pay item type
                                <span
                                    class="text-destructive"
                                    aria-hidden="true"
                                    >*</span
                                ></label
                            >
                            <OperationalSelect
                                v-model="manual.type"
                                labelledby="v2-manual-type-label"
                                label="Pay item type"
                                placeholder="Choose a type"
                                trigger-class="h-11 w-full lg:h-9"
                                :options="run.options.itemTypes"
                                :invalid="!!errors.item_type_id"
                            />
                            <p
                                v-if="errors.item_type_id"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ errors.item_type_id }}
                            </p>
                        </div>
                        <V2MoneyInput
                            v-model="manual.amount"
                            label="Amount"
                            :error="errors.amount"
                            required
                        />
                        <div class="grid gap-1.5">
                            <label
                                id="v2-manual-reason-label"
                                class="text-sm font-medium"
                                >Why
                                <span
                                    class="text-destructive"
                                    aria-hidden="true"
                                    >*</span
                                ></label
                            >
                            <OperationalSelect
                                v-model="manual.reason"
                                labelledby="v2-manual-reason-label"
                                label="Why"
                                placeholder="Choose a reason"
                                trigger-class="h-11 w-full lg:h-9"
                                :options="run.options.manualReasons"
                                :invalid="!!errors.reason_code"
                            />
                            <p
                                v-if="errors.reason_code"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ errors.reason_code }}
                            </p>
                        </div>
                        <p
                            v-if="errors.worker_id"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errors.worker_id }}
                        </p>
                        <p
                            v-if="manualMissing"
                            id="v2-manual-missing"
                            class="text-sm text-muted-foreground"
                        >
                            {{ manualMissing }}
                        </p>
                        <button
                            type="submit"
                            :class="button"
                            class="self-start aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            :disabled="busy || !!run.draftRefusal"
                            :aria-disabled="manualMissing ? 'true' : undefined"
                            :aria-describedby="
                                manualMissing ? 'v2-manual-missing' : undefined
                            "
                            data-test="run-manual-add"
                        >
                            Add line
                        </button>
                    </form>
                </div>
            </div>
        </V2Dialog>

        <!-- Send, approve, return and lock: the same shared step request as Classic. -->
        <V2Dialog
            :open="asking !== null"
            :title="dialog.title"
            :description="dialog.description"
            :confirm-label="busy ? 'Saving…' : dialog.label"
            :busy="busy"
            :confirm-disabled="returnMissing !== ''"
            :confirm-disabled-reason="returnMissing"
            @update:open="(v: boolean) => !v && (asking = null)"
            @confirm="returnMissing === '' && confirm()"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div v-if="asking === 'return'" class="grid gap-1.5">
                <label id="v2-run-return-label" class="text-sm font-medium"
                    >Why it goes back
                    <span class="text-destructive" aria-hidden="true"
                        >*</span
                    ></label
                >
                <OperationalSelect
                    v-model="returnReason"
                    labelledby="v2-run-return-label"
                    label="Why it goes back"
                    placeholder="Choose a reason"
                    trigger-class="h-11 w-full lg:h-9"
                    :options="run.options.returnReasons"
                    :invalid="!!errors.reason"
                />
                <p
                    v-if="errors.reason"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.reason }}
                </p>
            </div>
        </V2Dialog>
    </PageFrame>
</template>
