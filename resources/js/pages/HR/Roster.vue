<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';

type Option = { value: string; label: string };
type Shift = {
    id: string;
    staffUserId: number;
    branch: string;
    date: string;
    startTime: string;
    endTime: string;
    startLabel: string;
    endLabel: string;
    nextDay: boolean;
    breakMinutes: number;
    sessionType: string;
    sessionLabel: string;
    roomId: number | null;
    roomName: string | null;
};
type Version = {
    id: string;
    number: number;
    status: 'draft' | 'submitted' | 'approved' | 'superseded' | 'discarded';
    lockVersion: number;
    createdBy: string | null;
    submittedBy: string | null;
    submittedAt: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    changedAt: string | null;
};
type Planner = {
    branches: { id: number; name: string }[];
    branch: { id: number; name: string; timezone: string };
    weekLockVersion: number | null;
    days: { date: string; label: string; long: string }[];
    approvedVersion: Version | null;
    openVersion: Version | null;
    shown:
        (Version & { sentByYou: boolean; returnReason: string | null }) | null;
    rows: {
        staffUserId: number;
        name: string;
        role: string;
        shifts: Shift[];
        leaveDays: Record<string, 'full' | 'half'>;
    }[];
    totals: number[];
    shortDays: { date: string; label: string }[];
    warnings: string[];
    staffOptions: { id: number; label: string }[];
    rooms: { id: number; name: string }[];
    returnReasons: Option[];
    previousWeekApproved: boolean;
    can: {
        start: boolean;
        copy: boolean;
        change: boolean;
        edit: boolean;
        submit: boolean;
        discard: boolean;
        decide: boolean;
    };
};
type Minimums = {
    branches: { id: number; name: string }[];
    branch: { id: number; name: string };
    values: Record<string, Record<string, number>>;
    lockVersion: number;
    roles: { value: string; label: string; plural: string }[];
};

const props = defineProps<{
    roster: {
        tab: 'my' | 'planner' | 'minimums';
        tabs: ('my' | 'planner' | 'minimums')[];
        week: {
            start: string;
            end: string;
            previous: string;
            next: string;
            today: string;
            label: string;
        };
        sessions: Option[];
        my: { days: { date: string; label: string; shifts: Shift[] }[] } | null;
        planner: Planner | null;
        minimums: Minimums | null;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const skipped = computed(
    () =>
        ((page as unknown as { flash?: { rosterSkipped?: string[] } }).flash
            ?.rosterSkipped ?? []) as string[],
);

const tabLabels = {
    my: 'My roster',
    planner: 'Planner',
    minimums: 'Minimum staffing',
};
const tabs = computed(() =>
    props.roster.tabs.map((value) => ({ value, label: tabLabels[value] })),
);
const planner = computed(() => props.roster.planner);
const shown = computed(() => planner.value?.shown ?? null);

const statusKey = (status: Version['status']) =>
    status === 'discarded' ? 'roster_superseded' : `roster_${status}`;
const statusLabel: Record<Version['status'], string> = {
    draft: 'Draft',
    submitted: 'Waiting for approval',
    approved: 'Approved',
    superseded: 'Replaced',
    discarded: 'Discarded',
};

const branchId = computed(
    () => planner.value?.branch.id ?? props.roster.minimums?.branch.id ?? null,
);

const visit = (params: Record<string, string | number | undefined>) =>
    router.get(
        '/hr/roster',
        {
            tab: props.roster.tab,
            week: props.roster.week.start,
            branch: branchId.value ?? undefined,
            ...params,
        },
        { preserveScroll: true },
    );

const changeTab = (tab: string) => visit({ tab });
const changeBranch = (value: string | number) => visit({ branch: value });

const versionLine = computed(() => {
    const v = shown.value;

    if (!v) {
        return '';
    }

    if (v.status === 'approved' || v.status === 'superseded') {
        return `Version ${v.number} · approved by ${v.decidedBy ?? 'Staff member'} on ${v.decidedAt ?? ''}`;
    }

    if (v.status === 'submitted') {
        return `Version ${v.number} · sent by ${v.submittedBy ?? 'Staff member'} on ${v.submittedAt ?? ''}`;
    }

    return `Version ${v.number} draft · last changed ${v.changedAt ?? ''}`;
});

// One shared confirmation dialog for every week-level action.
type WeekAction =
    'start' | 'copy' | 'change' | 'submit' | 'approve' | 'return' | 'discard';
const dialog = reactive({
    open: false,
    action: 'submit' as WeekAction,
    processing: false,
    error: '',
    reason: 'needs_changes',
    key: '',
});
const dialogText = computed(() => {
    const p = planner.value;
    const copies = {
        start: {
            title: 'Start a draft for this week?',
            description: 'You can add shifts, then send the week for approval.',
            confirm: 'Start draft',
            busy: 'Starting…',
        },
        copy: {
            title: 'Copy last approved week?',
            description: `Shifts from ${props.roster.week.previous} onwards are copied into a new draft. Shifts that are no longer allowed are left out and listed.`,
            confirm: 'Copy week',
            busy: 'Copying…',
        },
        change: {
            title: 'Change this week?',
            description:
                'A new draft is made from the approved shifts. Staff keep seeing the approved week until the new version is approved.',
            confirm: 'Make a new draft',
            busy: 'Starting…',
        },
        submit: {
            title: 'Send this week for approval?',
            description:
                'The Director checks it before staff see it. You cannot change it while it waits.',
            confirm: 'Send for approval',
            busy: 'Sending…',
        },
        approve: {
            title: 'Approve this week?',
            description:
                'Staff will see these shifts in My roster. Any earlier approved version is replaced.',
            confirm: 'Approve week',
            busy: 'Approving…',
        },
        return: {
            title: 'Return this week to draft?',
            description:
                'The week goes back to the person who planned it so they can change it.',
            confirm: 'Return to draft',
            busy: 'Returning…',
        },
        discard: {
            title: 'Discard this draft?',
            description:
                'The draft and its changes are removed from the plan. The approved week, if any, stays.',
            confirm: 'Discard draft',
            busy: 'Discarding…',
        },
    } as const;

    return p ? copies[dialog.action] : copies.submit;
});

const openDialog = (action: WeekAction) => {
    dialog.action = action;
    dialog.error = '';
    // One key per opening, so a retried click replays instead of failing as stale.
    dialog.key = crypto.randomUUID();
    dialog.reason = 'needs_changes';
    dialog.open = true;
};

const confirmDialog = () => {
    const p = planner.value;

    if (!p) {
        return;
    }

    const base = {
        branch_id: p.branch.id,
        week: props.roster.week.start,
        idempotency_key: dialog.key,
    };
    const done = {
        preserveScroll: true,
        onStart: () => (dialog.processing = true),
        onFinish: () => (dialog.processing = false),
        onSuccess: () => (dialog.open = false),
        onError: (e: Record<string, string>) =>
            (dialog.error =
                e.roster ?? e.reason ?? e.branch_id ?? e.week ?? 'Try again.'),
    };
    const version = p.shown;

    if (
        dialog.action === 'start' ||
        dialog.action === 'copy' ||
        dialog.action === 'change'
    ) {
        router.post(
            '/hr/roster/drafts',
            {
                ...base,
                mode: dialog.action === 'start' ? 'blank' : dialog.action,
                expected_week_lock_version: p.weekLockVersion,
            },
            done,
        );

        return;
    }

    if (!version) {
        return;
    }

    router.post(
        `/hr/roster/versions/${version.id}/${dialog.action}`,
        {
            ...base,
            expected_version_lock: version.lockVersion,
            reason: dialog.action === 'return' ? dialog.reason : undefined,
        },
        done,
    );
};

// Add or edit one shift in a draft.
const sheetOpen = ref(false);
const editing = ref<Shift | null>(null);
const removeOpen = ref(false);
const shiftForm = useForm({
    staff_user_id: '' as string | number,
    date: '',
    start_time: '08:00',
    end_time: '14:00',
    break_minutes: 0 as number | string,
    session_type: 'morning',
    branch_room_id: '' as string | number,
});

const shiftKey = ref('');
const openAdd = (date?: string, staffUserId?: number) => {
    shiftKey.value = crypto.randomUUID();
    editing.value = null;
    shiftForm.reset();
    shiftForm.clearErrors();
    shiftForm.date = date ?? props.roster.week.start;
    shiftForm.staff_user_id = staffUserId ?? '';
    sheetOpen.value = true;
};

const openEdit = (shift: Shift) => {
    shiftKey.value = crypto.randomUUID();
    editing.value = shift;
    shiftForm.clearErrors();
    shiftForm.staff_user_id = shift.staffUserId;
    shiftForm.date = shift.date;
    shiftForm.start_time = shift.startTime;
    shiftForm.end_time = shift.endTime;
    shiftForm.break_minutes = shift.breakMinutes;
    shiftForm.session_type = shift.sessionType;
    shiftForm.branch_room_id = shift.roomId ?? '';
    sheetOpen.value = true;
};

const saveShift = () => {
    const p = planner.value;
    const version = p?.shown;

    if (!p || !version) {
        return;
    }

    const payload = (data: Record<string, unknown>) => ({
        ...data,
        branch_room_id: data.branch_room_id === '' ? null : data.branch_room_id,
        branch_id: p.branch.id,
        week: props.roster.week.start,
        expected_version_lock: version.lockVersion,
        idempotency_key: shiftKey.value,
    });
    const options = {
        preserveScroll: true,
        onSuccess: () => (sheetOpen.value = false),
    };

    if (editing.value) {
        shiftForm
            .transform(payload)
            .put(
                `/hr/roster/versions/${version.id}/shifts/${editing.value.id}`,
                options,
            );
    } else {
        shiftForm
            .transform(payload)
            .post(`/hr/roster/versions/${version.id}/shifts`, options);
    }
};

const removing = ref(false);
const removeError = ref('');
const removeShift = () => {
    const p = planner.value;
    const version = p?.shown;

    if (!p || !version || !editing.value) {
        return;
    }

    router.delete(
        `/hr/roster/versions/${version.id}/shifts/${editing.value.id}`,
        {
            data: {
                branch_id: p.branch.id,
                week: props.roster.week.start,
                expected_version_lock: version.lockVersion,
            },
            preserveScroll: true,
            onStart: () => (removing.value = true),
            onFinish: () => (removing.value = false),
            onSuccess: () => {
                removeOpen.value = false;
                sheetOpen.value = false;
            },
            onError: (e: Record<string, string>) =>
                (removeError.value = e.roster ?? 'Try again.'),
        },
    );
};

const staffOptions = computed(() => {
    const options = (planner.value?.staffOptions ?? []).map((s) => ({
        value: s.id,
        label: s.label,
    }));
    const current = editing.value?.staffUserId;
    // Keep the person on the shift visible even if they are no longer assigned; the server re-checks on save.
    const row = planner.value?.rows.find((r) => r.staffUserId === current);

    if (current && row && !options.some((o) => o.value === current)) {
        options.unshift({ value: current, label: `${row.name} · ${row.role}` });
    }

    return options;
});
const roomOptions = computed(() => [
    { value: '', label: 'No room' },
    ...(planner.value?.rooms ?? []).map((r) => ({
        value: r.id,
        label: r.name,
    })),
]);
const sessionOptions = computed(() => props.roster.sessions);

const shiftsOn = (row: Planner['rows'][number], date: string) =>
    row.shifts.filter((s) => s.date === date);
const dayShifts = (date: string) =>
    (planner.value?.rows ?? []).flatMap((row) =>
        shiftsOn(row, date).map((shift) => ({ row, shift })),
    );
const isShort = (date: string) =>
    (planner.value?.shortDays ?? []).some((d) => d.date === date);
const timeText = (s: Shift) =>
    `${s.startLabel} – ${s.endLabel}${s.nextDay ? ' (next day)' : ''}`;

// Minimum staffing.
const minimumForm = useForm({
    values: JSON.parse(
        JSON.stringify(props.roster.minimums?.values ?? {}),
    ) as Record<string, Record<string, number | string>>,
});
const saveMinimums = () => {
    const m = props.roster.minimums;

    if (!m) {
        return;
    }

    minimumForm
        .transform((data) => ({
            branch_id: m.branch.id,
            week: props.roster.week.start,
            minimums: data.values,
            expected_lock_version: m.lockVersion,
        }))
        .put('/hr/roster/minimum-staffing', { preserveScroll: true });
};
const minimumsEmpty = computed(() =>
    Object.values(props.roster.minimums?.values ?? {}).every((roles) =>
        Object.values(roles).every((n) => n === 0),
    ),
);
// HR-2a: "On leave" only, never the leave type or reason.
const onLeave = (date: string) =>
    (planner.value?.rows ?? [])
        .filter((row) => row.leaveDays[date])
        .map((row) =>
            row.leaveDays[date] === 'half'
                ? `${row.name} (half day)`
                : row.name,
        );
const minimumError = (session: string, role: string) =>
    errors.value[`minimums.${session}.${role}`];
</script>

<template>
    <Head title="Roster" />
    <div
        class="mx-auto flex w-full max-w-[1600px] flex-col gap-4 px-4 py-4 md:px-5"
    >
        <PageHeader
            eyebrow="KPOne HR"
            title="Roster"
            description="Weekly shifts for each branch. The Director approves each week before staff see it."
        />

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="roster.tab"
            :tabs="tabs"
            label="Roster sections"
            @update:model-value="changeTab"
        />

        <div
            v-if="roster.tab !== 'minimums'"
            class="flex flex-wrap items-center gap-2"
            data-test="week-switcher"
        >
            <Button
                variant="outline"
                size="sm"
                @click="visit({ week: roster.week.previous })"
                >Previous week</Button
            >
            <Button
                variant="outline"
                size="sm"
                @click="visit({ week: roster.week.today })"
                >This week</Button
            >
            <Button
                variant="outline"
                size="sm"
                @click="visit({ week: roster.week.next })"
                >Next week</Button
            >
            <span class="text-sm font-medium" data-test="week-label">{{
                roster.week.label
            }}</span>
        </div>

        <!-- My roster -->
        <section
            v-if="roster.tab === 'my' && roster.my"
            aria-labelledby="my-shifts"
            class="flex flex-col gap-3"
        >
            <div>
                <h2 id="my-shifts" class="text-base font-semibold">
                    My shifts
                </h2>
                <p class="text-xs text-muted-foreground">
                    Your approved shifts for this week. Times are in each
                    branch's time.
                </p>
            </div>
            <EmptyState
                v-if="roster.my.days.length === 0"
                title="No approved shifts this week."
                description="Your roster appears here after the Director approves it."
            />
            <ul v-else class="flex flex-col gap-3">
                <li
                    v-for="day in roster.my.days"
                    :key="day.date"
                    class="grid gap-2 rounded-xl border bg-card p-4 md:grid-cols-[10rem_1fr]"
                >
                    <h3 class="text-sm font-semibold">{{ day.label }}</h3>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="shift in day.shifts"
                            :key="shift.id"
                            class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                        >
                            <span class="font-medium tabular-nums">{{
                                timeText(shift)
                            }}</span>
                            <span>{{ shift.branch }}</span>
                            <Badge variant="outline">{{
                                shift.sessionLabel
                            }}</Badge>
                            <span
                                v-if="shift.breakMinutes > 0"
                                class="text-xs text-muted-foreground"
                                >Break {{ shift.breakMinutes }} min</span
                            >
                            <span
                                v-if="shift.roomName"
                                class="text-xs text-muted-foreground"
                                >{{ shift.roomName }}</span
                            >
                        </li>
                    </ul>
                </li>
            </ul>
        </section>

        <!-- Planner -->
        <section
            v-if="roster.tab === 'planner' && planner"
            aria-labelledby="planner-title"
            class="flex flex-col gap-3"
        >
            <h2 id="planner-title" class="sr-only">Planner</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid w-full gap-2 sm:w-56">
                    <Label for="planner-branch">Branch</Label>
                    <OperationalSelect
                        id="planner-branch"
                        :model-value="planner.branch.id"
                        :options="
                            planner.branches.map((b) => ({
                                value: b.id,
                                label: b.name,
                            }))
                        "
                        label="Branch"
                        @update:model-value="changeBranch"
                    />
                </div>
                <StatusBadge
                    v-if="shown"
                    :status="statusKey(shown.status)"
                    :label="statusLabel[shown.status]"
                    data-test="version-status"
                />
                <div
                    v-if="planner.approvedVersion && planner.openVersion"
                    class="flex gap-1"
                    role="group"
                    aria-label="Version shown"
                >
                    <Button
                        size="sm"
                        :variant="
                            shown?.id === planner.approvedVersion.id
                                ? 'secondary'
                                : 'outline'
                        "
                        :aria-pressed="shown?.id === planner.approvedVersion.id"
                        @click="visit({ view: 'approved' })"
                    >
                        Approved (v{{ planner.approvedVersion.number }})
                    </Button>
                    <Button
                        size="sm"
                        :variant="
                            shown?.id === planner.openVersion.id
                                ? 'secondary'
                                : 'outline'
                        "
                        :aria-pressed="shown?.id === planner.openVersion.id"
                        @click="visit({ view: 'open' })"
                    >
                        {{
                            planner.openVersion.status === 'submitted'
                                ? 'Waiting'
                                : 'Draft'
                        }}
                        (v{{ planner.openVersion.number }})
                    </Button>
                </div>
            </div>
            <p
                v-if="shown"
                class="text-xs text-muted-foreground"
                data-test="version-line"
            >
                {{ versionLine }}
                <template v-if="shown.returnReason && shown.status === 'draft'">
                    · returned: {{ shown.returnReason }}</template
                >
            </p>

            <ErrorState v-if="errors.roster" :message="errors.roster" />

            <div
                class="flex flex-col gap-2 sm:flex-row sm:flex-wrap"
                data-test="planner-actions"
            >
                <Button v-if="planner.can.decide" @click="openDialog('approve')"
                    >Approve week</Button
                >
                <Button
                    v-if="planner.can.decide"
                    variant="outline"
                    @click="openDialog('return')"
                    >Return to draft</Button
                >
                <Button v-if="planner.can.submit" @click="openDialog('submit')"
                    >Send for approval</Button
                >
                <Button
                    v-if="planner.can.edit"
                    variant="outline"
                    @click="openAdd()"
                    >Add shift</Button
                >
                <Button
                    v-if="planner.can.change"
                    variant="outline"
                    @click="openDialog('change')"
                    >Change this week</Button
                >
                <Button
                    v-if="planner.can.discard"
                    variant="ghost"
                    class="text-destructive"
                    @click="openDialog('discard')"
                    >Discard draft</Button
                >
                <p
                    v-if="shown?.status === 'submitted' && !planner.can.decide"
                    class="text-sm text-muted-foreground"
                    data-test="waiting-note"
                >
                    {{
                        shown.sentByYou
                            ? 'You sent this week, so someone else must approve it.'
                            : 'Waiting for the Director to approve.'
                    }}
                </p>
            </div>

            <div
                v-if="skipped.length > 0"
                role="status"
                class="rounded-xl border border-l-4 border-l-warning bg-card p-4 text-sm"
            >
                <p class="font-medium">Some shifts were not copied</p>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="note in skipped" :key="note">{{ note }}</li>
                </ul>
            </div>

            <div
                v-if="planner.warnings.length > 0"
                role="status"
                class="rounded-xl border border-l-4 border-l-warning bg-card p-4 text-sm"
                data-test="roster-warnings"
            >
                <p class="font-medium">Check before approval</p>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="warning in planner.warnings" :key="warning">
                        {{ warning }}
                    </li>
                </ul>
            </div>

            <div v-if="!shown" class="flex flex-col gap-3">
                <EmptyState
                    :title="`No roster for ${planner.branch.name} this week.`"
                    :description="
                        planner.can.start
                            ? 'Start a draft, or copy the last approved week.'
                            : 'Nothing has been planned for this week yet.'
                    "
                />
                <div
                    v-if="planner.can.start"
                    class="flex flex-col gap-2 sm:flex-row"
                >
                    <Button @click="openDialog('start')">Start draft</Button>
                    <Button
                        v-if="planner.can.copy"
                        variant="outline"
                        @click="openDialog('copy')"
                        >Copy last approved week</Button
                    >
                </div>
            </div>

            <template v-else>
                <!-- Desktop grid (from 1024 px) -->
                <div class="hidden lg:block">
                    <OperationalTable
                        :label="`Roster for ${planner.branch.name}, ${roster.week.label}`"
                        :columns="8"
                        :empty="planner.rows.length === 0"
                        empty-message="No shifts yet. Use Add shift to plan the week."
                        min-width="960px"
                    >
                        <template #head>
                            <tr>
                                <th scope="col" class="w-48">Staff</th>
                                <th
                                    v-for="day in planner.days"
                                    :key="day.date"
                                    scope="col"
                                >
                                    {{ day.label }}
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in planner.rows"
                                :key="row.staffUserId"
                            >
                                <th scope="row" class="align-top font-normal">
                                    <span class="block font-medium">{{
                                        row.name
                                    }}</span>
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ row.role }}</span
                                    >
                                </th>
                                <td
                                    v-for="day in planner.days"
                                    :key="day.date"
                                    class="align-top"
                                >
                                    <div class="flex flex-col gap-1">
                                        <Badge
                                            v-if="row.leaveDays[day.date]"
                                            variant="outline"
                                            class="w-fit"
                                            data-test="on-leave-chip"
                                            >{{
                                                row.leaveDays[day.date] ===
                                                'half'
                                                    ? 'On leave (half day)'
                                                    : 'On leave'
                                            }}</Badge
                                        >
                                        <component
                                            :is="
                                                planner.can.edit
                                                    ? 'button'
                                                    : 'div'
                                            "
                                            v-for="shift in shiftsOn(
                                                row,
                                                day.date,
                                            )"
                                            :key="shift.id"
                                            :type="
                                                planner.can.edit
                                                    ? 'button'
                                                    : undefined
                                            "
                                            class="min-h-11 rounded-md border bg-card px-2 py-1 text-left text-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :class="
                                                planner.can.edit
                                                    ? 'hover:bg-muted'
                                                    : ''
                                            "
                                            :aria-label="
                                                planner.can.edit
                                                    ? `Edit shift: ${row.name}, ${day.long}, ${shift.startLabel} to ${shift.endLabel}`
                                                    : undefined
                                            "
                                            @click="
                                                planner.can.edit &&
                                                openEdit(shift)
                                            "
                                        >
                                            <span
                                                class="block font-medium tabular-nums"
                                                >{{ shift.startLabel }} –
                                                {{ shift.endLabel }}</span
                                            >
                                            <span
                                                class="block text-muted-foreground"
                                            >
                                                {{ shift.sessionLabel
                                                }}<template
                                                    v-if="shift.roomName"
                                                >
                                                    ·
                                                    {{
                                                        shift.roomName
                                                    }}</template
                                                >
                                            </span>
                                        </component>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" class="font-medium">
                                    Total on shift
                                </th>
                                <td
                                    v-for="(day, index) in planner.days"
                                    :key="day.date"
                                    class="tabular-nums"
                                >
                                    <span
                                        class="inline-flex items-center gap-2 whitespace-nowrap"
                                        data-test="day-total"
                                    >
                                        <span>{{ planner.totals[index] }}</span>
                                        <StatusBadge
                                            v-if="isShort(day.date)"
                                            status="roster_short"
                                            label="Short"
                                            tone="warning"
                                        />
                                    </span>
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                </div>

                <!-- Phone: one card per day -->
                <ul
                    class="flex flex-col gap-3 lg:hidden"
                    data-test="planner-days"
                >
                    <li
                        v-for="day in planner.days"
                        :key="day.date"
                        class="rounded-xl border bg-card p-4"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold">
                                {{ day.long }}
                            </h3>
                            <StatusBadge
                                v-if="isShort(day.date)"
                                status="roster_short"
                                label="Short"
                                tone="warning"
                            />
                        </div>
                        <p
                            v-if="onLeave(day.date).length > 0"
                            class="mt-2 text-xs text-muted-foreground"
                            data-test="on-leave-day"
                        >
                            On leave: {{ onLeave(day.date).join(', ') }}
                        </p>
                        <p
                            v-if="dayShifts(day.date).length === 0"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            No shifts.
                        </p>
                        <ul v-else class="mt-2 flex flex-col divide-y">
                            <li
                                v-for="item in dayShifts(day.date)"
                                :key="item.shift.id"
                                class="flex min-w-0 items-center justify-between gap-2 py-2"
                            >
                                <div class="min-w-0 text-sm">
                                    <p class="truncate font-medium">
                                        {{ item.row.name }}
                                        <span
                                            class="font-normal text-muted-foreground"
                                            >· {{ item.row.role }}</span
                                        >
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        <span class="tabular-nums">{{
                                            timeText(item.shift)
                                        }}</span>
                                        · {{ item.shift.sessionLabel
                                        }}<template v-if="item.shift.roomName">
                                            ·
                                            {{ item.shift.roomName }}</template
                                        >
                                    </p>
                                </div>
                                <Button
                                    v-if="planner.can.edit"
                                    variant="outline"
                                    class="min-h-11 min-w-11"
                                    :aria-label="`Edit shift: ${item.row.name}, ${day.long}, ${item.shift.startLabel} to ${item.shift.endLabel}`"
                                    @click="openEdit(item.shift)"
                                >
                                    Edit
                                </Button>
                            </li>
                        </ul>
                    </li>
                </ul>
            </template>
        </section>

        <!-- Minimum staffing -->
        <section
            v-if="roster.tab === 'minimums' && roster.minimums"
            aria-labelledby="minimums-title"
            class="flex max-w-5xl flex-col gap-3"
        >
            <div>
                <h2 id="minimums-title" class="text-base font-semibold">
                    Minimum staffing
                </h2>
                <p class="text-xs text-muted-foreground">
                    Shown as a warning on the planner. It never blocks approval.
                </p>
            </div>
            <div class="grid w-full gap-2 sm:w-56">
                <Label for="minimum-branch">Branch</Label>
                <OperationalSelect
                    id="minimum-branch"
                    :model-value="roster.minimums.branch.id"
                    :options="
                        roster.minimums.branches.map((b) => ({
                            value: b.id,
                            label: b.name,
                        }))
                    "
                    label="Branch"
                    @update:model-value="changeBranch"
                />
            </div>
            <ErrorState v-if="errors.minimums" :message="errors.minimums" />
            <p v-if="minimumsEmpty" class="text-xs text-muted-foreground">
                No minimums set for {{ roster.minimums.branch.name }}.
            </p>

            <div class="hidden md:block">
                <OperationalTable
                    :label="`Minimum staffing for ${roster.minimums.branch.name}`"
                    :columns="4"
                    min-width="560px"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Session</th>
                            <th
                                v-for="role in roster.minimums.roles"
                                :key="role.value"
                                scope="col"
                            >
                                {{ role.plural }}
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="session in roster.sessions"
                            :key="session.value"
                        >
                            <th scope="row" class="font-medium">
                                {{ session.label }}
                            </th>
                            <td
                                v-for="role in roster.minimums.roles"
                                :key="role.value"
                            >
                                <Input
                                    :id="`min-${session.value}-${role.value}`"
                                    v-model="
                                        minimumForm.values[session.value][
                                            role.value
                                        ]
                                    "
                                    type="number"
                                    min="0"
                                    max="20"
                                    class="w-24 tabular-nums"
                                    :aria-label="`Minimum ${role.plural}, ${session.label}, ${roster.minimums.branch.name}`"
                                    :aria-invalid="
                                        minimumError(session.value, role.value)
                                            ? 'true'
                                            : undefined
                                    "
                                />
                                <InputError
                                    :message="
                                        minimumError(session.value, role.value)
                                    "
                                />
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>
            <ul class="flex flex-col gap-3 md:hidden">
                <li
                    v-for="session in roster.sessions"
                    :key="session.value"
                    class="rounded-xl border bg-card p-4"
                >
                    <h3 class="text-sm font-semibold">{{ session.label }}</h3>
                    <div class="mt-2 grid gap-3">
                        <div
                            v-for="role in roster.minimums.roles"
                            :key="role.value"
                            class="grid gap-2"
                        >
                            <Label
                                :for="`min-m-${session.value}-${role.value}`"
                                >{{ role.plural }}</Label
                            >
                            <Input
                                :id="`min-m-${session.value}-${role.value}`"
                                v-model="
                                    minimumForm.values[session.value][
                                        role.value
                                    ]
                                "
                                type="number"
                                min="0"
                                max="20"
                                class="tabular-nums"
                                :aria-invalid="
                                    minimumError(session.value, role.value)
                                        ? 'true'
                                        : undefined
                                "
                            />
                            <InputError
                                :message="
                                    minimumError(session.value, role.value)
                                "
                            />
                        </div>
                    </div>
                </li>
            </ul>
            <div>
                <Button
                    :disabled="minimumForm.processing"
                    @click="saveMinimums"
                >
                    {{ minimumForm.processing ? 'Saving…' : 'Save minimums' }}
                </Button>
            </div>
        </section>

        <OperationalConfirmDialog
            v-model:open="dialog.open"
            :title="dialogText.title"
            :description="dialogText.description"
            :confirm-label="dialogText.confirm"
            :processing-label="dialogText.busy"
            :processing="dialog.processing"
            :destructive="dialog.action === 'discard'"
            :error="dialog.error"
            @confirm="confirmDialog"
        >
            <div
                v-if="dialog.action === 'return' && planner"
                class="grid gap-2"
            >
                <Label for="return-reason">Reason *</Label>
                <OperationalSelect
                    id="return-reason"
                    v-model="dialog.reason"
                    :options="planner.returnReasons"
                    label="Reason"
                />
            </div>
        </OperationalConfirmDialog>

        <Sheet v-model:open="sheetOpen">
            <SheetContent
                side="right"
                class="w-full overflow-y-auto sm:max-w-md"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        editing ? 'Edit shift' : 'Add shift'
                    }}</SheetTitle>
                    <SheetDescription
                        >{{ planner?.branch.name }} ·
                        {{ roster.week.label }}</SheetDescription
                    >
                </SheetHeader>
                <form class="grid gap-4 px-4 pb-4" @submit.prevent="saveShift">
                    <div class="grid gap-2">
                        <Label for="shift-staff">Staff member *</Label>
                        <OperationalSelect
                            id="shift-staff"
                            v-model="shiftForm.staff_user_id"
                            :options="staffOptions"
                            label="Staff member"
                            placeholder="Choose a staff member"
                            :invalid="!!shiftForm.errors.staff_user_id"
                        />
                        <InputError :message="shiftForm.errors.staff_user_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="shift-date">Date *</Label>
                        <Input
                            id="shift-date"
                            v-model="shiftForm.date"
                            type="date"
                            :min="roster.week.start"
                            :max="roster.week.end"
                            :aria-invalid="
                                shiftForm.errors.date ? 'true' : undefined
                            "
                        />
                        <InputError :message="shiftForm.errors.date" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="shift-start">Start time *</Label>
                            <Input
                                id="shift-start"
                                v-model="shiftForm.start_time"
                                type="time"
                                :aria-invalid="
                                    shiftForm.errors.start_time
                                        ? 'true'
                                        : undefined
                                "
                            />
                            <InputError
                                :message="shiftForm.errors.start_time"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="shift-end">End time *</Label>
                            <Input
                                id="shift-end"
                                v-model="shiftForm.end_time"
                                type="time"
                                :aria-invalid="
                                    shiftForm.errors.end_time
                                        ? 'true'
                                        : undefined
                                "
                                aria-describedby="shift-end-help"
                            />
                            <p
                                id="shift-end-help"
                                class="text-xs text-muted-foreground"
                            >
                                If the end is earlier than the start, the shift
                                ends the next day.
                            </p>
                            <InputError :message="shiftForm.errors.end_time" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="shift-break">Break (minutes)</Label>
                        <Input
                            id="shift-break"
                            v-model="shiftForm.break_minutes"
                            type="number"
                            min="0"
                            max="180"
                            :aria-invalid="
                                shiftForm.errors.break_minutes
                                    ? 'true'
                                    : undefined
                            "
                            aria-describedby="shift-break-help"
                        />
                        <p
                            id="shift-break-help"
                            class="text-xs text-muted-foreground"
                        >
                            Unpaid break inside the shift.
                        </p>
                        <InputError :message="shiftForm.errors.break_minutes" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="shift-session">Session *</Label>
                        <OperationalSelect
                            id="shift-session"
                            v-model="shiftForm.session_type"
                            :options="sessionOptions"
                            label="Session"
                            :invalid="!!shiftForm.errors.session_type"
                        />
                        <InputError :message="shiftForm.errors.session_type" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="shift-room">Proposed room</Label>
                        <OperationalSelect
                            id="shift-room"
                            v-model="shiftForm.branch_room_id"
                            :options="roomOptions"
                            label="Proposed room"
                            describedby="shift-room-help"
                            :invalid="!!shiftForm.errors.branch_room_id"
                        />
                        <p
                            id="shift-room-help"
                            class="text-xs text-muted-foreground"
                        >
                            A plan only. The doctor still chooses their room on
                            the day.
                        </p>
                        <InputError
                            :message="shiftForm.errors.branch_room_id"
                        />
                    </div>
                    <ErrorState v-if="errors.roster" :message="errors.roster" />
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            v-if="editing"
                            type="button"
                            variant="ghost"
                            class="mr-auto text-destructive"
                            @click="
                                removeError = '';
                                removeOpen = true;
                            "
                            >Remove shift</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            @click="sheetOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            :disabled="shiftForm.processing"
                            >{{
                                shiftForm.processing ? 'Saving…' : 'Save shift'
                            }}</Button
                        >
                    </div>
                </form>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            v-model:open="removeOpen"
            title="Remove this shift?"
            description="The shift is taken out of this draft."
            confirm-label="Remove shift"
            processing-label="Removing…"
            :processing="removing"
            :error="removeError"
            destructive
            @confirm="removeShift"
        />
    </div>
</template>
