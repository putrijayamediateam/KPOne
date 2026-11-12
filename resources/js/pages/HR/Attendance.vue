<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CalendarCheck, CalendarX, Clock } from '@lucide/vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import ClockPhotoThumb from '@/components/hr/attendance/ClockPhotoThumb.vue';
import type { ClockPhotoEntry } from '@/components/hr/attendance/ClockPhotoThumb.vue';
import LocationCheckSettings from '@/components/hr/attendance/LocationCheckSettings.vue';
import MyOvertimeSection from '@/components/hr/attendance/MyOvertime.vue';
import OvertimeAskSheet from '@/components/hr/attendance/OvertimeAskSheet.vue';
import OvertimeSettingsForm from '@/components/hr/attendance/OvertimeSettingsForm.vue';
import OvertimeTab from '@/components/hr/attendance/OvertimeTab.vue';
import SummaryTab from '@/components/hr/attendance/SummaryTab.vue';
import type {
    LocationCheckCard,
    MyOvertime,
    OvertimeChoice,
    OvertimeOptions,
    OvertimeSettingsCard,
    OvertimeTabData,
    SummaryTabData,
    WeekHours,
} from '@/components/hr/attendance/types';
import { weekTone } from '@/components/hr/attendance/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField, DateTimeField } from '@/components/ui/date-field';
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
import { useClockPhoto } from '@/composables/useClockPhoto';
import { useScrollStrip } from '@/composables/useScrollStrip';

type Tab = 'my' | 'review' | 'overtime' | 'summary' | 'settings';
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
    overtimeChoice?: OvertimeChoice | null;
    week?: WeekHours | null;
    /** HR-PHOTO: each press's photo; anyone else's needs Show. */
    photos?: { in: ClockPhotoEntry | null; out: ClockPhotoEntry | null } | null;
    /** HR-16a: each press's location result in plain words; never a position. */
    location?: {
        in: string | null;
        out: string | null;
        outside: boolean;
    } | null;
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
    /** HR-5a: your own expiring or expired credentials, in words. */
    credentialReminders?: string[];
    attendance: {
        tab: Tab;
        tabs: Tab[];
        pendingCount: number;
        kinds: Option[];
        reasons: Option[];
        rejectReasons: Option[];
        overtimeOptions: OvertimeOptions;
        overtimePendingCount: number;
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
            overtime: MyOvertime;
            /** HR-16a: on at the branch the next press goes to. */
            location: {
                consentNeeded: boolean;
                consentEn: string | null;
                consentBm: string | null;
                /** Your own latest result, in plain words. */
                lastResult: string | null;
            } | null;
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
            overtime: OvertimeSettingsCard;
            location: LocationCheckCard | null;
        } | null;
        overtime: OvertimeTabData | null;
        summary: SummaryTabData | null;
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
    overtime: 'Overtime',
    summary: 'Summary',
    settings: 'Settings',
};
const tabs = computed(() =>
    props.attendance.tabs.map((value) => ({
        value,
        label:
            value === 'review' && props.attendance.pendingCount > 0
                ? `Review (${props.attendance.pendingCount})`
                : value === 'overtime' &&
                    props.attendance.overtimePendingCount > 0
                  ? `Overtime (${props.attendance.overtimePendingCount})`
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

// ---- Tabs: the shared scroll strip brings the current tab into view and fades the hidden edge (Daniel, HR-PHOTO) ----
const tabStrip = useTemplateRef<HTMLElement>('tabStrip');
const tabNav = ref<HTMLElement | null>(null);
onMounted(() => {
    tabNav.value =
        tabStrip.value?.querySelector<HTMLElement>('[role="tablist"]') ?? null;
});
const { measure: measureTabs, fadeStyle: tabFade } = useScrollStrip(
    tabNav,
    '[aria-selected="true"]',
);
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
    tabNav.value?.addEventListener('scroll', measureTabs, { passive: true });
});
onBeforeUnmount(() => {
    clearInterval(timer);
    tabNav.value?.removeEventListener('scroll', measureTabs);
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
const clock = reactive({
    processing: false,
    confirmOut: false,
    key: '',
    locating: false,
    consentFor: null as 'in' | 'out' | null,
});
// HR-16a: where the branch checks location, the browser reads it once, at the press, and never before or after. A
// refusal, a failure or "without location" still records the press.
const press = (kind: 'in' | 'out') => {
    const location = props.attendance.my?.location;

    if (!location) {
        send(kind, {});

        return;
    }

    if (location.consentNeeded) {
        clock.confirmOut = false;
        clock.consentFor = kind;

        return;
    }

    locate(kind, false);
};
const locate = (kind: 'in' | 'out', consent: boolean) => {
    clock.consentFor = null;
    const extra: Record<string, unknown> = consent
        ? { location_consent: true }
        : {};

    if (!('geolocation' in navigator)) {
        send(kind, { ...extra, location_unavailable: 'failed' });

        return;
    }

    clock.locating = true;
    clock.processing = true;
    navigator.geolocation.getCurrentPosition(
        (position) => {
            clock.locating = false;
            send(kind, {
                ...extra,
                clock_position: {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                },
            });
        },
        (error) => {
            clock.locating = false;
            send(kind, {
                ...extra,
                location_unavailable: error.code === 1 ? 'denied' : 'failed',
            });
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
    );
};
const withoutLocation = (kind: 'in' | 'out') => {
    clock.consentFor = null;
    send(kind, { location_unavailable: 'declined' });
};
// HR-PHOTO: every press goes through the camera; the location result (HR-16a) rides along with the photo.
const { open: openCamera } = useClockPhoto();
const send = (kind: 'in' | 'out', extra: Record<string, unknown>) => {
    clock.confirmOut = false;
    clock.processing = false;
    openCamera(kind, {
        extra,
        onDone: () => router.reload({ preserveScroll: true } as never),
    });
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

// ---- HR-3b overtime ----
const ask = reactive({
    open: false,
    sessionId: '',
    fromReview: false,
    person: '',
    userId: 0,
    choices: [] as OvertimeChoice[],
});
const myChoices = computed(() => props.attendance.my?.overtime.choices ?? []);
const choiceFor = (row: Row) =>
    row.session
        ? (myChoices.value.find((c) => c.sessionId === row.session?.publicId) ??
          null)
        : null;
const openAsk = (sessionId: string) =>
    Object.assign(ask, {
        open: true,
        sessionId,
        fromReview: false,
        person: '',
        userId: 0,
        choices: myChoices.value,
    });
const openAskFor = (row: Row) => {
    if (!row.overtimeChoice) {
        return;
    }

    Object.assign(ask, {
        open: true,
        sessionId: row.overtimeChoice.sessionId,
        fromReview: true,
        person: row.person ?? '',
        userId: row.userId,
        choices: [row.overtimeChoice],
    });
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
                :style="tabFade"
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
            <!-- HR-5a: your expiring or expired credentials, in words; inside KPOne only. -->
            <ul
                v-if="credentialReminders?.length"
                class="flex flex-col gap-1 text-sm"
                data-test="credential-reminders"
            >
                <li v-for="r in credentialReminders" :key="r">
                    <Link
                        href="/hr/credentials"
                        class="underline underline-offset-4"
                        >{{ r }}</Link
                    >
                </li>
            </ul>

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
                        {{
                            clock.locating
                                ? 'Checking location…'
                                : clock.processing
                                  ? 'Clocking in…'
                                  : 'Clock in'
                        }}
                    </Button>
                    <Button
                        v-else-if="attendance.my.state === 'in'"
                        class="min-h-14 w-full text-base"
                        :disabled="clock.processing"
                        data-test="clock-out"
                        @click="clock.confirmOut = true"
                    >
                        <Clock class="size-5" />
                        {{
                            clock.locating
                                ? 'Checking location…'
                                : clock.processing
                                  ? 'Clocking out…'
                                  : 'Clock out'
                        }}
                    </Button>
                    <InputError :message="errors.clock" />
                    <p
                        v-if="attendance.my.location"
                        class="text-sm text-muted-foreground"
                        data-test="location-note"
                    >
                        This branch checks your location when you press. Only
                        inside, outside or not available is kept, never where
                        you are.
                    </p>
                    <p
                        v-if="attendance.my.location?.lastResult"
                        class="text-sm text-muted-foreground"
                        data-test="location-last"
                    >
                        {{ attendance.my.location.lastResult }}
                    </p>
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
                    <!-- HR-PHOTO: shown at every branch, beside the location note where the branch checks location. -->
                    <p
                        class="text-xs text-muted-foreground"
                        data-test="privacy-line"
                    >
                        KPOne records the time and a photo of you at each press.
                        HR can see it. Kept for 7 years.
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
                <div class="flex flex-col gap-1">
                    <h3 class="text-base font-semibold">
                        This week
                        <span
                            class="text-sm font-normal text-muted-foreground"
                            >{{ attendance.my.weekLabel }}</span
                        >
                    </h3>
                    <p
                        class="flex flex-wrap items-center gap-2 text-sm"
                        data-test="week-hours"
                    >
                        <span class="tabular-nums"
                            >{{ attendance.my.overtime.weekHours.label }} this
                            week</span
                        >
                        <StatusBadge
                            v-if="attendance.my.overtime.weekHours.chip"
                            status="week"
                            :label="attendance.my.overtime.weekHours.chip"
                            :tone="
                                weekTone(attendance.my.overtime.weekHours.level)
                            "
                        />
                    </p>
                </div>
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
                            v-if="r.photos?.in || r.photos?.out"
                            class="flex flex-wrap items-center gap-3"
                            data-test="my-photos"
                        >
                            <ClockPhotoThumb
                                v-if="r.photos?.in"
                                :photo="r.photos.in"
                                :name="`Your clock-in photo, ${r.dateLabel}`"
                            />
                            <ClockPhotoThumb
                                v-if="r.photos?.out"
                                :photo="r.photos.out"
                                :name="`Your clock-out photo, ${r.dateLabel}`"
                            />
                        </div>
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
                        class="flex flex-col gap-2 sm:flex-row"
                    >
                        <Button
                            v-if="choiceFor(r)"
                            variant="outline"
                            class="min-h-11 w-full sm:min-h-8 sm:w-auto"
                            data-test="ask-overtime"
                            @click="openAsk(r.session.publicId)"
                        >
                            Ask for overtime<span class="sr-only">
                                for {{ r.dateLabel }}</span
                            >
                        </Button>
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

            <div
                v-if="
                    attendance.my.overtime.eligible &&
                    attendance.my.overtime.choices.length > 0
                "
                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-card p-4 text-sm"
                data-test="overtime-days"
            >
                <span
                    >{{
                        attendance.my.overtime.choices.length === 1
                            ? '1 day'
                            : `${attendance.my.overtime.choices.length} days`
                    }}
                    with time beyond your roster can still be asked for.</span
                >
                <Button
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    @click="
                        openAsk(attendance.my.overtime.choices[0].sessionId)
                    "
                >
                    Ask for overtime
                </Button>
            </div>
            <MyOvertimeSection :data="attendance.my.overtime" />
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
                            <DateField
                                id="review-date"
                                :model-value="attendance.review.date ?? ''"
                                @change="
                                    (value: string) =>
                                        visit({
                                            branch: attendance.review?.branch
                                                ?.id,
                                            date: value,
                                        })
                                "
                                :echo="false"
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
                                    <td class="font-medium">
                                        {{ r.person }}
                                        <StatusBadge
                                            v-if="r.week?.chip"
                                            status="week"
                                            :label="r.week.chip"
                                            :tone="weekTone(r.week.level)"
                                            class="mt-1"
                                        />
                                    </td>
                                    <td>{{ r.shift?.label ?? 'No shift' }}</td>
                                    <td class="min-w-64 tabular-nums">
                                        <div
                                            class="flex flex-wrap items-center gap-2 xl:flex-nowrap"
                                        >
                                            <span>{{
                                                r.session?.in ?? '–'
                                            }}</span>
                                            <ClockPhotoThumb
                                                v-if="r.photos?.in"
                                                :photo="r.photos.in"
                                                :name="`Clock-in photo of ${r.person}`"
                                            />
                                        </div>
                                        <p
                                            v-if="r.location?.in"
                                            class="text-xs text-muted-foreground"
                                            data-test="review-location-in"
                                        >
                                            {{ r.location.in }}
                                        </p>
                                    </td>
                                    <td class="min-w-64 tabular-nums">
                                        <div
                                            class="flex flex-wrap items-center gap-2 xl:flex-nowrap"
                                        >
                                            <span>{{
                                                r.session?.out ?? '–'
                                            }}</span>
                                            <ClockPhotoThumb
                                                v-if="r.photos?.out"
                                                :photo="r.photos.out"
                                                :name="`Clock-out photo of ${r.person}`"
                                            />
                                        </div>
                                        <p
                                            v-if="r.location?.out"
                                            class="text-xs text-muted-foreground"
                                            data-test="review-location-out"
                                        >
                                            {{ r.location.out }}
                                        </p>
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
                                        <div
                                            class="flex flex-wrap justify-end gap-2"
                                        >
                                            <Button
                                                v-if="r.overtimeChoice"
                                                variant="outline"
                                                size="sm"
                                                data-test="review-ask-overtime"
                                                @click="openAskFor(r)"
                                            >
                                                Ask for overtime<span
                                                    class="sr-only"
                                                >
                                                    for {{ r.person }}</span
                                                >
                                            </Button>
                                            <Button
                                                v-if="
                                                    r.session &&
                                                    !r.correctionWaiting &&
                                                    attendance.review
                                                        .settingsReady
                                                "
                                                variant="outline"
                                                size="sm"
                                                @click="openCorrection(r, true)"
                                            >
                                                Correct<span class="sr-only">
                                                    times for
                                                    {{ r.person }}</span
                                                >
                                            </Button>
                                        </div>
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
                            <StatusBadge
                                v-if="r.week?.chip"
                                status="week"
                                :label="r.week.chip"
                                :tone="weekTone(r.week.level)"
                            />
                            <p>{{ r.shift?.label ?? 'No shift' }}</p>
                            <p class="tabular-nums">
                                In {{ r.session?.in ?? '–' }} · Out
                                {{ r.session?.out ?? '–'
                                }}<span v-if="r.worked">
                                    · Worked {{ r.worked }}</span
                                >
                            </p>
                            <div
                                v-if="r.photos?.in || r.photos?.out"
                                class="flex flex-col gap-2"
                                data-test="review-card-photos"
                            >
                                <!-- One line each, named In and Out, so each Show or Hide belongs to its own press (Daniel). -->
                                <div
                                    v-if="r.photos?.in"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <span
                                        class="w-8 text-xs text-muted-foreground"
                                        >In</span
                                    >
                                    <ClockPhotoThumb
                                        :photo="r.photos.in"
                                        :name="`Clock-in photo of ${r.person}`"
                                    />
                                </div>
                                <div
                                    v-if="r.photos?.out"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <span
                                        class="w-8 text-xs text-muted-foreground"
                                        >Out</span
                                    >
                                    <ClockPhotoThumb
                                        :photo="r.photos.out"
                                        :name="`Clock-out photo of ${r.person}`"
                                    />
                                </div>
                            </div>
                            <p
                                v-if="r.location?.in"
                                class="text-xs text-muted-foreground"
                            >
                                Clock in: {{ r.location.in }}
                            </p>
                            <p
                                v-if="r.location?.out"
                                class="text-xs text-muted-foreground"
                            >
                                Clock out: {{ r.location.out }}
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
                                v-if="r.overtimeChoice"
                                variant="outline"
                                class="mt-2 min-h-11 w-full"
                                @click="openAskFor(r)"
                            >
                                Ask for overtime<span class="sr-only">
                                    for {{ r.person }}</span
                                >
                            </Button>
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

        <!-- HR-3b Overtime and Summary -->
        <OvertimeTab
            v-if="attendance.tab === 'overtime' && attendance.overtime"
            :data="attendance.overtime"
            :options="attendance.overtimeOptions"
            :error="errors.overtime"
        />
        <SummaryTab
            v-if="attendance.tab === 'summary' && attendance.summary"
            :data="attendance.summary"
        />

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
                    <DateField
                        id="effective-from"
                        v-model="settingsForm.effective_from"
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
                        variant="outline"
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
            <OvertimeSettingsForm
                :card="attendance.settings.overtime"
                :today="attendance.settings.today"
            />
            <LocationCheckSettings
                v-if="attendance.settings.location"
                :card="attendance.settings.location"
            />
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

        <!-- HR-16a: consent once per version, before the first press at this branch -->
        <Sheet
            :open="clock.consentFor !== null"
            @update:open="(open: boolean) => !open && (clock.consentFor = null)"
        >
            <SheetContent
                side="bottom"
                class="flex max-h-[90dvh] flex-col sm:mx-auto sm:max-w-xl sm:rounded-t-xl"
                data-test="location-consent"
            >
                <SheetHeader>
                    <SheetTitle>Location check at this branch</SheetTitle>
                    <SheetDescription>
                        Read this once. You can clock
                        {{ clock.consentFor === 'out' ? 'out' : 'in' }} without
                        it.
                    </SheetDescription>
                    <p
                        class="text-xs text-muted-foreground"
                        data-test="location-consent-draft"
                    >
                        Draft wording until the clinic's PDPA review. Synthetic
                        data only.
                    </p>
                </SheetHeader>
                <!-- Closing with X records nothing and gives no consent; the sheet opens again at the next press (Daniel). -->
                <div
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4 text-sm"
                    data-test="location-consent-text"
                >
                    <section class="flex flex-col gap-1">
                        <h3 class="font-medium">English</h3>
                        <p class="whitespace-pre-line">
                            {{ attendance.my?.location?.consentEn }}
                        </p>
                    </section>
                    <section class="flex flex-col gap-1" lang="ms">
                        <h3 class="font-medium">Bahasa Melayu</h3>
                        <p class="whitespace-pre-line">
                            {{ attendance.my?.location?.consentBm }}
                        </p>
                    </section>
                </div>
                <!-- Pinned below the text; two equal choices, neither preselected (Daniel). -->
                <div
                    class="grid shrink-0 gap-2 border-t p-4 sm:grid-cols-2"
                    data-test="location-consent-actions"
                >
                    <Button
                        variant="outline"
                        class="min-h-11"
                        data-test="location-without"
                        @click="withoutLocation(clock.consentFor ?? 'in')"
                    >
                        Clock
                        {{ clock.consentFor === 'out' ? 'out' : 'in' }}
                        without location
                    </Button>
                    <Button
                        variant="outline"
                        class="min-h-11"
                        data-test="location-agree"
                        @click="locate(clock.consentFor ?? 'in', true)"
                    >
                        I understand, check my location
                    </Button>
                </div>
            </SheetContent>
        </Sheet>

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
                <CalendarCheck v-if="decision.approve" class="size-5" />
                <CalendarX v-else class="size-5" />
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

        <OvertimeAskSheet
            v-model:open="ask.open"
            :choices="ask.choices"
            :reasons="attendance.overtimeOptions.reasons"
            :session-id="ask.sessionId"
            :person="ask.person"
            :user-id="ask.userId"
            :from-review="ask.fromReview"
            :review-query="reviewQuery"
        />

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
                        <DateTimeField
                            id="correction-in"
                            v-model="correction.inTime"
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
                        <DateTimeField
                            id="correction-out"
                            v-model="correction.outTime"
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
