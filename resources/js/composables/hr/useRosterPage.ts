import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import type { Compliance } from '@/components/hr/compliance/CompliancePanel.vue';
import type { ComplianceSettings } from '@/components/hr/compliance/ComplianceRules.vue';

/**
 * The Roster page's state and actions, shared by Classic (`pages/HR/Roster.vue`) and Version 2
 * (`pages/HR/V2/Roster.vue`), so both send the same requests with the same lock versions and idempotency keys.
 * Moved out of the Classic page unchanged; the Classic template did not change.
 */
export type Option = { value: string; label: string };
export type Shift = {
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
export type Version = {
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
export type Planner = {
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
    /** HR-5b: compliance warnings and fair rotation; null when no week is shown. */
    compliance: Compliance | null;
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
export type Minimums = {
    branches: { id: number; name: string }[];
    branch: { id: number; name: string };
    values: Record<string, Record<string, number>>;
    lockVersion: number;
    roles: { value: string; label: string; plural: string }[];
    /** HR-5b rules, for Director and HR Manager only. */
    compliance: ComplianceSettings | null;
};

export type RosterProps = {
    /** HR-5a: your own expiring or expired credentials, in words. */
    credentialReminders?: string[];
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
        my: {
            days: { date: string; label: string; shifts: Shift[] }[];
            canClock: boolean;
            canAskOpenShifts?: boolean;
        } | null;
        planner: Planner | null;
        minimums: Minimums | null;
    };
};

export function useRosterPage(props: RosterProps) {
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
        () =>
            planner.value?.branch.id ??
            props.roster.minimums?.branch.id ??
            null,
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
        | 'start'
        | 'copy'
        | 'change'
        | 'submit'
        | 'approve'
        | 'return'
        | 'discard';
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
                description:
                    'You can add shifts, then send the week for approval.',
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
                    e.roster ??
                    e.reason ??
                    e.branch_id ??
                    e.week ??
                    'Try again.'),
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
            branch_room_id:
                data.branch_room_id === '' ? null : data.branch_room_id,
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
            options.unshift({
                value: current,
                label: `${row.name} · ${row.role}`,
            });
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

    return {
        page,
        errors,
        skipped,
        tabLabels,
        tabs,
        planner,
        shown,
        statusKey,
        statusLabel,
        branchId,
        visit,
        changeTab,
        changeBranch,
        versionLine,
        dialog,
        dialogText,
        openDialog,
        confirmDialog,
        sheetOpen,
        editing,
        removeOpen,
        shiftForm,
        shiftKey,
        openAdd,
        openEdit,
        saveShift,
        removing,
        removeError,
        removeShift,
        staffOptions,
        roomOptions,
        sessionOptions,
        shiftsOn,
        dayShifts,
        isShort,
        timeText,
        minimumForm,
        saveMinimums,
        minimumsEmpty,
        onLeave,
        minimumError,
    };
}
