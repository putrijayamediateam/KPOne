<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import { OperationalTable } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useLeavePage } from '@/composables/hr/useLeavePage';
import type {
    Balance,
    LeavePageProps,
    RequestRow,
    TypeRow,
} from '@/composables/hr/useLeavePage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Leave (shown only in V2; Classic is `pages/HR/Leave.vue`, untouched). Same props, routes, permissions,
 * lock versions and idempotency keys: every request comes from `useLeavePage`, which Classic uses too. No backend
 * change. PR 1 (Daniel): My leave, Apply, Approvals, the Team calendar and every request dialog are V2. Settings shows
 * the Classic settings inside the V2 frame until PR 2 (V2Tabs: Types · Entitlements · Calendar · Opening balances).
 */
const props = defineProps<LeavePageProps>();

const {
    errors,
    tabs,
    visit,
    branchOptions,
    yearOptions,
    selectLeaveTab,
    applyOpen,
    applyForm,
    chosenType,
    singleDay,
    firstDayOptions,
    lastDayOptions,
    preview,
    openApply,
    sizeLabel,
    chooseApplyFiles,
    dropApplyFile,
    applyFileError,
    sendApply,
    evidence,
    evidenceRow,
    openEvidence,
    fileCountText,
    photoAlt,
    uploadEvidence,
    removal,
    removalNeedsReason,
    openRemoval,
    confirmRemoval,
    removalReasons,
    action,
    actionText,
    actionDisabled,
    openAction,
    confirmAction,
    typeOpen,
    typeForm,
    openType,
    MAX_EVIDENCE_DAYS,
    savedRetentionDays,
    saveType,
    archiveType,
    flags,
    setFlag,
    flagValue,
    yesNo,
    balanceTypes,
    entitlementValues,
    personEntitlements,
    entitlementSaving,
    saveEntitlements,
    personForm,
    savePerson,
    yearAction,
    yearText,
    plural,
    openYear,
    confirmYear,
    weekdays,
    workingDays,
    toggleDay,
    saveWorkingDays,
    holidayForm,
    addHoliday,
    removeHoliday,
    entryForm,
    saveEntry,
    decideEntry,
    entryTone,
    entryLabel,
    peopleOptions,
    balanceTypeOptions,
    typeName,
    calendar,
    branchName,
    calendarDays,
} = useLeavePage(props);

usePageCrumbs(() => [{ title: 'KPOne HR', href: '/hr' }, { title: 'Leave' }]);

const dayWord = (days: string) => (days === '1' ? 'day' : 'days');

// Settings (PR 2, Daniel): four sub-tabs. The chosen one is a per-viewer convenience kept in this browser only, not in
// the URL, so a link still opens the same page; Types when nothing is kept or storage is blocked.
type SettingsTab = 'types' | 'entitlements' | 'calendar' | 'balances';
const settingsTabs: { value: SettingsTab; label: string }[] = [
    { value: 'types', label: 'Types' },
    { value: 'entitlements', label: 'Entitlements' },
    { value: 'calendar', label: 'Calendar' },
    { value: 'balances', label: 'Opening balances' },
];
const SETTINGS_KEY = 'kpone.v2.leave.settings-tab';
const readSettingsTab = (): SettingsTab => {
    try {
        const kept = window.localStorage.getItem(SETTINGS_KEY);

        return settingsTabs.some((t) => t.value === kept)
            ? (kept as SettingsTab)
            : 'types';
    } catch {
        return 'types';
    }
};
const settingsTab = ref<SettingsTab>(
    typeof window === 'undefined' ? 'types' : readSettingsTab(),
);
const chooseSettingsTab = (value: string) => {
    settingsTab.value = value as SettingsTab;

    try {
        window.localStorage.setItem(SETTINGS_KEY, value);
    } catch {
        // Storage blocked: the choice lasts for this visit only.
    }
};
const archiving = reactive<{ open: boolean; type: TypeRow | null }>({
    open: false,
    type: null,
});
const askArchive = (t: TypeRow) => {
    archiving.type = t;
    archiving.open = true;
};
const confirmArchive = () => {
    if (archiving.type) {
        archiveType(archiving.type);
    }

    archiving.open = false;
};
// The type dialog asks before discarding only when something changed since it opened (Suresh): openType fills the
// form field by field, so Inertia's isDirty would count an untouched Edit as changed.
const typeFields = () =>
    JSON.stringify({
        code: typeForm.code,
        name: typeForm.name,
        is_paid: typeForm.is_paid,
        uses_balance: typeForm.uses_balance,
        allow_half_days: typeForm.allow_half_days,
        evidence_needed: typeForm.evidence_needed,
        allow_negative: typeForm.allow_negative,
        accepts_evidence_files: typeForm.accepts_evidence_files,
        evidence_retention_days: typeForm.evidence_retention_days,
        carry_max_days: typeForm.carry_max_days,
        carry_expires_on: typeForm.carry_expires_on,
    });
const typeSnapshot = ref('');
watch(typeOpen, (open) => {
    if (open) {
        void nextTick(() => (typeSnapshot.value = typeFields()));
    } else {
        typeSnapshot.value = '';
    }
});
const typeDirty = computed(
    () =>
        typeOpen.value &&
        typeSnapshot.value !== '' &&
        typeFields() !== typeSnapshot.value,
);
const settingsBranch = computed(() => branchName.value || 'this branch');
const typeMenu = (t: TypeRow): RowMenuGroup[] => [
    {
        label: t.name,
        items: [
            { id: 'edit', label: 'Edit', allowed: true },
            {
                id: 'archive',
                label: t.isActive ? 'Archive' : 'Restore',
                allowed: true,
                tone: t.isActive ? 'danger' : 'default',
            },
        ],
    },
];

// Daniel (a): the big figure is what is left, the line under it the rest. "Booked" is the server's own figure: waiting
// requests plus approved leave that has not started, so it is not called "pending". A type with no yearly balance
// (maternity, paternity) says so instead of a big 0.
const balanceDetail = (b: Balance) =>
    `of ${b.entitled} · ${b.taken} taken · ${b.booked} booked`;

const requestTone = {
    pending: 'warning',
    approved: 'success',
    rejected: 'neutral',
    cancelled: 'neutral',
} as const;

const typeOptions = computed(() =>
    (props.leave.my?.types ?? []).map((t) => ({
        value: String(t.id),
        label: t.name,
    })),
);

// Daniel: "Send request" is faded until the required choices are made, and says what is missing in words.
const applyMissing = computed(() =>
    applyForm.leave_type_id === ''
        ? 'Choose a leave type to continue.'
        : applyForm.start_date === ''
          ? 'Choose the start date to continue.'
          : applyForm.end_date === ''
            ? 'Choose the end date to continue.'
            : '',
);

// The apply sheet's files (Daniel): a file chooser with a spoken state line.
const applyFileStatus = computed(() => {
    const files = applyForm.evidence_files;

    if (files.length === 0) {
        return 'No file chosen. You can add up to 3.';
    }

    return `${files.length} of 3 ${files.length === 1 ? 'file' : 'files'} chosen.`;
});

// The working-days line (Daniel): "Counting…" while the server works, never a number for the old dates.
const applyLine = computed(() => {
    if (preview.counting) {
        return { text: 'Counting…', error: false };
    }

    if (preview.text) {
        return { text: preview.text, error: false };
    }

    if (preview.error && preview.error !== applyForm.errors.end_date) {
        return { text: preview.error, error: true };
    }

    return null;
});

const evidenceFileStatus = computed(() => {
    if (evidence.uploading) {
        return 'Uploading…';
    }

    const files = evidenceRow.value?.evidence?.files ?? [];

    return `${fileCountText(files)} added.`;
});

// One reason chooser for reject and manager cancel; empty at the start (Daniel).
const actionReasons = computed(() =>
    action.kind === 'reject'
        ? props.leave.rejectReasons
        : props.leave.cancelReasons,
);
const actionNeedsReason = computed(
    () => action.kind === 'reject' || action.kind === 'manager-cancel',
);
const actionTarget = computed(() => {
    const row = action.row as (RequestRow & { person?: string }) | null;

    if (!row) {
        return '';
    }

    // A no-break space keeps the number with its unit.
    return `${row.person ? `${row.person} · ` : ''}${row.type} · ${row.dates} · ${row.days}\u00A0${dayWord(row.days)}`;
});
const actionBlockedReason = computed(() =>
    action.kind === 'approve'
        ? 'Tick "Evidence seen" after checking the paper copy to continue.'
        : 'Pick a reason to continue.',
);

// Team calendar (Daniel b): weekends and public holidays are shaded and carry words, not colour alone.
const weekdayNames: Record<string, string> = {
    Mon: 'Monday',
    Tue: 'Tuesday',
    Wed: 'Wednesday',
    Thu: 'Thursday',
    Fri: 'Friday',
    Sat: 'Saturday',
    Sun: 'Sunday',
};
const dayMark = (day: {
    holiday: string | null;
    working: boolean;
    weekday: string;
}) => (day.holiday ? 'Hol' : day.working ? '' : day.weekday);
const dayWords = (day: {
    holiday: string | null;
    working: boolean;
    weekday: string;
}) =>
    day.holiday
        ? `Public holiday: ${day.holiday}`
        : day.working
          ? ''
          : (weekdayNames[day.weekday] ?? day.weekday);
// Daniel: every mark has a spoken name, per person and day: "On leave" or "Half day leave", never the type or reason.
const spokenCell = (name: string, label: string, cell: 'full' | 'half') =>
    `${name}, ${label}: ${cell === 'half' ? 'Half day leave' : 'On leave'}`;
const shaded = (day: { holiday: string | null; working: boolean }) =>
    day.holiday !== null || !day.working;

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const textLink =
    'inline-flex min-h-11 items-center text-sm font-medium text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
</script>

<template>
    <Head title="Leave" />
    <PageFrame>
        <V2WorkLayout
            title="Leave"
            description="Apply for leave and see your balance. Someone else approves each request."
        >
            <template
                v-if="leave.tab === 'my' && leave.my && leave.setUp"
                #actions
            >
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="apply-leave"
                    @click="openApply"
                >
                    Apply for leave
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="leave.tab"
                        :tabs="tabs"
                        ariaLabel="Leave sections"
                        @update:model-value="selectLeaveTab"
                    />

                    <p
                        v-if="errors.leave && !action.open && !removal.open"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.leave }}
                    </p>

                    <!-- My leave -->
                    <section
                        v-if="leave.tab === 'my' && leave.my"
                        aria-labelledby="v2-my-leave"
                        class="flex flex-col gap-4"
                    >
                        <h2 id="v2-my-leave" class="sr-only">My leave</h2>
                        <div
                            v-if="!leave.setUp"
                            class="rounded-[14px] border border-dashed bg-card p-4 text-sm"
                            data-test="not-set-up"
                        >
                            <p class="font-medium">Leave is not set up yet.</p>
                            <p class="text-muted-foreground">
                                Ask HR to add the leave types for the clinic.
                            </p>
                        </div>
                        <template v-else>
                            <div class="flex flex-col gap-1">
                                <h3 class="text-base font-semibold">
                                    Balance for {{ leave.today.slice(0, 4) }}
                                </h3>
                                <p
                                    class="text-[13px] text-muted-foreground"
                                    data-test="booked-note"
                                >
                                    Booked: waiting for approval, or approved
                                    and not yet started.
                                </p>
                            </div>
                            <ul
                                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                                data-test="balances"
                            >
                                <li
                                    v-for="b in leave.my.balances"
                                    :key="b.typeId"
                                    class="flex flex-col gap-1 rounded-[14px] border bg-card p-4"
                                    data-test="balance"
                                >
                                    <h4 class="text-sm font-medium">
                                        {{ b.name }}
                                    </h4>
                                    <p
                                        v-if="!b.counted"
                                        class="text-sm text-muted-foreground"
                                        data-test="no-balance"
                                    >
                                        No yearly balance
                                    </p>
                                    <template v-else>
                                        <p
                                            class="text-2xl font-semibold tabular-nums"
                                        >
                                            {{ b.left }}
                                            {{ dayWord(b.left) }} left
                                        </p>
                                        <p
                                            class="text-[13px] text-muted-foreground tabular-nums"
                                        >
                                            {{ balanceDetail(b) }}
                                        </p>
                                    </template>
                                </li>
                            </ul>

                            <h3 class="text-base font-semibold">My requests</h3>
                            <V2Table
                                label="My leave requests"
                                :columns="6"
                                cards="lg"
                                min-width="760px"
                                :empty="leave.my.requests.length === 0"
                                empty-message="No leave requests yet. Apply for leave to see it here."
                            >
                                <template #head>
                                    <tr>
                                        <th scope="col">Dates</th>
                                        <th scope="col">Type</th>
                                        <th scope="col">Days</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Evidence</th>
                                        <th scope="col">
                                            <span class="sr-only">Action</span>
                                        </th>
                                    </tr>
                                </template>
                                <template #body>
                                    <tr
                                        v-for="r in leave.my.requests"
                                        :key="r.publicId"
                                        data-test="my-request"
                                    >
                                        <td data-primary data-label="Dates">
                                            <span class="font-medium">{{
                                                r.dates
                                            }}</span>
                                        </td>
                                        <td data-label="Type">{{ r.type }}</td>
                                        <td
                                            data-label="Days"
                                            class="whitespace-nowrap tabular-nums"
                                        >
                                            {{ r.days }} {{ dayWord(r.days) }}
                                        </td>
                                        <td data-label="Status">
                                            <V2Chip
                                                :tone="requestTone[r.status]"
                                                :label="r.statusLabel"
                                            />
                                        </td>
                                        <td data-label="Evidence">
                                            <span
                                                v-if="r.evidence"
                                                class="flex flex-wrap items-center gap-2"
                                                data-test="evidence-cell"
                                            >
                                                <span
                                                    class="text-muted-foreground"
                                                    >{{
                                                        fileCountText(
                                                            r.evidence.files,
                                                        )
                                                    }}</span
                                                >
                                                <button
                                                    v-if="
                                                        r.evidence.files
                                                            .length > 0 ||
                                                        r.evidence.canAdd
                                                    "
                                                    type="button"
                                                    :class="textLink"
                                                    @click="openEvidence(r)"
                                                >
                                                    {{
                                                        r.evidence.files
                                                            .length > 0
                                                            ? 'View'
                                                            : 'Add file'
                                                    }}<span class="sr-only">
                                                        for {{ r.type }},
                                                        {{ r.dates }}</span
                                                    >
                                                </button>
                                            </span>
                                            <span
                                                v-else
                                                class="text-muted-foreground"
                                                >—</span
                                            >
                                        </td>
                                        <td class="text-right">
                                            <button
                                                v-if="r.canCancel"
                                                type="button"
                                                :class="button"
                                                @click="openAction('cancel', r)"
                                            >
                                                Cancel request<span
                                                    class="sr-only"
                                                >
                                                    for {{ r.dates }}</span
                                                >
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </V2Table>
                        </template>
                    </section>

                    <!-- Approvals -->
                    <section
                        v-if="leave.tab === 'approvals' && leave.approvals"
                        aria-labelledby="v2-approvals"
                        class="flex max-w-[56rem] flex-col gap-4"
                    >
                        <h2 id="v2-approvals" class="text-base font-semibold">
                            Waiting for you
                        </h2>
                        <p
                            v-if="leave.approvals.pending.length === 0"
                            class="rounded-[14px] border border-dashed bg-card p-4 text-sm text-muted-foreground"
                        >
                            Nothing waiting for you. Your own requests go to
                            someone else.
                        </p>
                        <ul
                            v-else
                            class="flex flex-col gap-3"
                            data-test="pending-list"
                        >
                            <li
                                v-for="r in leave.approvals.pending"
                                :key="r.publicId"
                                class="flex flex-col gap-3 rounded-[14px] border bg-card p-4 lg:flex-row lg:items-start lg:justify-between"
                                data-test="pending-card"
                            >
                                <div
                                    class="flex min-w-0 flex-col gap-1 text-sm"
                                >
                                    <p class="font-medium">
                                        {{ r.person }}
                                        <span
                                            class="font-normal text-muted-foreground"
                                            >· {{ r.role }}</span
                                        >
                                    </p>
                                    <p>
                                        {{ r.type }} · {{ r.dates }} ·
                                        <span
                                            class="whitespace-nowrap tabular-nums"
                                            >{{ r.days }}
                                            {{ dayWord(r.days) }}</span
                                        >
                                    </p>
                                    <p
                                        v-if="r.leftAfter !== null"
                                        class="font-medium tabular-nums"
                                        data-test="left-after"
                                    >
                                        Left after approval: {{ r.leftAfter }}
                                        {{ dayWord(r.leftAfter) }}
                                    </p>
                                    <p
                                        v-if="r.reason"
                                        class="break-words text-muted-foreground"
                                    >
                                        Reason: {{ r.reason }}
                                    </p>
                                    <p
                                        v-if="r.evidenceReference"
                                        class="break-words text-muted-foreground"
                                    >
                                        Evidence reference:
                                        {{ r.evidenceReference }}
                                    </p>
                                    <p
                                        v-if="r.evidence"
                                        class="flex flex-wrap items-center gap-2"
                                        data-test="evidence-line"
                                    >
                                        <span class="text-muted-foreground"
                                            >Evidence:
                                            {{
                                                fileCountText(r.evidence.files)
                                            }}</span
                                        >
                                        <button
                                            v-if="
                                                r.evidence.files.length > 0 ||
                                                r.evidence.canAdd
                                            "
                                            type="button"
                                            :class="textLink"
                                            @click="openEvidence(r)"
                                        >
                                            {{
                                                r.evidence.files.length > 0
                                                    ? 'View'
                                                    : 'Add file'
                                            }}<span class="sr-only">
                                                for {{ r.person }},
                                                {{ r.dates }}</span
                                            >
                                        </button>
                                    </p>
                                    <ul
                                        v-if="r.warnings.length > 0"
                                        class="mt-1 flex flex-col gap-1"
                                    >
                                        <li
                                            v-for="w in r.warnings"
                                            :key="w"
                                            class="border-l-4 border-l-warning pl-2 text-[13px]"
                                        >
                                            {{ w }}
                                        </li>
                                    </ul>
                                </div>
                                <div
                                    class="flex shrink-0 flex-col gap-2 sm:flex-row"
                                >
                                    <button
                                        type="button"
                                        :class="button"
                                        @click="openAction('approve', r)"
                                    >
                                        Approve<span class="sr-only">
                                            leave for {{ r.person }}</span
                                        >
                                    </button>
                                    <button
                                        type="button"
                                        :class="button"
                                        @click="openAction('reject', r)"
                                    >
                                        Reject<span class="sr-only">
                                            leave for {{ r.person }}</span
                                        >
                                    </button>
                                </div>
                            </li>
                        </ul>

                        <h2 class="text-base font-semibold">Approved leave</h2>
                        <p
                            v-if="leave.approvals.approved.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No approved leave in the last 30 days or ahead.
                        </p>
                        <ul
                            v-else
                            class="flex flex-col divide-y rounded-[14px] border bg-card"
                        >
                            <li
                                v-for="r in leave.approvals.approved"
                                :key="r.publicId"
                                class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                            >
                                <span class="min-w-0"
                                    ><span class="font-medium">{{
                                        r.person
                                    }}</span>
                                    · {{ r.type }} · {{ r.dates }} ·
                                    <span class="whitespace-nowrap tabular-nums"
                                        >{{ r.days }}
                                        {{ dayWord(r.days) }}</span
                                    ></span
                                >
                                <span class="flex flex-wrap items-center gap-2">
                                    <button
                                        v-if="r.evidence"
                                        type="button"
                                        :class="textLink"
                                        @click="openEvidence(r)"
                                    >
                                        Evidence ({{
                                            r.evidence.files.length
                                        }})<span class="sr-only">
                                            for {{ r.person }},
                                            {{ r.dates }}</span
                                        >
                                    </button>
                                    <button
                                        type="button"
                                        :class="button"
                                        @click="openAction('manager-cancel', r)"
                                    >
                                        Cancel approved leave<span
                                            class="sr-only"
                                        >
                                            for {{ r.person }}</span
                                        >
                                    </button>
                                </span>
                            </li>
                        </ul>
                    </section>

                    <!-- Team calendar -->
                    <section
                        v-if="leave.tab === 'calendar' && calendar"
                        aria-labelledby="v2-calendar"
                        class="flex flex-col gap-3"
                    >
                        <h2 id="v2-calendar" class="sr-only">Team calendar</h2>
                        <div class="flex flex-wrap items-end gap-3">
                            <V2Field
                                v-slot="{ id }"
                                label="Branch"
                                class="w-full sm:w-56"
                            >
                                <OperationalSelect
                                    :id="id"
                                    :model-value="leave.branchId ?? ''"
                                    :options="branchOptions"
                                    label="Branch"
                                    @update:model-value="
                                        (v: string | number) =>
                                            visit({
                                                branch: v,
                                                month: calendar?.month,
                                            })
                                    "
                                />
                            </V2Field>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :class="button"
                                    @click="visit({ month: calendar.previous })"
                                >
                                    Previous month
                                </button>
                                <span
                                    class="min-w-28 text-center text-sm font-medium"
                                    data-test="month-label"
                                    >{{ calendar.monthLabel }}</span
                                >
                                <button
                                    type="button"
                                    :class="button"
                                    @click="visit({ month: calendar.next })"
                                >
                                    Next month
                                </button>
                            </div>
                        </div>
                        <p
                            v-if="!calendar.workingDaysSet"
                            class="text-sm text-muted-foreground"
                        >
                            Working days are not set up for this branch yet.
                        </p>
                        <div class="hidden lg:block">
                            <OperationalTable
                                :label="`Leave for ${branchName}, ${calendar.monthLabel}`"
                                :columns="calendar.days.length + 1"
                                :empty="calendar.rows.length === 0"
                                empty-message="Nobody is on approved leave this month."
                                min-width="1200px"
                            >
                                <template #head>
                                    <tr>
                                        <th
                                            scope="col"
                                            class="sticky left-0 z-10 w-40 bg-card"
                                        >
                                            Staff
                                        </th>
                                        <th
                                            v-for="day in calendar.days"
                                            :key="day.date"
                                            scope="col"
                                            class="relative px-1 text-center text-xs"
                                            :class="
                                                shaded(day)
                                                    ? 'bg-muted text-muted-foreground'
                                                    : ''
                                            "
                                            data-test="calendar-day"
                                        >
                                            <!-- Daniel: a working day reads "M / 2"; a shaded day "28 / Sat" or "9 / Hol". -->
                                            <span
                                                v-if="!dayMark(day)"
                                                class="block"
                                                aria-hidden="true"
                                                >{{ day.weekday[0] }}</span
                                            >
                                            <span class="block tabular-nums">{{
                                                day.day
                                            }}</span>
                                            <span
                                                v-if="dayMark(day)"
                                                class="block text-[11px] font-medium"
                                                aria-hidden="true"
                                                data-test="day-mark"
                                                >{{ dayMark(day) }}</span
                                            >
                                            <span
                                                v-if="dayWords(day)"
                                                class="sr-only"
                                                >{{ dayWords(day) }}</span
                                            >
                                        </th>
                                    </tr>
                                </template>
                                <template #body>
                                    <tr
                                        v-for="(row, i) in calendar.rows"
                                        :key="`${i}-${row.name}`"
                                    >
                                        <th
                                            scope="row"
                                            class="sticky left-0 z-10 bg-card font-medium"
                                        >
                                            {{ row.name }}
                                        </th>
                                        <td
                                            v-for="day in calendar.days"
                                            :key="day.date"
                                            class="relative px-1 text-center text-xs"
                                            :class="
                                                shaded(day) ? 'bg-muted' : ''
                                            "
                                        >
                                            <span
                                                v-if="row.cells[day.date]"
                                                class="inline-block rounded border bg-card px-1"
                                                ><span aria-hidden="true">{{
                                                    row.cells[day.date] ===
                                                    'half'
                                                        ? '½'
                                                        : '•'
                                                }}</span
                                                ><span
                                                    class="sr-only"
                                                    data-test="cell-name"
                                                    >{{
                                                        spokenCell(
                                                            row.name,
                                                            day.label,
                                                            row.cells[day.date],
                                                        )
                                                    }}</span
                                                ></span
                                            >
                                        </td>
                                    </tr>
                                </template>
                            </OperationalTable>
                            <p class="mt-2 text-[13px] text-muted-foreground">
                                • On leave · ½ Half day · shaded days are not
                                working days: "Hol" marks a public holiday.
                            </p>
                        </div>
                        <ul
                            class="flex flex-col gap-2 lg:hidden"
                            data-test="calendar-days"
                        >
                            <li
                                v-if="calendarDays.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                Nobody is on approved leave this month.
                            </li>
                            <li
                                v-for="day in calendarDays"
                                :key="day.date"
                                class="rounded-[14px] border bg-card p-3 text-sm"
                            >
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{
                                        day.label
                                    }}</span>
                                    <V2Chip
                                        v-if="day.holiday"
                                        tone="neutral"
                                        label="Public holiday"
                                    />
                                </p>
                                <p
                                    v-if="day.holiday"
                                    class="text-[13px] text-muted-foreground"
                                >
                                    {{ day.holiday }}
                                </p>
                                <p v-if="day.people.length > 0" class="mt-1">
                                    {{ day.people.join(', ') }}
                                </p>
                            </li>
                        </ul>
                    </section>

                    <!-- Settings (PR 2, Daniel): four sub-tabs, Types · Entitlements · Calendar · Opening balances. -->
                    <section
                        v-if="leave.tab === 'settings' && leave.settings"
                        aria-labelledby="settings-title"
                        class="flex flex-col gap-4"
                    >
                        <h2 id="settings-title" class="sr-only">
                            Leave settings
                        </h2>
                        <div
                            class="flex flex-wrap items-end gap-3"
                            data-test="settings-filters"
                        >
                            <V2Field label="Year" class="w-full sm:w-40">
                                <template #default="{ id, describedBy }">
                                    <OperationalSelect
                                        :id="id"
                                        :describedby="describedBy"
                                        :model-value="leave.year"
                                        :options="yearOptions"
                                        label="Year"
                                        @update:model-value="
                                            (v: string | number) =>
                                                visit({ year: v })
                                        "
                                    />
                                </template>
                            </V2Field>
                        </div>

                        <V2Tabs
                            :model-value="settingsTab"
                            :tabs="settingsTabs"
                            ariaLabel="Leave settings sections"
                            id-prefix="leave-settings"
                            data-test="settings-tabs"
                            @update:model-value="chooseSettingsTab"
                        />

                        <!-- Types -->
                        <div
                            v-if="settingsTab === 'types'"
                            id="leave-settings-panel-types"
                            role="tabpanel"
                            aria-labelledby="leave-settings-tab-types"
                            class="flex flex-col gap-3"
                            data-test="types-card"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold">
                                        Leave types
                                    </h2>
                                    <p class="text-sm text-muted-foreground">
                                        Add the types the clinic uses, or start
                                        from the KPOne defaults.
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        class="min-h-11 lg:min-h-9"
                                        data-test="use-defaults"
                                        @click="openYear('defaults')"
                                        >Use KPOne defaults</Button
                                    >
                                    <Button
                                        class="min-h-11 lg:min-h-9"
                                        data-test="add-type"
                                        @click="openType()"
                                        >Add leave type</Button
                                    >
                                </div>
                            </div>
                            <p
                                v-if="
                                    leave.settings.types.some(
                                        (t) => t.setByDefaults,
                                    )
                                "
                                class="text-sm text-muted-foreground"
                                data-test="defaults-label"
                            >
                                {{ leave.settings.defaults.label }}
                            </p>
                            <V2Table
                                label="Leave types"
                                :columns="7"
                                cards="lg"
                                min-width="760px"
                                :empty="leave.settings.types.length === 0"
                                empty-message="No leave types yet."
                            >
                                <template #head>
                                    <tr>
                                        <th scope="col">Type</th>
                                        <th scope="col">Paid</th>
                                        <th scope="col">Balance</th>
                                        <th scope="col">Half days</th>
                                        <th scope="col">Evidence</th>
                                        <th scope="col" class="text-right">
                                            Carry up to
                                        </th>
                                        <th scope="col">
                                            <span class="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </template>
                                <template #body>
                                    <tr
                                        v-for="t in leave.settings.types"
                                        :key="t.id"
                                        data-test="type-row"
                                    >
                                        <td data-label="Type">
                                            <div class="flex flex-col gap-1">
                                                <span class="font-medium"
                                                    >{{ t.name }}
                                                    <span
                                                        class="text-sm font-normal text-muted-foreground"
                                                        >· {{ t.code }}</span
                                                    ></span
                                                >
                                                <span
                                                    v-if="
                                                        !t.isActive ||
                                                        t.setByDefaults
                                                    "
                                                    class="flex flex-wrap gap-1"
                                                >
                                                    <V2Chip
                                                        v-if="!t.isActive"
                                                        tone="neutral"
                                                        label="Archived"
                                                    />
                                                    <V2Chip
                                                        v-if="t.setByDefaults"
                                                        tone="neutral"
                                                        label="KPOne default"
                                                        plain
                                                    />
                                                </span>
                                            </div>
                                        </td>
                                        <td data-label="Paid">
                                            {{ yesNo(t.isPaid) }}
                                        </td>
                                        <td data-label="Balance">
                                            {{ yesNo(t.usesBalance) }}
                                        </td>
                                        <td data-label="Half days">
                                            {{ yesNo(t.allowHalfDays) }}
                                        </td>
                                        <td data-label="Evidence">
                                            {{ yesNo(t.evidenceNeeded) }}
                                        </td>
                                        <td
                                            data-label="Carry up to"
                                            class="text-right"
                                        >
                                            {{
                                                t.carryMaxDays
                                                    ? `${t.carryMaxDays} days`
                                                    : '—'
                                            }}
                                        </td>
                                        <td data-actions class="text-right">
                                            <V2RowMenu
                                                :label="`Actions for ${t.name}`"
                                                :groups="typeMenu(t)"
                                                @select="
                                                    (id: string) =>
                                                        id === 'edit'
                                                            ? openType(t)
                                                            : askArchive(t)
                                                "
                                            />
                                        </td>
                                    </tr>
                                </template>
                            </V2Table>
                        </div>

                        <!-- Entitlements -->
                        <div
                            v-if="settingsTab === 'entitlements'"
                            id="leave-settings-panel-entitlements"
                            role="tabpanel"
                            aria-labelledby="leave-settings-tab-entitlements"
                            class="flex flex-col gap-4"
                            data-test="entitlements-card"
                        >
                            <V2FormCard
                                :title="`Entitlements for ${leave.year}`"
                                description="Days per year by role, in steps of 0.5. A person row wins over the role. Nothing reaches a balance until you give the entitlement."
                            >
                                <p
                                    v-if="
                                        leave.settings.entitlements.some(
                                            (e) => e.setByDefaults,
                                        )
                                    "
                                    class="text-sm text-muted-foreground"
                                    data-test="defaults-entitlement-label"
                                >
                                    Some figures here come from KPOne defaults:
                                    {{ leave.settings.defaults.label }}
                                </p>
                                <p
                                    v-if="errors.entitlements"
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.entitlements }}
                                </p>
                                <p
                                    v-if="balanceTypes.length === 0"
                                    class="text-sm text-muted-foreground"
                                >
                                    Add a leave type that counts against a
                                    balance first.
                                </p>
                                <template v-else>
                                    <div
                                        class="hidden overflow-x-auto md:block"
                                    >
                                        <table
                                            class="w-full min-w-[560px] text-sm"
                                        >
                                            <caption class="sr-only">
                                                Days per year by role and leave
                                                type
                                            </caption>
                                            <thead>
                                                <tr
                                                    class="text-left text-[13px] text-muted-foreground"
                                                >
                                                    <th
                                                        scope="col"
                                                        class="py-2 pr-2 font-medium"
                                                    >
                                                        Role
                                                    </th>
                                                    <th
                                                        v-for="t in balanceTypes"
                                                        :key="t.id"
                                                        scope="col"
                                                        class="py-2 pr-2 font-medium"
                                                    >
                                                        {{ t.name }}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="role in leave
                                                        .settings.roles"
                                                    :key="role.value"
                                                    class="border-t border-border/70"
                                                >
                                                    <th
                                                        scope="row"
                                                        class="py-2 pr-2 text-left font-normal"
                                                    >
                                                        {{ role.label }}
                                                    </th>
                                                    <td
                                                        v-for="t in balanceTypes"
                                                        :key="t.id"
                                                        class="py-2 pr-2"
                                                    >
                                                        <Input
                                                            v-model="
                                                                entitlementValues[
                                                                    `${t.id}:${role.value}`
                                                                ]
                                                            "
                                                            inputmode="decimal"
                                                            class="h-11 w-20 tabular-nums lg:h-9"
                                                            :aria-label="`${t.name} days for ${role.label}`"
                                                        />
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Below 768 px: one card per role, one labelled field per leave type (Daniel). -->
                                    <ul
                                        class="flex flex-col gap-3 md:hidden"
                                        data-test="entitlement-cards"
                                    >
                                        <li
                                            v-for="role in leave.settings.roles"
                                            :key="role.value"
                                            class="rounded-lg border p-3"
                                        >
                                            <h4 class="text-sm font-medium">
                                                {{ role.label }}
                                            </h4>
                                            <div
                                                class="mt-2 grid grid-cols-2 gap-3"
                                            >
                                                <div
                                                    v-for="t in balanceTypes"
                                                    :key="t.id"
                                                    class="grid gap-1"
                                                >
                                                    <Label
                                                        :for="`ent-${t.id}-${role.value}`"
                                                        class="text-xs"
                                                        ><span class="sr-only"
                                                            >{{
                                                                role.label
                                                            }}, </span
                                                        >{{
                                                            t.name
                                                        }}
                                                        days</Label
                                                    >
                                                    <Input
                                                        :id="`ent-${t.id}-${role.value}`"
                                                        v-model="
                                                            entitlementValues[
                                                                `${t.id}:${role.value}`
                                                            ]
                                                        "
                                                        inputmode="decimal"
                                                        class="h-11 tabular-nums"
                                                    />
                                                </div>
                                            </div>
                                        </li>
                                    </ul>
                                    <InputError
                                        v-for="(message, key) in errors"
                                        v-show="String(key).startsWith('rows.')"
                                        :key="key"
                                        :message="message"
                                    />
                                    <div class="flex justify-end">
                                        <Button
                                            class="min-h-11 w-full sm:w-fit lg:min-h-9"
                                            :disabled="entitlementSaving"
                                            data-test="save-entitlements"
                                            @click="saveEntitlements"
                                            >Save entitlements</Button
                                        >
                                    </div>
                                </template>
                            </V2FormCard>

                            <V2FormCard
                                v-if="balanceTypes.length > 0"
                                :title="`The ${leave.year} year`"
                                description="Each of these runs only when you press it, never on a schedule."
                            >
                                <div
                                    class="flex flex-col gap-2 sm:flex-row sm:flex-wrap"
                                    data-test="year-actions"
                                >
                                    <Button
                                        variant="outline"
                                        class="min-h-11 lg:min-h-9"
                                        data-test="grant"
                                        @click="openYear('grant')"
                                        >Give this year's entitlement</Button
                                    >
                                    <Button
                                        variant="outline"
                                        class="min-h-11 lg:min-h-9"
                                        @click="openYear('carry-forward')"
                                        >Carry forward from
                                        {{ leave.year }}</Button
                                    >
                                    <Button
                                        variant="outline"
                                        class="min-h-11 lg:min-h-9"
                                        @click="openYear('expire')"
                                        >Expire carried days</Button
                                    >
                                </div>
                                <InputError :message="errors.year" />
                            </V2FormCard>

                            <V2FormCard
                                v-if="balanceTypes.length > 0"
                                title="Set for one person"
                                description="A person's figure wins over their role's for that leave type."
                            >
                                <ul
                                    v-if="personEntitlements.length > 0"
                                    class="flex flex-col divide-y text-sm"
                                    data-test="person-entitlements"
                                >
                                    <li
                                        v-for="e in personEntitlements"
                                        :key="`${e.leaveTypeId}:${e.userId}`"
                                        class="flex flex-wrap items-center gap-2 py-2"
                                    >
                                        <span class="font-medium">{{
                                            e.person
                                        }}</span>
                                        <span class="text-muted-foreground"
                                            >{{ typeName(e.leaveTypeId) }} ·
                                            <span class="tabular-nums">{{
                                                e.days
                                            }}</span>
                                            days</span
                                        >
                                        <V2Chip
                                            v-if="e.setByDefaults"
                                            tone="neutral"
                                            label="KPOne default"
                                            plain
                                        />
                                    </li>
                                </ul>
                                <form
                                    class="grid gap-3 sm:grid-cols-[1fr_1fr_6rem_auto] sm:items-end"
                                    @submit.prevent="savePerson"
                                >
                                    <V2Field label="Person">
                                        <template
                                            #default="{ id, describedBy }"
                                        >
                                            <OperationalSelect
                                                :id="id"
                                                :describedby="describedBy"
                                                v-model="personForm.user_id"
                                                :options="peopleOptions"
                                                label="Person"
                                                placeholder="Choose a person"
                                            />
                                        </template>
                                    </V2Field>
                                    <V2Field label="Leave type">
                                        <template
                                            #default="{ id, describedBy }"
                                        >
                                            <OperationalSelect
                                                :id="id"
                                                :describedby="describedBy"
                                                v-model="
                                                    personForm.leave_type_id
                                                "
                                                :options="balanceTypeOptions"
                                                label="Leave type"
                                                placeholder="Choose a type"
                                            />
                                        </template>
                                    </V2Field>
                                    <V2Field label="Days">
                                        <template
                                            #default="{ id, describedBy }"
                                        >
                                            <Input
                                                :id="id"
                                                :aria-describedby="describedBy"
                                                v-model="personForm.days"
                                                inputmode="decimal"
                                                class="h-11 tabular-nums lg:h-9"
                                            />
                                        </template>
                                    </V2Field>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        class="min-h-11 lg:min-h-9"
                                        :disabled="personForm.processing"
                                        >Save for person</Button
                                    >
                                </form>
                            </V2FormCard>
                        </div>

                        <!-- Calendar: working days and public holidays for the chosen branch -->
                        <div
                            v-if="settingsTab === 'calendar'"
                            id="leave-settings-panel-calendar"
                            role="tabpanel"
                            aria-labelledby="leave-settings-tab-calendar"
                            class="flex flex-col gap-4"
                        >
                            <!-- Branch filters only the calendar (Daniel), so it lives here, not above the sub-tabs. -->
                            <V2Field label="Branch" class="w-full sm:w-56">
                                <template #default="{ id, describedBy }">
                                    <OperationalSelect
                                        :id="id"
                                        :describedby="describedBy"
                                        :model-value="leave.branchId ?? ''"
                                        :options="branchOptions"
                                        label="Branch"
                                        @update:model-value="
                                            (v: string | number) =>
                                                visit({ branch: v })
                                        "
                                    />
                                </template>
                            </V2Field>
                            <V2FormCard
                                :title="`Working days · ${settingsBranch}`"
                                description="Leave counts only these weekdays. Requests already sent keep their day count."
                                data-test="working-days-card"
                            >
                                <fieldset>
                                    <legend class="sr-only">
                                        Working weekdays
                                    </legend>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="d in weekdays"
                                            :key="d.value"
                                            type="button"
                                            role="checkbox"
                                            :aria-checked="
                                                workingDays.includes(d.value)
                                            "
                                            class="inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
                                            :class="
                                                workingDays.includes(d.value)
                                                    ? 'border-foreground bg-foreground text-background'
                                                    : 'bg-card text-foreground'
                                            "
                                            :data-test="`weekday-${d.value}`"
                                            @click="
                                                toggleDay(
                                                    d.value,
                                                    !workingDays.includes(
                                                        d.value,
                                                    ),
                                                )
                                            "
                                        >
                                            <Check
                                                v-if="
                                                    workingDays.includes(
                                                        d.value,
                                                    )
                                                "
                                                class="size-4"
                                                aria-hidden="true"
                                            />{{ d.label }}
                                        </button>
                                    </div>
                                </fieldset>
                                <InputError :message="errors.weekdays" />
                                <div class="flex justify-end">
                                    <Button
                                        class="min-h-11 w-full sm:w-fit lg:min-h-9"
                                        data-test="save-working-days"
                                        @click="saveWorkingDays"
                                        >Save working days</Button
                                    >
                                </div>
                            </V2FormCard>

                            <V2FormCard
                                :title="`Public holidays ${leave.year} · ${settingsBranch}`"
                                description="KPOne ships no holiday list. Add each one for this branch."
                                data-test="holidays-card"
                            >
                                <form
                                    class="grid gap-3 sm:grid-cols-[12rem_1fr_auto] sm:items-end"
                                    @submit.prevent="addHoliday"
                                >
                                    <V2Field
                                        label="Date"
                                        :error="holidayForm.errors.holiday_date"
                                    >
                                        <template
                                            #default="{ id, describedBy }"
                                        >
                                            <DateField
                                                :id="id"
                                                :aria-describedby="describedBy"
                                                v-model="
                                                    holidayForm.holiday_date
                                                "
                                            />
                                        </template>
                                    </V2Field>
                                    <V2Field
                                        label="Name"
                                        :error="holidayForm.errors.name"
                                    >
                                        <template
                                            #default="{ id, describedBy }"
                                        >
                                            <Input
                                                :id="id"
                                                :aria-describedby="describedBy"
                                                v-model="holidayForm.name"
                                                maxlength="80"
                                                class="h-11 lg:h-9"
                                            />
                                        </template>
                                    </V2Field>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        class="min-h-11 lg:min-h-9"
                                        :disabled="holidayForm.processing"
                                        >Add holiday</Button
                                    >
                                </form>
                                <InputError :message="errors.holiday" />
                                <V2Table
                                    label="Public holidays"
                                    :columns="3"
                                    cards="lg"
                                    min-width="480px"
                                    dense
                                    :empty="
                                        leave.settings.holidays.length === 0
                                    "
                                    :empty-message="`No public holidays for ${leave.year} yet.`"
                                >
                                    <template #head>
                                        <tr>
                                            <th scope="col">Date</th>
                                            <th scope="col">Name</th>
                                            <th scope="col">
                                                <span class="sr-only"
                                                    >Actions</span
                                                >
                                            </th>
                                        </tr>
                                    </template>
                                    <template #body>
                                        <tr
                                            v-for="h in leave.settings.holidays"
                                            :key="h.id"
                                            data-test="holiday-row"
                                        >
                                            <td
                                                data-label="Date"
                                                class="tabular-nums"
                                            >
                                                {{ h.label }}
                                            </td>
                                            <td data-label="Name">
                                                {{ h.name }}
                                            </td>
                                            <td data-actions class="text-right">
                                                <V2RowMenu
                                                    :label="`Actions for ${h.name}, ${h.label}`"
                                                    :groups="[
                                                        {
                                                            label: 'Holiday',
                                                            items: [
                                                                {
                                                                    id: 'remove',
                                                                    label: 'Remove',
                                                                    allowed: true,
                                                                    tone: 'danger',
                                                                },
                                                            ],
                                                        },
                                                    ]"
                                                    @select="
                                                        removeHoliday(h.id)
                                                    "
                                                />
                                            </td>
                                        </tr>
                                    </template>
                                </V2Table>
                            </V2FormCard>
                        </div>

                        <!-- Opening balances and adjustments -->
                        <div
                            v-if="settingsTab === 'balances'"
                            id="leave-settings-panel-balances"
                            role="tabpanel"
                            aria-labelledby="leave-settings-tab-balances"
                            class="flex flex-col gap-4"
                            data-test="entries-card"
                        >
                            <V2FormCard
                                title="Opening balances and adjustments"
                                description="Another manager checks each one before it counts."
                            >
                                <form
                                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                                    @submit.prevent="saveEntry"
                                >
                                    <V2Field label="Entry">
                                        <template
                                            #default="{ id, describedBy }"
                                        >
                                            <OperationalSelect
                                                :id="id"
                                                :describedby="describedBy"
                                                v-model="entryForm.kind"
                                                :options="[
                                                    {
                                                        value: 'opening',
                                                        label: 'Opening balance',
                                                    },
                                                    {
                                                        value: 'adjustment',
                                                        label: 'Adjustment',
                                                    },
                                                ]"
                                                label="Entry"
                                            />
                                        </template>
                                    </V2Field>
                                    <V2Field
                                        label="Person"
                                        required
                                        :error="entryForm.errors.user_id"
                                    >
                                        <template
                                            #default="{
                                                id,
                                                invalid,
                                                describedBy,
                                            }"
                                        >
                                            <OperationalSelect
                                                :id="id"
                                                :describedby="describedBy"
                                                v-model="entryForm.user_id"
                                                :options="peopleOptions"
                                                label="Person"
                                                placeholder="Choose a person"
                                                :invalid="invalid"
                                            />
                                        </template>
                                    </V2Field>
                                    <V2Field
                                        label="Leave type"
                                        required
                                        :error="entryForm.errors.leave_type_id"
                                    >
                                        <template
                                            #default="{
                                                id,
                                                invalid,
                                                describedBy,
                                            }"
                                        >
                                            <OperationalSelect
                                                :id="id"
                                                :describedby="describedBy"
                                                v-model="
                                                    entryForm.leave_type_id
                                                "
                                                :options="balanceTypeOptions"
                                                label="Leave type"
                                                placeholder="Choose a type"
                                                :invalid="invalid"
                                            />
                                        </template>
                                    </V2Field>
                                    <V2Field
                                        label="Days"
                                        required
                                        :hint="
                                            entryForm.kind === 'opening'
                                                ? 'Days left on the day the clinic starts using KPOne leave.'
                                                : 'Use a minus sign to remove days, for example -1.'
                                        "
                                        :error="entryForm.errors.days"
                                    >
                                        <template
                                            #default="{ id, describedBy }"
                                        >
                                            <Input
                                                :id="id"
                                                v-model="entryForm.days"
                                                inputmode="decimal"
                                                class="h-11 tabular-nums lg:h-9"
                                                :aria-describedby="describedBy"
                                            />
                                        </template>
                                    </V2Field>
                                    <template
                                        v-if="entryForm.kind === 'adjustment'"
                                    >
                                        <V2Field
                                            label="Reason"
                                            required
                                            :error="
                                                entryForm.errors.reason_code
                                            "
                                        >
                                            <template
                                                #default="{
                                                    id,
                                                    invalid,
                                                    describedBy,
                                                }"
                                            >
                                                <OperationalSelect
                                                    :id="id"
                                                    :aria-describedby="
                                                        describedBy
                                                    "
                                                    v-model="
                                                        entryForm.reason_code
                                                    "
                                                    :options="
                                                        leave.settings
                                                            .adjustmentReasons
                                                    "
                                                    label="Reason"
                                                    placeholder="Choose a reason"
                                                    :invalid="invalid"
                                                />
                                            </template>
                                        </V2Field>
                                        <V2Field
                                            label="Note"
                                            :error="entryForm.errors.note"
                                        >
                                            <template
                                                #default="{ id, describedBy }"
                                            >
                                                <Textarea
                                                    :id="id"
                                                    :aria-describedby="
                                                        describedBy
                                                    "
                                                    v-model="entryForm.note"
                                                    maxlength="200"
                                                    rows="2"
                                                />
                                            </template>
                                        </V2Field>
                                    </template>
                                    <div
                                        class="flex items-end sm:col-span-2 lg:col-span-3 lg:justify-end"
                                    >
                                        <Button
                                            type="submit"
                                            class="min-h-11 w-full sm:w-fit lg:min-h-9"
                                            :disabled="entryForm.processing"
                                            >Send for a check</Button
                                        >
                                    </div>
                                </form>
                            </V2FormCard>
                            <V2Table
                                :label="`Opening balances and adjustments ${leave.year}`"
                                :columns="6"
                                cards="lg"
                                min-width="820px"
                                :empty="leave.settings.entries.length === 0"
                                :empty-message="`No opening balances or adjustments for ${leave.year}.`"
                                data-test="entries"
                            >
                                <template #head>
                                    <tr>
                                        <th scope="col">Person</th>
                                        <th scope="col">Leave type</th>
                                        <th scope="col" class="text-right">
                                            Days
                                        </th>
                                        <th scope="col">Entry</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">
                                            <span class="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </template>
                                <template #body>
                                    <tr
                                        v-for="e in leave.settings.entries"
                                        :key="e.publicId"
                                        data-test="entry-row"
                                    >
                                        <td data-label="Person">
                                            <span class="font-medium">{{
                                                e.person
                                            }}</span>
                                            <span
                                                class="block text-sm text-muted-foreground"
                                                >Entered by
                                                {{ e.enteredBy }}</span
                                            >
                                        </td>
                                        <td data-label="Leave type">
                                            {{ e.type }} {{ e.year }}
                                        </td>
                                        <td
                                            data-label="Days"
                                            class="text-right"
                                        >
                                            {{ e.days }}
                                        </td>
                                        <td data-label="Entry" data-wrap>
                                            {{
                                                e.kind === 'opening'
                                                    ? 'Opening balance'
                                                    : e.reason
                                            }}<span
                                                v-if="e.note"
                                                class="block text-sm text-muted-foreground"
                                                >{{ e.note }}</span
                                            >
                                        </td>
                                        <td data-label="Status">
                                            <V2Chip
                                                :tone="entryTone[e.status]"
                                                :label="entryLabel[e.status]"
                                            />
                                        </td>
                                        <td data-actions class="text-right">
                                            <div
                                                v-if="e.canDecide"
                                                class="flex flex-wrap justify-end gap-2"
                                            >
                                                <Button
                                                    variant="outline"
                                                    class="min-h-11 lg:min-h-9"
                                                    @click="
                                                        decideEntry(
                                                            e,
                                                            'confirm',
                                                        )
                                                    "
                                                    >Confirm<span
                                                        class="sr-only"
                                                    >
                                                        {{ e.person }},
                                                        {{ e.type }}</span
                                                    ></Button
                                                >
                                                <Button
                                                    variant="outline"
                                                    class="min-h-11 lg:min-h-9"
                                                    @click="
                                                        decideEntry(e, 'reject')
                                                    "
                                                    >Reject<span
                                                        class="sr-only"
                                                    >
                                                        {{ e.person }},
                                                        {{ e.type }}</span
                                                    ></Button
                                                >
                                            </div>
                                            <span
                                                v-else-if="
                                                    e.status === 'pending'
                                                "
                                                class="text-sm text-muted-foreground"
                                                >Another manager checks
                                                this.</span
                                            >
                                        </td>
                                    </tr>
                                </template>
                            </V2Table>
                        </div>
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <!-- Apply (Daniel): "Send request" is the sheet's one filled action. -->
        <V2Dialog
            :open="applyOpen"
            title="Apply for leave"
            description="Someone else approves your request."
            :confirm-label="
                applyForm.processing
                    ? applyForm.evidence_files.length > 0
                        ? 'Uploading…'
                        : 'Sending…'
                    : 'Send request'
            "
            size="tall"
            :busy="applyForm.processing"
            :confirm-disabled="applyMissing !== ''"
            :confirm-disabled-reason="applyMissing"
            @update:open="applyOpen = $event"
            @confirm="sendApply"
        >
            <form
                class="flex flex-col gap-4"
                data-test="apply-form"
                @submit.prevent="sendApply"
            >
                <!-- Daniel: the leave type is a set of option chips, as the plan said. -->
                <V2OptionGroup
                    :model-value="
                        applyForm.leave_type_id === ''
                            ? null
                            : String(applyForm.leave_type_id)
                    "
                    :options="typeOptions"
                    label="Leave type"
                    required
                    :error="applyForm.errors.leave_type_id"
                    data-test="apply-type"
                    @update:model-value="applyForm.leave_type_id = $event ?? ''"
                />
                <div class="grid gap-4 sm:grid-cols-2 sm:items-start">
                    <V2DateField
                        v-model="applyForm.start_date"
                        label="Start date"
                        required
                        :error="applyForm.errors.start_date"
                        @update:model-value="
                            applyForm.end_date ||
                            (applyForm.end_date = applyForm.start_date)
                        "
                    />
                    <V2DateField
                        v-model="applyForm.end_date"
                        label="End date"
                        required
                        :min="applyForm.start_date || undefined"
                        :error="applyForm.errors.end_date"
                    />
                </div>
                <template v-if="chosenType?.allowHalfDays">
                    <V2Field
                        v-slot="{ id }"
                        :label="
                            singleDay ? 'Half day' : 'Half day on the first day'
                        "
                        :error="applyForm.errors.start_part"
                    >
                        <OperationalSelect
                            :id="id"
                            v-model="applyForm.start_part"
                            :options="firstDayOptions"
                            label="Half day on the first day"
                        />
                    </V2Field>
                    <V2Field
                        v-if="!singleDay"
                        v-slot="{ id }"
                        label="Half day on the last day"
                        :error="applyForm.errors.end_part"
                    >
                        <OperationalSelect
                            :id="id"
                            v-model="applyForm.end_part"
                            :options="lastDayOptions"
                            label="Half day on the last day"
                        />
                    </V2Field>
                </template>
                <p
                    class="text-sm"
                    :class="
                        applyLine?.error
                            ? 'text-destructive'
                            : 'font-medium tabular-nums'
                    "
                    role="status"
                    aria-live="polite"
                    data-test="apply-summary"
                >
                    {{ applyLine?.text ?? '' }}
                </p>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Reason"
                    hint="Do not write medical details here."
                    :error="applyForm.errors.reason"
                >
                    <textarea
                        :id="id"
                        v-model="applyForm.reason"
                        maxlength="300"
                        rows="3"
                        :aria-describedby="describedBy"
                        class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                    />
                </V2Field>
                <V2Field
                    v-if="chosenType?.evidenceNeeded"
                    v-slot="{ id, describedBy }"
                    label="Evidence reference"
                    hint="For example, the MC serial number. HR checks the paper copy."
                    :error="applyForm.errors.evidence_reference"
                >
                    <input
                        :id="id"
                        v-model="applyForm.evidence_reference"
                        maxlength="100"
                        :aria-describedby="describedBy"
                        :class="inputClass"
                    />
                </V2Field>
                <div
                    v-if="chosenType?.acceptsFiles"
                    class="flex flex-col gap-2"
                    data-test="apply-files"
                >
                    <span class="text-sm font-medium"
                        >Photo or PDF (optional)</span
                    >
                    <FileChooseButton
                        accept="image/jpeg,image/png,application/pdf"
                        label="Add a photo or PDF"
                        multiple
                        :status="applyFileStatus"
                        status-id="v2-apply-files-status"
                        aria-describedby="v2-apply-files-help"
                        :disabled="applyForm.evidence_files.length >= 3"
                        @change="chooseApplyFiles"
                    />
                    <ul
                        v-if="applyForm.evidence_files.length > 0"
                        class="flex flex-col gap-1 text-sm"
                    >
                        <li
                            v-for="(f, i) in applyForm.evidence_files"
                            :key="`${f.name}-${i}`"
                            class="flex min-w-0 items-center justify-between gap-2"
                        >
                            <span class="min-w-0 truncate"
                                >{{ f.name }} ·
                                <span class="whitespace-nowrap">{{
                                    sizeLabel(f.size)
                                }}</span></span
                            >
                            <button
                                type="button"
                                :class="textLink"
                                class="shrink-0 px-2"
                                :aria-label="`Remove ${f.name}`"
                                @click="dropApplyFile(i)"
                            >
                                Remove
                            </button>
                        </li>
                    </ul>
                    <p
                        id="v2-apply-files-help"
                        class="text-[13px] text-muted-foreground"
                    >
                        JPG, PNG or PDF, up to 5 MB. Take a clear photo of the
                        whole MC.<template v-if="chosenType.retentionDays"
                            >{{ ' ' }}Files are kept for
                            {{ chosenType.retentionDays }} days after your
                            leave.</template
                        >
                    </p>
                    <p
                        v-if="applyFileError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ applyFileError }}
                    </p>
                </div>
            </form>
        </V2Dialog>

        <!-- Evidence (HR-2b): an info sheet with one outline Close. -->
        <V2Dialog
            :open="evidence.open"
            title="Evidence"
            :description="
                evidenceRow
                    ? `${evidenceRow.person ? `${evidenceRow.person} · ` : ''}${evidenceRow.type} · ${evidenceRow.dates}`
                    : undefined
            "
            confirm-label="Close"
            single-action
            single-action-style="outline"
            size="tall"
            @update:open="evidence.open = $event"
            @confirm="evidence.open = false"
        >
            <div
                v-if="evidenceRow?.evidence"
                class="flex flex-col gap-4"
                data-test="evidence-panel"
            >
                <p
                    v-if="evidenceRow.evidence.files.length === 0"
                    class="rounded-[14px] border border-dashed bg-card p-4 text-sm text-muted-foreground"
                >
                    No files yet. Add a photo or PDF of the MC.
                </p>
                <ul v-else class="flex flex-col gap-3">
                    <li
                        v-for="f in evidenceRow.evidence.files"
                        :key="f.publicId"
                        class="flex flex-col gap-2 rounded-[14px] border bg-card p-3"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <p class="text-sm font-medium">
                                {{ f.label }} ·
                                <span class="whitespace-nowrap">{{
                                    f.size
                                }}</span>
                            </p>
                            <button
                                v-if="f.canRemove"
                                type="button"
                                :class="textLink"
                                class="min-w-11 justify-center px-2"
                                :aria-label="`Remove ${f.label}`"
                                @click="openRemoval(f)"
                            >
                                Remove
                            </button>
                        </div>
                        <p class="text-[13px] text-muted-foreground">
                            Added by {{ f.addedBy }} on {{ f.addedOn
                            }}<template v-if="f.onBehalf">
                                (paper copy scanned by HR)</template
                            >
                        </p>
                        <div
                            v-if="f.kind !== 'pdf'"
                            class="overflow-hidden rounded-[10px] border bg-muted p-2"
                        >
                            <img
                                :src="f.url"
                                :alt="photoAlt(f, evidenceRow.evidence.files)"
                                class="mx-auto block h-auto max-h-60 max-w-full object-contain"
                                loading="lazy"
                            />
                        </div>
                        <a v-else :href="f.url" :class="button" class="w-fit"
                            >Download PDF<span class="sr-only">
                                {{ f.label }}</span
                            ></a
                        >
                    </li>
                </ul>
                <div
                    v-if="evidenceRow.evidence.canAdd"
                    class="flex flex-col gap-2"
                >
                    <FileChooseButton
                        accept="image/jpeg,image/png,application/pdf"
                        :label="
                            evidence.uploading
                                ? 'Uploading…'
                                : 'Add a photo or PDF'
                        "
                        :status="evidenceFileStatus"
                        status-id="v2-evidence-status"
                        aria-describedby="v2-evidence-help"
                        :disabled="evidence.uploading"
                        @change="uploadEvidence"
                    />
                    <p
                        id="v2-evidence-help"
                        class="text-[13px] text-muted-foreground"
                    >
                        JPG, PNG or PDF, up to 5 MB.<template
                            v-if="evidenceRow.evidence.retentionDays"
                            >{{ ' ' }}Files are kept for
                            {{ evidenceRow.evidence.retentionDays }} days after
                            the leave.</template
                        >
                    </p>
                    <p
                        v-if="evidence.error"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ evidence.error }}
                    </p>
                </div>
            </div>
        </V2Dialog>

        <V2Dialog
            :open="removal.open"
            :title="`Remove ${removal.file?.label ?? 'this file'}?`"
            description="This deletes the file. A record that it was added and removed stays."
            :confirm-label="removal.processing ? 'Removing…' : 'Remove file'"
            tone="danger"
            :busy="removal.processing"
            :confirm-disabled="removalNeedsReason && removal.reason === ''"
            confirm-disabled-reason="Pick a reason to continue."
            @update:open="removal.open = $event"
            @confirm="confirmRemoval"
        >
            <V2OptionGroup
                v-if="removalNeedsReason"
                :model-value="removal.reason || null"
                :options="removalReasons"
                label="Reason"
                required
                @update:model-value="
                    removal.reason = $event ?? '';
                    removal.error = '';
                "
            />
            <p
                v-if="removal.error"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ removal.error }}
            </p>
        </V2Dialog>

        <!-- Cancel, approve, reject (Daniel): the dialog holds the filled action; Reject and cancels are red. -->
        <V2Dialog
            :open="action.open"
            :title="actionText.title"
            :description="actionText.description"
            :confirm-label="
                action.processing ? actionText.busy : actionText.confirm
            "
            :tone="action.kind === 'approve' ? 'default' : 'danger'"
            :busy="action.processing"
            :confirm-disabled="actionDisabled"
            :confirm-disabled-reason="actionBlockedReason"
            @update:open="action.open = $event"
            @confirm="confirmAction"
        >
            <div class="flex flex-col gap-4" data-test="action-dialog">
                <p class="text-sm font-medium">{{ actionTarget }}</p>
                <p
                    v-if="
                        action.kind === 'approve' &&
                        action.row &&
                        'leftAfter' in action.row &&
                        action.row.leftAfter !== null
                    "
                    class="text-sm tabular-nums"
                >
                    Left after approval: {{ action.row.leftAfter }}
                    {{ dayWord(action.row.leftAfter) }}
                </p>
                <label
                    v-if="action.kind === 'approve' && action.evidenceNeeded"
                    class="flex min-h-11 items-center gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="action.evidenceSeen"
                        @update:model-value="
                            (v) => {
                                action.evidenceSeen = v === true;
                                action.error = '';
                            }
                        "
                    />
                    Evidence seen
                </label>
                <V2OptionGroup
                    v-if="actionNeedsReason"
                    :model-value="action.reason || null"
                    :options="actionReasons"
                    label="Reason"
                    required
                    @update:model-value="
                        action.reason = $event ?? '';
                        action.error = '';
                    "
                />
                <p
                    v-if="action.error"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ action.error }}
                </p>
            </div>
        </V2Dialog>

        <!-- Leave type (PR 2, Daniel): a V2 dialog; "Save leave type" is its one filled action. -->
        <V2Dialog
            :open="typeOpen"
            :title="
                typeForm.leave_type_id ? 'Edit leave type' : 'Add leave type'
            "
            description="A type in use keeps its balance and half-day settings."
            :confirm-label="typeForm.processing ? 'Saving…' : 'Save leave type'"
            size="tall"
            :busy="typeForm.processing"
            :dirty="typeDirty"
            data-test="type-dialog"
            @update:open="typeOpen = $event"
            @confirm="saveType"
        >
            <form
                class="flex flex-col gap-4"
                @submit.prevent="typeForm.processing || saveType()"
            >
                <button
                    type="submit"
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                >
                    Save leave type
                </button>
                <V2Field
                    label="Code"
                    required
                    hint="For example AL. It cannot change later."
                    :error="typeForm.errors.code"
                >
                    <template #default="{ id, describedBy }">
                        <Input
                            :id="id"
                            v-model="typeForm.code"
                            maxlength="20"
                            class="h-11 uppercase lg:h-9"
                            :disabled="!!typeForm.leave_type_id"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </V2Field>
                <V2Field label="Name" required :error="typeForm.errors.name">
                    <template #default="{ id, describedBy }">
                        <Input
                            :id="id"
                            :aria-describedby="describedBy"
                            v-model="typeForm.name"
                            maxlength="60"
                            class="h-11 lg:h-9"
                        />
                    </template>
                </V2Field>
                <fieldset class="grid gap-1">
                    <legend class="mb-1 text-sm font-medium">Rules</legend>
                    <div
                        v-for="f in flags"
                        :key="f.key"
                        class="flex min-h-11 items-center gap-2 lg:min-h-9"
                    >
                        <Checkbox
                            :id="`type-${f.key}`"
                            :model-value="flagValue(f.key)"
                            :disabled="
                                f.key === 'allow_negative' &&
                                !typeForm.uses_balance
                            "
                            @update:model-value="
                                (v) => setFlag(f.key, v === true)
                            "
                        />
                        <Label :for="`type-${f.key}`">{{ f.label }}</Label>
                    </div>
                    <InputError :message="typeForm.errors.uses_balance" />
                </fieldset>
                <fieldset class="grid gap-2" data-test="type-evidence">
                    <legend class="mb-1 text-sm font-medium">
                        Evidence files
                    </legend>
                    <div class="flex min-h-11 items-center gap-2 lg:min-h-9">
                        <Checkbox
                            id="type-accepts-files"
                            :model-value="typeForm.accepts_evidence_files"
                            @update:model-value="
                                (v) =>
                                    (typeForm.accepts_evidence_files =
                                        v === true)
                            "
                        />
                        <Label for="type-accepts-files"
                            >Accept evidence files</Label
                        >
                    </div>
                    <V2Field
                        v-if="typeForm.accepts_evidence_files"
                        label="Keep files for (days after the leave ends)"
                        required
                        hint="Files are deleted after this. Requests and their history stay. At most 2,555 days (7 years). When you lower the days, files already older than that are deleted the next night."
                        :error="typeForm.errors.evidence_retention_days"
                    >
                        <template #default="{ id, describedBy }">
                            <Input
                                :id="id"
                                v-model="typeForm.evidence_retention_days"
                                inputmode="numeric"
                                class="h-11 tabular-nums lg:h-9"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </V2Field>
                    <p
                        v-if="
                            typeForm.accepts_evidence_files &&
                            savedRetentionDays > MAX_EVIDENCE_DAYS
                        "
                        class="text-sm text-muted-foreground"
                        data-test="type-retention-over-cap"
                    >
                        This type was saved with
                        {{ savedRetentionDays.toLocaleString('en-MY') }} days.
                        Leave records are deleted 7 years after their leave
                        year, and the files go with them, so the 7 years win.
                        Enter 2,555 or fewer to save a change.
                    </p>
                </fieldset>
                <template v-if="typeForm.uses_balance">
                    <V2Field
                        label="Carry forward up to (days)"
                        hint="Leave empty for no carry-forward."
                        :error="typeForm.errors.carry_max_days"
                    >
                        <template #default="{ id, describedBy }">
                            <Input
                                :id="id"
                                v-model="typeForm.carry_max_days"
                                inputmode="decimal"
                                class="h-11 tabular-nums lg:h-9"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </V2Field>
                    <V2Field
                        v-if="typeForm.carry_max_days"
                        label="Carried days expire on (MM-DD)"
                        :error="typeForm.errors.carry_expires_on"
                    >
                        <template #default="{ id, describedBy }">
                            <Input
                                :id="id"
                                :aria-describedby="describedBy"
                                v-model="typeForm.carry_expires_on"
                                placeholder="03-31"
                                maxlength="5"
                                class="h-11 lg:h-9"
                            />
                        </template>
                    </V2Field>
                </template>
            </form>
        </V2Dialog>

        <!-- Archive or restore a leave type (Daniel): confirmed first; Archive is red. The request is the same archiveType. -->
        <V2Dialog
            :open="archiving.open"
            :title="
                archiving.type?.isActive
                    ? `Archive ${archiving.type?.name}?`
                    : `Restore ${archiving.type?.name}?`
            "
            :description="
                archiving.type?.isActive
                    ? 'Nobody can apply for it after this. Requests and balances already made stay.'
                    : 'Staff can apply for it again.'
            "
            :confirm-label="
                archiving.type?.isActive ? 'Archive type' : 'Restore type'
            "
            :tone="archiving.type?.isActive ? 'danger' : 'default'"
            data-test="archive-dialog"
            @update:open="archiving.open = $event"
            @confirm="confirmArchive"
        />

        <!-- Year actions and KPOne defaults (PR 2): a V2 dialog with the same wording and details. -->
        <V2Dialog
            :open="yearAction.open"
            :title="yearText.title"
            :description="yearText.description"
            :confirm-label="
                yearAction.processing ? 'Working…' : yearText.confirm
            "
            :busy="yearAction.processing"
            :size="yearAction.kind === 'defaults' ? 'tall' : 'default'"
            :tone="yearAction.kind === 'expire' ? 'danger' : 'default'"
            data-test="year-dialog"
            @update:open="yearAction.open = $event"
            @confirm="confirmYear"
        >
            <div
                v-if="
                    yearAction.kind === 'defaults' &&
                    leave.settings &&
                    (leave.settings.defaults.typesToAdd.length > 0 ||
                        leave.settings.defaults.rows > 0)
                "
                class="grid gap-2 text-sm"
                data-test="defaults-details"
            >
                <ul class="list-disc pl-5">
                    <li>
                        Annual leave 8, 12 or 16 days and sick leave 14, 18 or
                        22 days a year, by service under 2 years, 2 to 5 years,
                        and 5 years or more at 1 January.
                    </li>
                    <li>
                        Hospitalisation leave: 60 days a year including sick
                        leave, so 60 less the sick days in force.
                    </li>
                    <li>
                        Maternity leave (98 days a confinement) and paternity
                        leave (7 days a birth): paid, no yearly balance; the
                        approver checks the days.
                    </li>
                    <li>
                        Annual leave by completed months for someone who leaves
                        during the year (Employment Act section 60E(1A)) and, as
                        KPOne's default, for someone who joins during it. It
                        uses the dates in the staff file when you press; press
                        again after a join or a leave to update them. Sick leave
                        is not pro-rated.
                    </li>
                    <li>No carry-forward.</li>
                </ul>
                <p class="text-muted-foreground">
                    Check that the clinic does not already have these types
                    under other codes.
                </p>
                <p class="text-muted-foreground">
                    {{
                        plural(leave.settings.defaults.base, 'person', 'people')
                    }}
                    under 2 years ·
                    {{ leave.settings.defaults.middle }} with 2 to 5 years ·
                    {{ leave.settings.defaults.long }} with 5 years or more.
                    <template v-if="leave.settings.defaults.prorated > 0"
                        >{{ ' '
                        }}{{
                            plural(
                                leave.settings.defaults.prorated,
                                'person joins or leaves',
                                'people join or leave',
                            )
                        }}
                        during {{ leave.year }}, so their annual leave is
                        pro-rated.</template
                    >
                </p>
                <p
                    v-if="
                        leave.settings.defaults.noRecord > 0 ||
                        leave.settings.defaults.crossing > 0
                    "
                    class="text-muted-foreground"
                >
                    Check by hand:
                    <template v-if="leave.settings.defaults.noRecord > 0"
                        >{{
                            plural(
                                leave.settings.defaults.noRecord,
                                'person has',
                                'people have',
                            )
                        }}
                        no employment start date in their staff file, so they
                        get the first tier.</template
                    >
                    <template v-if="leave.settings.defaults.crossing > 0"
                        >{{ ' '
                        }}{{
                            plural(
                                leave.settings.defaults.crossing,
                                'person reaches',
                                'people reach',
                            )
                        }}
                        2 or 5 years during {{ leave.year }}; add a person
                        entitlement if needed.</template
                    >
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ leave.settings.defaults.label }}
                </p>
            </div>
            <p
                v-if="yearAction.error"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ yearAction.error }}
            </p>
        </V2Dialog>
    </PageFrame>
</template>
