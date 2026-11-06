<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { CalendarCheck, Clock } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Textarea } from '@/components/ui/textarea';

type Tab = 'my' | 'review' | 'settings';
type Kind = 'change_in' | 'set_out' | 'add_day';
type Option = { value: string; label: string };
type Flag = { code: string; label: string };
type Row = {
    key: string;
    userId: number;
    person?: string;
    date: string;
    dateLabel: string;
    shift: { label: string; branch: string } | null;
    session: {
        publicId: string;
        status: 'open' | 'closed' | 'missing_out';
        branch: string;
        branchId: number;
        in: string | null;
        out: string | null;
        inValue: string;
        outValue: string | null;
    } | null;
    worked: string | null;
    flags: Flag[];
    correctionWaiting: boolean;
    needsClockOut: boolean;
};
type Correction = {
    publicId: string;
    person: string;
    dayLabel: string;
    branch: string;
    kind: Kind;
    kindLabel: string;
    compare: string;
    reason: string;
    note: string | null;
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    statusLabel: string;
    onBehalf: boolean;
    proposedBy: string;
    canDecide: boolean;
    canCancel: boolean;
    lockVersion: number;
};

const props = defineProps<{
    attendance: {
        tab: Tab;
        tabs: Tab[];
        pendingCount: number;
        kinds: Option[];
        reasons: Option[];
        rejectReasons: Option[];
        my: {
            branch: { id: number; name: string } | null;
            timezone: string;
            serverNow: string;
            canPress: boolean;
            refusal: string | null;
            state: 'out' | 'in' | 'done';
            clockedInLabel: string | null;
            doneLabel: string | null;
            staleLabel: string | null;
            todayShift: string | null;
            week: Row[];
            weekLabel: string;
            attention: Row[];
            settingsReady: boolean;
            correctionDays: number | null;
            corrections: Correction[];
        } | null;
        review: {
            branches: { id: number; name: string }[];
            branch: { id: number; name: string } | null;
            date: string | null;
            dateLabel?: string;
            prevDate?: string;
            nextDate?: string;
            isToday?: boolean;
            settingsReady?: boolean;
            rows: Row[];
            summary: string;
            pending: Correction[];
            staff: { id: number; name: string }[];
        } | null;
        settings: {
            today: string;
            current: {
                graceMinutes: number;
                forgottenWindowMinutes: number;
                correctionDays: number;
                effectiveFrom: string;
            } | null;
            suggested: {
                graceMinutes: number;
                forgottenWindowMinutes: number;
                correctionDays: number;
            };
            history: {
                id: number;
                effectiveFrom: string;
                summary: string;
                by: string;
            }[];
        } | null;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);

const clockNotes = computed(
    () =>
        !!props.attendance.my &&
        (!props.attendance.my.settingsReady ||
            !!props.attendance.my.staleLabel),
);
const userName = computed(() => page.props.auth?.user?.name ?? '');

const tabLabels: Record<Tab, string> = {
    my: 'My attendance',
    review: 'Review',
    settings: 'Settings',
};
const tabs = computed(() =>
    props.attendance.tabs.map((value) => ({
        value,
        label:
            value === 'review' && props.attendance.pendingCount > 0
                ? `Review (${props.attendance.pendingCount})`
                : tabLabels[value],
    })),
);
const reviewQuery = computed(() => ({
    review_branch: props.attendance.review?.branch?.id,
    review_date: props.attendance.review?.date ?? undefined,
}));
const visit = (params: Record<string, string | number | undefined>) =>
    router.get(
        '/hr/attendance',
        { tab: props.attendance.tab, ...params },
        {
            preserveScroll: true,
        },
    );

// ---- Tabs (DD-30 pattern from the Queue board, #296) ----
const tabStrip = useTemplateRef<HTMLElement>('tabStrip');
const tabNav = () =>
    tabStrip.value?.querySelector<HTMLElement>('[role="tablist"]') ?? null;
const moreTabsRight = ref(false);
const updateTabFade = () => {
    const nav = tabNav();

    moreTabsRight.value =
        nav !== null &&
        nav.scrollWidth > nav.clientWidth + 1 &&
        nav.scrollLeft + nav.clientWidth < nav.scrollWidth - 1;
};
const selectTab = (tab: string) => visit({ tab });

// ---- Server time (Daniel: ticks every second, never in a live region) ----
const offset = ref(0);
const tick = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;
const syncClock = () => {
    if (props.attendance.my) {
        offset.value = Date.parse(props.attendance.my.serverNow) - Date.now();
    }
};
const serverTime = computed(() => {
    const my = props.attendance.my;

    if (!my) {
        return '';
    }

    return new Intl.DateTimeFormat('en-US', {
        timeZone: my.timezone,
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }).format(new Date(tick.value + offset.value));
});
const serverDate = computed(() => {
    const my = props.attendance.my;

    if (!my) {
        return '';
    }

    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: my.timezone,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(tick.value + offset.value));

    return parts;
});
watch(() => props.attendance.my?.serverNow, syncClock);

onMounted(() => {
    syncClock();
    timer = setInterval(() => (tick.value = Date.now()), 1000);
    const nav = tabNav();
    nav?.addEventListener('scroll', updateTabFade, { passive: true });
    void nextTick().then(updateTabFade);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    tabNav()?.removeEventListener('scroll', updateTabFade);
});

// ---- One polite live region for state changes only (a refusal is read once, by its alert under the button) ----
const announcement = ref('');
watch(
    () => [props.attendance.my?.state, props.attendance.my?.clockedInLabel],
    ([, label], [oldState]) => {
        const my = props.attendance.my;

        if (my && my.state !== oldState) {
            announcement.value =
                my.state === 'in'
                    ? String(label ?? 'Clocked in.')
                    : my.state === 'done'
                      ? String(my.doneLabel ?? 'Clocked out.')
                      : '';
        }
    },
);

// ---- Clock in and out ----
const clock = reactive({ processing: false, confirmOut: false, key: '' });
const press = (kind: 'in' | 'out') => {
    clock.key = clock.key || crypto.randomUUID();
    clock.processing = true;
    router.post(
        kind === 'in' ? '/hr/attendance/clock-in' : '/hr/attendance/clock-out',
        { idempotency_key: clock.key },
        {
            preserveScroll: true,
            onSuccess: () => {
                clock.key = '';
                clock.confirmOut = false;
            },
            onError: () => {
                clock.key = '';
                clock.confirmOut = false;
            },
            onFinish: () => (clock.processing = false),
        },
    );
};

// ---- Corrections ----
const correction = reactive({
    open: false,
    processing: false,
    key: '',
    kind: 'set_out' as Kind,
    sessionId: '',
    userId: '' as string | number,
    branchId: '' as string | number,
    person: '',
    recordedIn: null as string | null,
    recordedOut: null as string | null,
    inTime: '',
    outTime: '',
    reason: '',
    note: '',
    fromReview: false,
});
const correctionErrors = computed(() => ({
    kind: errors.value.kind,
    in_time: errors.value.in_time,
    out_time: errors.value.out_time,
    reason_code: errors.value.reason_code,
    note: errors.value.note,
    session_id: errors.value.session_id,
    branch_id: errors.value.branch_id,
    user_id: errors.value.user_id,
    attendance: errors.value.attendance,
}));
const kindOptions = computed(() =>
    props.attendance.kinds.filter((k) =>
        correction.sessionId === ''
            ? k.value === 'add_day'
            : k.value !== 'add_day',
    ),
);
const formatLocal = (value: string) => {
    if (!value) {
        return '';
    }

    const [, time] = value.split('T');
    const [h, m] = time.split(':').map(Number);
    const suffix = h >= 12 ? 'PM' : 'AM';

    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${suffix}`;
};
const compareLine = computed(() => {
    if (correction.kind === 'change_in') {
        return `Recorded: ${correction.recordedIn ?? 'none'} · New: ${formatLocal(correction.inTime) || '–'}`;
    }

    if (correction.kind === 'set_out') {
        return `Recorded: ${correction.recordedOut ?? 'none'} · New: ${formatLocal(correction.outTime) || '–'}`;
    }

    return `Recorded: none · New: ${formatLocal(correction.inTime) || '–'} – ${formatLocal(correction.outTime) || '–'}`;
});
const openCorrection = (row: Row | null, fromReview = false) => {
    const session = row?.session ?? null;
    Object.assign(correction, {
        open: true,
        key: crypto.randomUUID(),
        sessionId: session?.publicId ?? '',
        kind: session
            ? session.out === null
                ? 'set_out'
                : 'change_in'
            : ('add_day' as Kind),
        userId: fromReview && row ? row.userId : '',
        branchId: fromReview
            ? (props.attendance.review?.branch?.id ?? '')
            : (props.attendance.my?.branch?.id ?? ''),
        person: fromReview ? (row?.person ?? '') : '',
        recordedIn: session?.in ?? null,
        recordedOut: session?.out ?? null,
        inTime: session?.inValue ?? '',
        outTime: session?.outValue ?? '',
        reason: '',
        note: '',
        fromReview,
    });
};
const openForgottenDay = (fromReview = false) => {
    openCorrection(null, fromReview);
};
const sendCorrection = () => {
    correction.processing = true;
    router.post(
        '/hr/attendance/corrections',
        {
            tab: correction.fromReview ? 'review' : 'my',
            ...(correction.fromReview ? reviewQuery.value : {}),
            kind: correction.kind,
            session_id: correction.sessionId || undefined,
            user_id: correction.userId || undefined,
            branch_id:
                correction.kind === 'add_day' ? correction.branchId : undefined,
            in_time:
                correction.kind === 'set_out' ? undefined : correction.inTime,
            out_time:
                correction.kind === 'change_in'
                    ? undefined
                    : correction.outTime,
            reason_code: correction.reason,
            note: correction.note,
            idempotency_key: correction.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (correction.open = false),
            onFinish: () => (correction.processing = false),
        },
    );
};
const withdrawing = ref('');
const cancelCorrection = (c: Correction, fromReview = false) => {
    if (withdrawing.value !== '') {
        return;
    }

    withdrawing.value = c.publicId;
    router.post(
        `/hr/attendance/corrections/${c.publicId}/cancel`,
        {
            tab: fromReview ? 'review' : 'my',
            ...(fromReview ? reviewQuery.value : {}),
            expected_lock_version: c.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true, onFinish: () => (withdrawing.value = '') },
    );
};

// ---- Decisions (outline buttons in the list; the primary action is in the dialog) ----
const decision = reactive({
    open: false,
    processing: false,
    approve: true,
    target: null as Correction | null,
    reason: '',
    key: '',
    error: '',
});
const openDecision = (c: Correction, approve: boolean) =>
    Object.assign(decision, {
        open: true,
        approve,
        target: c,
        reason: '',
        key: crypto.randomUUID(),
        error: '',
    });
const confirmDecision = () => {
    const target = decision.target;

    if (!target) {
        return;
    }

    if (!decision.approve && decision.reason === '') {
        decision.error = 'Choose a reason.';

        return;
    }

    decision.processing = true;
    router.post(
        `/hr/attendance/corrections/${target.publicId}/${decision.approve ? 'approve' : 'reject'}`,
        {
            ...reviewQuery.value,
            expected_lock_version: target.lockVersion,
            reason: decision.approve ? undefined : decision.reason,
            idempotency_key: decision.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (decision.open = false),
            onError: (e) =>
                (decision.error =
                    e.attendance ?? e.reason ?? 'This could not be saved.'),
            onFinish: () => (decision.processing = false),
        },
    );
};

// ---- Settings ----
const settingsForm = reactive({
    grace_minutes: String(
        props.attendance.settings?.current?.graceMinutes ??
            props.attendance.settings?.suggested.graceMinutes ??
            '',
    ),
    forgotten_window_minutes: String(
        props.attendance.settings?.current?.forgottenWindowMinutes ??
            props.attendance.settings?.suggested.forgottenWindowMinutes ??
            '',
    ),
    correction_days: String(
        props.attendance.settings?.current?.correctionDays ??
            props.attendance.settings?.suggested.correctionDays ??
            '',
    ),
    effective_from: props.attendance.settings?.today ?? '',
    processing: false,
    key: crypto.randomUUID(),
});
const saveSettings = () => {
    settingsForm.processing = true;
    router.put(
        '/hr/attendance/settings',
        {
            grace_minutes: settingsForm.grace_minutes,
            forgotten_window_minutes: settingsForm.forgotten_window_minutes,
            correction_days: settingsForm.correction_days,
            effective_from: settingsForm.effective_from,
            idempotency_key: settingsForm.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (settingsForm.key = crypto.randomUUID()),
            onFinish: () => (settingsForm.processing = false),
        },
    );
};

const reviewBranchOptions = computed(() =>
    (props.attendance.review?.branches ?? []).map((b) => ({
        value: b.id,
        label: b.name,
    })),
);
const staffOptions = computed(() =>
    (props.attendance.review?.staff ?? []).map((s) => ({
        value: s.id,
        label: s.name,
    })),
);
const correctionStatusTone = (
    status: Correction['status'],
): 'neutral' | 'success' | 'danger' =>
    status === 'approved'
        ? 'success'
        : status === 'rejected'
          ? 'danger'
          : 'neutral';
</script>

<template>
    <Head title="Attendance" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <PageHeader
            eyebrow="KPOne HR"
            title="Attendance"
            description="Clock in and out on your own account. Someone else approves each correction."
        />

        <div v-if="tabs.length > 1" ref="tabStrip" class="min-w-0">
            <OperationalTabs
                :model-value="attendance.tab"
                :tabs="tabs"
                label="Attendance sections"
                class="min-w-0 max-sm:[&>button]:h-11 max-sm:[&>button]:px-2.5"
                :class="
                    moreTabsRight
                        ? '[mask-image:linear-gradient(to_right,#000_calc(100%-2.5rem),transparent)]'
                        : ''
                "
                @update:model-value="selectTab"
            />
        </div>

        <p aria-live="polite" class="sr-only" data-test="clock-announcer">
            {{ announcement }}
        </p>

        <ErrorState
            v-if="errors.attendance && !correction.open && !decision.open"
            :message="errors.attendance"
        />

        <!-- My attendance -->
        <section
            v-if="attendance.tab === 'my' && attendance.my"
            aria-labelledby="my-attendance-title"
            class="flex flex-col gap-4"
        >
            <h2 id="my-attendance-title" class="sr-only">My attendance</h2>

            <div
                class="grid gap-4 rounded-xl border bg-card p-4 sm:p-5"
                :class="
                    clockNotes
                        ? 'lg:grid-cols-[minmax(0,26rem)_1fr]'
                        : 'lg:max-w-[28rem]'
                "
                data-test="clock-card"
            >
                <div class="flex flex-col gap-3">
                    <p class="text-sm text-muted-foreground">
                        {{ serverDate }}
                        <span v-if="attendance.my.branch">
                            · {{ attendance.my.branch.name }}</span
                        >
                    </p>
                    <p
                        v-if="
                            attendance.my.state === 'in' &&
                            attendance.my.clockedInLabel
                        "
                        class="text-base font-semibold"
                        data-test="clocked-in"
                    >
                        {{ attendance.my.clockedInLabel }}
                    </p>
                    <p
                        v-if="attendance.my.state === 'done'"
                        class="text-base font-semibold"
                        data-test="done-today"
                    >
                        {{ attendance.my.doneLabel }}
                    </p>
                    <Button
                        v-if="attendance.my.state === 'out'"
                        class="min-h-14 w-full text-base"
                        :disabled="!attendance.my.canPress || clock.processing"
                        data-test="clock-in"
                        @click="press('in')"
                    >
                        <Clock class="size-5" />
                        {{ clock.processing ? 'Clocking in…' : 'Clock in' }}
                    </Button>
                    <Button
                        v-else-if="attendance.my.state === 'in'"
                        class="min-h-14 w-full text-base"
                        :disabled="clock.processing"
                        data-test="clock-out"
                        @click="clock.confirmOut = true"
                    >
                        <Clock class="size-5" />
                        {{ clock.processing ? 'Clocking out…' : 'Clock out' }}
                    </Button>
                    <InputError :message="errors.clock" />
                    <p
                        v-if="
                            attendance.my.state === 'out' &&
                            attendance.my.refusal &&
                            !errors.clock
                        "
                        class="text-sm text-muted-foreground"
                        data-test="clock-refusal"
                    >
                        {{ attendance.my.refusal }}
                    </p>
                    <p class="text-sm tabular-nums" data-test="server-time">
                        Server time {{ serverTime }}
                    </p>
                    <p v-if="attendance.my.todayShift" class="text-sm">
                        Today: {{ attendance.my.todayShift }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        No approved shift today.
                    </p>
                    <p
                        class="text-xs text-muted-foreground"
                        data-test="privacy-line"
                    >
                        KPOne records the time only. No location or photo.
                    </p>
                    <p
                        v-if="
                            attendance.my.state !== 'out' ||
                            attendance.my.doneLabel
                        "
                        class="text-xs text-muted-foreground"
                    >
                        Signed in as
                        {{ userName }}. Sign out if this is a shared computer.
                    </p>
                </div>
                <div v-if="clockNotes" class="flex flex-col gap-3">
                    <p
                        v-if="!attendance.my.settingsReady"
                        class="text-sm text-muted-foreground"
                    >
                        Attendance is not set up yet. Your times are still
                        recorded; late and early flags start once HR saves the
                        settings.
                    </p>
                    <p
                        v-if="attendance.my.staleLabel"
                        class="text-sm"
                        data-test="stale"
                    >
                        {{ attendance.my.staleLabel }}
                    </p>
                </div>
            </div>

            <!-- Earlier days that need a clock-out time -->
            <div
                v-if="attendance.my.attention.length > 0"
                class="rounded-xl border bg-card p-4"
                data-test="attention"
            >
                <h3 class="text-sm font-semibold">Needs your clock-out time</h3>
                <ul class="mt-2 flex flex-col gap-2">
                    <li
                        v-for="r in attendance.my.attention"
                        :key="r.key"
                        class="flex flex-wrap items-center justify-between gap-2 text-sm"
                    >
                        <span
                            >{{ r.dateLabel }} · Clocked in at
                            {{ r.session?.in }}</span
                        >
                        <Button
                            variant="outline"
                            class="min-h-11 sm:min-h-8"
                            @click="openCorrection(r)"
                        >
                            Add clock-out time<span class="sr-only">
                                for {{ r.dateLabel }}</span
                            >
                        </Button>
                    </li>
                </ul>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-base font-semibold">
                    This week
                    <span class="text-sm font-normal text-muted-foreground">{{
                        attendance.my.weekLabel
                    }}</span>
                </h3>
                <Button
                    v-if="attendance.my.settingsReady && attendance.my.branch"
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    data-test="add-day"
                    @click="openForgottenDay(false)"
                >
                    Add a day I forgot to clock
                </Button>
            </div>
            <EmptyState
                v-if="attendance.my.week.length === 0"
                title="No shifts or clock times this week yet."
                description="Your approved shifts and clock times appear here."
            />
            <ul v-else class="flex flex-col gap-2" data-test="my-week">
                <li
                    v-for="r in attendance.my.week"
                    :key="r.key"
                    class="grid gap-2 rounded-xl border bg-card p-4 md:grid-cols-[8rem_1fr_auto] md:items-center"
                >
                    <h4 class="text-sm font-semibold">{{ r.dateLabel }}</h4>
                    <div class="flex min-w-0 flex-col gap-1 text-sm">
                        <p v-if="r.shift">
                            Shift: {{ r.shift.label
                            }}<span v-if="r.shift.branch">
                                · {{ r.shift.branch }}</span
                            >
                        </p>
                        <p v-else class="text-muted-foreground">No shift</p>
                        <p v-if="r.session" class="tabular-nums">
                            In {{ r.session.in }} · Out {{ r.session.out ?? '–'
                            }}<span v-if="r.worked">
                                · Worked {{ r.worked }}</span
                            >
                        </p>
                        <div
                            v-if="r.flags.length > 0 || r.correctionWaiting"
                            class="flex flex-wrap gap-1"
                        >
                            <StatusBadge
                                v-for="f in r.flags"
                                :key="f.code"
                                status="flag"
                                :label="f.label"
                                tone="warning"
                            />
                            <StatusBadge
                                v-if="r.correctionWaiting"
                                status="waiting"
                                label="Correction waiting"
                                tone="neutral"
                            />
                        </div>
                    </div>
                    <div
                        v-if="
                            r.session &&
                            !r.correctionWaiting &&
                            attendance.my.settingsReady
                        "
                    >
                        <Button
                            variant="outline"
                            class="min-h-11 w-full sm:min-h-8 sm:w-auto"
                            @click="openCorrection(r)"
                        >
                            {{
                                r.needsClockOut
                                    ? 'Add clock-out time'
                                    : 'Correct'
                            }}<span class="sr-only">
                                for {{ r.dateLabel }}</span
                            >
                        </Button>
                    </div>
                </li>
            </ul>

            <div
                v-if="attendance.my.corrections.length > 0"
                class="flex flex-col gap-2"
            >
                <h3 class="text-base font-semibold">My corrections</h3>
                <ul class="flex flex-col gap-2" data-test="my-corrections">
                    <li
                        v-for="c in attendance.my.corrections"
                        :key="c.publicId"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-card p-3 text-sm"
                    >
                        <div class="flex min-w-0 flex-col gap-1">
                            <span class="font-medium"
                                >{{ c.dayLabel }} · {{ c.kindLabel }}</span
                            >
                            <span class="text-muted-foreground tabular-nums"
                                >{{ c.compare }} · {{ c.reason }}</span
                            >
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusBadge
                                :status="c.status"
                                :label="c.statusLabel"
                                :tone="correctionStatusTone(c.status)"
                            />
                            <Button
                                v-if="c.canCancel"
                                variant="ghost"
                                class="min-h-11 sm:min-h-8"
                                :disabled="withdrawing !== ''"
                                @click="cancelCorrection(c)"
                            >
                                Withdraw<span class="sr-only">
                                    correction for {{ c.dayLabel }}</span
                                >
                            </Button>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Review -->
        <section
            v-if="attendance.tab === 'review' && attendance.review"
            aria-labelledby="review-title"
            class="flex flex-col gap-4"
        >
            <h2 id="review-title" class="sr-only">Attendance review</h2>
            <EmptyState
                v-if="!attendance.review.branch"
                title="No active branch."
                description="Add a branch first."
            />
            <template v-else>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="grid gap-1">
                        <Label for="review-branch">Branch</Label>
                        <OperationalSelect
                            id="review-branch"
                            :model-value="attendance.review.branch.id"
                            :options="reviewBranchOptions"
                            label="Branch"
                            class="min-w-48"
                            @update:model-value="
                                (v) =>
                                    visit({
                                        branch: v,
                                        date:
                                            attendance.review?.date ??
                                            undefined,
                                    })
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="review-date">Day</Label>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="outline"
                                class="min-h-11 sm:min-h-9"
                                aria-label="Previous day"
                                @click="
                                    visit({
                                        branch: attendance.review?.branch?.id,
                                        date: attendance.review?.prevDate,
                                    })
                                "
                                >‹</Button
                            >
                            <Input
                                id="review-date"
                                type="date"
                                class="min-h-11 w-40 sm:min-h-9"
                                :model-value="attendance.review.date ?? ''"
                                @change="
                                    (e: Event) =>
                                        visit({
                                            branch: attendance.review?.branch
                                                ?.id,
                                            date: (e.target as HTMLInputElement)
                                                .value,
                                        })
                                "
                            />
                            <Button
                                variant="outline"
                                class="min-h-11 sm:min-h-9"
                                aria-label="Next day"
                                @click="
                                    visit({
                                        branch: attendance.review?.branch?.id,
                                        date: attendance.review?.nextDate,
                                    })
                                "
                                >›</Button
                            >
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        class="min-h-11 sm:min-h-9"
                        :disabled="!attendance.review.settingsReady"
                        data-test="review-add-day"
                        @click="openForgottenDay(true)"
                    >
                        Add a forgotten day
                    </Button>
                </div>
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h3 class="text-base font-semibold">
                        {{ attendance.review.dateLabel }}
                    </h3>
                    <p
                        class="text-sm text-muted-foreground"
                        data-test="review-summary"
                    >
                        {{ attendance.review.summary }}
                    </p>
                </div>
                <p
                    v-if="!attendance.review.settingsReady"
                    class="text-sm text-muted-foreground"
                >
                    Attendance is not set up yet. Times are shown without flags.
                </p>
                <EmptyState
                    v-if="attendance.review.rows.length === 0"
                    title="No shifts or clock times on this day."
                    description="Approved shifts and clock times for this branch appear here."
                />
                <template v-else>
                    <div class="hidden lg:block">
                        <OperationalTable
                            label="Roster against attendance"
                            :columns="7"
                            min-width="960px"
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Person</th>
                                    <th scope="col">Shift</th>
                                    <th scope="col">In</th>
                                    <th scope="col">Out</th>
                                    <th scope="col">Worked</th>
                                    <th scope="col">Flags</th>
                                    <th scope="col">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="r in attendance.review.rows"
                                    :key="r.key"
                                    data-test="review-row"
                                >
                                    <td class="font-medium">{{ r.person }}</td>
                                    <td>{{ r.shift?.label ?? 'No shift' }}</td>
                                    <td class="tabular-nums">
                                        {{ r.session?.in ?? '–' }}
                                    </td>
                                    <td class="tabular-nums">
                                        {{ r.session?.out ?? '–' }}
                                    </td>
                                    <td class="tabular-nums">
                                        {{ r.worked ?? '–' }}
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            <StatusBadge
                                                v-for="f in r.flags"
                                                :key="f.code"
                                                status="flag"
                                                :label="f.label"
                                                tone="warning"
                                            />
                                            <StatusBadge
                                                v-if="r.correctionWaiting"
                                                status="waiting"
                                                label="Correction waiting"
                                                tone="neutral"
                                            />
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <Button
                                            v-if="
                                                r.session &&
                                                !r.correctionWaiting &&
                                                attendance.review.settingsReady
                                            "
                                            variant="outline"
                                            size="sm"
                                            @click="openCorrection(r, true)"
                                        >
                                            Correct<span class="sr-only">
                                                times for {{ r.person }}</span
                                            >
                                        </Button>
                                    </td>
                                </tr>
                            </template>
                        </OperationalTable>
                    </div>
                    <ul class="flex flex-col gap-2 lg:hidden">
                        <li
                            v-for="r in attendance.review.rows"
                            :key="r.key"
                            class="rounded-xl border bg-card p-4 text-sm"
                        >
                            <p class="font-semibold">{{ r.person }}</p>
                            <p>{{ r.shift?.label ?? 'No shift' }}</p>
                            <p class="tabular-nums">
                                In {{ r.session?.in ?? '–' }} · Out
                                {{ r.session?.out ?? '–'
                                }}<span v-if="r.worked">
                                    · Worked {{ r.worked }}</span
                                >
                            </p>
                            <div
                                v-if="r.flags.length > 0 || r.correctionWaiting"
                                class="mt-1 flex flex-wrap gap-1"
                            >
                                <StatusBadge
                                    v-for="f in r.flags"
                                    :key="f.code"
                                    status="flag"
                                    :label="f.label"
                                    tone="warning"
                                />
                                <StatusBadge
                                    v-if="r.correctionWaiting"
                                    status="waiting"
                                    label="Correction waiting"
                                    tone="neutral"
                                />
                            </div>
                            <Button
                                v-if="
                                    r.session &&
                                    !r.correctionWaiting &&
                                    attendance.review.settingsReady
                                "
                                variant="outline"
                                class="mt-2 min-h-11 w-full"
                                @click="openCorrection(r, true)"
                            >
                                Correct<span class="sr-only">
                                    times for {{ r.person }}</span
                                >
                            </Button>
                        </li>
                    </ul>
                </template>

                <div
                    class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                    data-test="pending-corrections"
                >
                    <h3 class="text-base font-semibold">Corrections waiting</h3>
                    <p
                        v-if="attendance.review.pending.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Nothing is waiting.
                    </p>
                    <ul v-else class="flex flex-col divide-y">
                        <li
                            v-for="c in attendance.review.pending"
                            :key="c.publicId"
                            class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <span class="font-medium"
                                    >{{ c.person }} · {{ c.dayLabel }} ·
                                    {{ c.branch }}</span
                                >
                                <span>{{ c.kindLabel }}</span>
                                <span
                                    class="text-muted-foreground tabular-nums"
                                    >{{ c.compare }}</span
                                >
                                <span class="text-muted-foreground">
                                    {{ c.reason
                                    }}<span v-if="c.note">: {{ c.note }}</span
                                    ><span v-if="c.onBehalf">
                                        · Proposed by {{ c.proposedBy }}</span
                                    >
                                </span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <template v-if="c.canDecide">
                                    <Button
                                        variant="outline"
                                        class="min-h-11 sm:min-h-8"
                                        @click="openDecision(c, true)"
                                    >
                                        Approve<span class="sr-only">
                                            correction for {{ c.person }}</span
                                        >
                                    </Button>
                                    <Button
                                        variant="outline"
                                        class="min-h-11 sm:min-h-8"
                                        @click="openDecision(c, false)"
                                    >
                                        Reject<span class="sr-only">
                                            correction for {{ c.person }}</span
                                        >
                                    </Button>
                                </template>
                                <Button
                                    v-else-if="c.canCancel"
                                    variant="ghost"
                                    class="min-h-11 sm:min-h-8"
                                    :disabled="withdrawing !== ''"
                                    @click="cancelCorrection(c, true)"
                                >
                                    Withdraw<span class="sr-only">
                                        correction for {{ c.person }}</span
                                    >
                                </Button>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >Someone else decides this one.</span
                                >
                            </div>
                        </li>
                    </ul>
                </div>
            </template>
        </section>

        <!-- Settings -->
        <section
            v-if="attendance.tab === 'settings' && attendance.settings"
            aria-labelledby="settings-title"
            class="flex flex-col gap-4"
        >
            <h2 id="settings-title" class="text-base font-semibold">
                Attendance settings
            </h2>
            <p
                v-if="!attendance.settings.current"
                class="text-sm text-muted-foreground"
                data-test="settings-not-set"
            >
                Attendance is not set up yet. The numbers below are suggestions
                from the clinic HR proposal; nothing counts until you save.
            </p>
            <p v-else class="text-sm text-muted-foreground">
                In force since {{ attendance.settings.current.effectiveFrom }}.
            </p>
            <form
                class="grid max-w-xl gap-4 rounded-xl border bg-card p-4"
                data-test="settings-form"
                @submit.prevent="saveSettings"
            >
                <div class="grid gap-1">
                    <Label for="grace-minutes">Grace minutes *</Label>
                    <Input
                        id="grace-minutes"
                        v-model="settingsForm.grace_minutes"
                        inputmode="numeric"
                        class="min-h-11 sm:min-h-9"
                        aria-describedby="grace-help"
                    />
                    <p id="grace-help" class="text-xs text-muted-foreground">
                        Minutes after the shift starts before a clock-in counts
                        as late, and before the end for leaving early.
                    </p>
                    <InputError :message="errors.grace_minutes" />
                </div>
                <div class="grid gap-1">
                    <Label for="forgotten-window"
                        >Forgotten clock-out after (minutes) *</Label
                    >
                    <Input
                        id="forgotten-window"
                        v-model="settingsForm.forgotten_window_minutes"
                        inputmode="numeric"
                        class="min-h-11 sm:min-h-9"
                        aria-describedby="forgotten-help"
                    />
                    <p
                        id="forgotten-help"
                        class="text-xs text-muted-foreground"
                    >
                        Minutes after the shift ends before "No clock-out"
                        shows. Nothing is closed automatically.
                    </p>
                    <InputError :message="errors.forgotten_window_minutes" />
                </div>
                <div class="grid gap-1">
                    <Label for="correction-days"
                        >Corrections up to (days back) *</Label
                    >
                    <Input
                        id="correction-days"
                        v-model="settingsForm.correction_days"
                        inputmode="numeric"
                        class="min-h-11 sm:min-h-9"
                        aria-describedby="correction-help"
                    />
                    <p
                        id="correction-help"
                        class="text-xs text-muted-foreground"
                    >
                        How far back staff may propose a corrected time.
                    </p>
                    <InputError :message="errors.correction_days" />
                </div>
                <div class="grid gap-1">
                    <Label for="effective-from">Applies from *</Label>
                    <Input
                        id="effective-from"
                        v-model="settingsForm.effective_from"
                        type="date"
                        class="min-h-11 w-48 sm:min-h-9"
                        aria-describedby="effective-help"
                    />
                    <p
                        id="effective-help"
                        class="text-xs text-muted-foreground"
                    >
                        Past days keep the settings they had.
                    </p>
                    <InputError :message="errors.effective_from" />
                </div>
                <div>
                    <Button
                        type="submit"
                        class="min-h-11 sm:min-h-9"
                        :disabled="settingsForm.processing"
                    >
                        {{
                            settingsForm.processing
                                ? 'Saving…'
                                : 'Save settings'
                        }}
                    </Button>
                </div>
            </form>
            <div
                v-if="attendance.settings.history.length > 0"
                class="flex flex-col gap-2"
            >
                <h3 class="text-sm font-semibold">Saved versions</h3>
                <ul class="flex flex-col gap-1 text-sm">
                    <li v-for="v in attendance.settings.history" :key="v.id">
                        From {{ v.effectiveFrom }}: {{ v.summary }}
                        <span class="text-muted-foreground">({{ v.by }})</span>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Clock out confirm (Daniel: only Clock out asks) -->
        <OperationalConfirmDialog
            v-model:open="clock.confirmOut"
            title="Clock out now?"
            description="Your clock-out time is the server time when you confirm."
            confirm-label="Clock out"
            processing-label="Clocking out…"
            :processing="clock.processing"
            @confirm="press('out')"
        >
            <template #icon>
                <Clock class="size-5" />
            </template>
        </OperationalConfirmDialog>

        <!-- Decision -->
        <OperationalConfirmDialog
            v-model:open="decision.open"
            :title="
                decision.approve
                    ? 'Approve this correction?'
                    : 'Reject this correction?'
            "
            :description="
                decision.target
                    ? `${decision.target.person} · ${decision.target.dayLabel} · ${decision.target.compare}`
                    : ''
            "
            :confirm-label="
                decision.approve ? 'Approve correction' : 'Reject correction'
            "
            :processing-label="decision.approve ? 'Approving…' : 'Rejecting…'"
            :processing="decision.processing"
            :error="decision.error"
            @confirm="confirmDecision"
        >
            <template #icon>
                <CalendarCheck class="size-5" />
            </template>
            <div v-if="!decision.approve" class="grid gap-2">
                <Label for="decision-reason">Reason *</Label>
                <OperationalSelect
                    id="decision-reason"
                    v-model="decision.reason"
                    :options="attendance.rejectReasons"
                    label="Reason"
                    placeholder="Choose a reason"
                />
            </div>
        </OperationalConfirmDialog>

        <!-- Correction sheet -->
        <Sheet v-model:open="correction.open">
            <SheetContent
                side="right"
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="correction-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        correction.person
                            ? `Correct times for ${correction.person}`
                            : 'Correct my times'
                    }}</SheetTitle>
                    <SheetDescription
                        >Someone else approves it. Your original presses stay on
                        record.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="grid gap-4 px-4 pb-6"
                    @submit.prevent="sendCorrection"
                >
                    <ErrorState
                        v-if="correctionErrors.attendance"
                        :message="correctionErrors.attendance"
                    />
                    <div
                        v-if="
                            correction.fromReview && correction.sessionId === ''
                        "
                        class="grid gap-1"
                    >
                        <Label for="correction-person">Staff member *</Label>
                        <OperationalSelect
                            id="correction-person"
                            v-model="correction.userId"
                            :options="staffOptions"
                            label="Staff member"
                            placeholder="Choose a staff member"
                        />
                        <InputError :message="correctionErrors.user_id" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="correction-kind">What to correct *</Label>
                        <OperationalSelect
                            id="correction-kind"
                            v-model="correction.kind"
                            :options="kindOptions"
                            label="What to correct"
                        />
                        <InputError
                            :message="
                                correctionErrors.kind ??
                                correctionErrors.session_id
                            "
                        />
                    </div>
                    <div
                        v-if="correction.kind !== 'set_out'"
                        class="grid gap-1"
                    >
                        <Label for="correction-in"
                            >Clock-in date and time *</Label
                        >
                        <Input
                            id="correction-in"
                            v-model="correction.inTime"
                            type="datetime-local"
                            class="min-h-11 sm:min-h-9"
                        />
                        <InputError :message="correctionErrors.in_time" />
                    </div>
                    <div
                        v-if="correction.kind !== 'change_in'"
                        class="grid gap-1"
                    >
                        <Label for="correction-out"
                            >Clock-out date and time *</Label
                        >
                        <Input
                            id="correction-out"
                            v-model="correction.outTime"
                            type="datetime-local"
                            class="min-h-11 sm:min-h-9"
                        />
                        <InputError :message="correctionErrors.out_time" />
                    </div>
                    <p
                        class="text-sm tabular-nums"
                        data-test="correction-compare"
                    >
                        {{ compareLine }}
                    </p>
                    <InputError :message="correctionErrors.branch_id" />
                    <div class="grid gap-1">
                        <Label for="correction-reason">Reason *</Label>
                        <OperationalSelect
                            id="correction-reason"
                            v-model="correction.reason"
                            :options="attendance.reasons"
                            label="Reason"
                            placeholder="Choose a reason"
                        />
                        <InputError :message="correctionErrors.reason_code" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="correction-note">Note</Label>
                        <Textarea
                            id="correction-note"
                            v-model="correction.note"
                            maxlength="200"
                            rows="3"
                            aria-describedby="correction-note-help"
                        />
                        <p
                            id="correction-note-help"
                            class="text-xs text-muted-foreground"
                        >
                            Do not write medical details here.
                        </p>
                        <InputError :message="correctionErrors.note" />
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            @click="correction.open = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="min-h-11 sm:min-h-9"
                            :disabled="correction.processing"
                        >
                            {{
                                correction.processing
                                    ? 'Sending…'
                                    : 'Send for approval'
                            }}
                        </Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>
