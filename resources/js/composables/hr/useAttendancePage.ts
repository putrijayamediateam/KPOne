import { router, usePage } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import type { ClockPhotoEntry } from '@/components/hr/attendance/ClockPhotoThumb.vue';
import type {
    LocationCheckCard,
    MyOvertime,
    OvertimeChoice,
    OvertimeOptions,
    OvertimeSettingsCard,
    OvertimeTabData,
    PhotoStorageCard,
    SummaryTabData,
    WeekHours,
} from '@/components/hr/attendance/types';
import { useClockPhoto } from '@/composables/useClockPhoto';
import { useScrollStrip } from '@/composables/useScrollStrip';

/**
 * The Attendance page's state and actions, shared by Classic (`pages/HR/Attendance.vue`) and Version 2
 * (`pages/HR/V2/Attendance.vue`), so both send the same requests with the same lock versions and idempotency keys.
 * Moved out of the Classic page unchanged; the Classic template did not change. The `tabStrip` template ref is read
 * here through `useTemplateRef`, so a page that shows the tab strip names its wrapper `ref="tabStrip"`.
 */
export type Tab = 'my' | 'review' | 'overtime' | 'summary' | 'settings';
export type Kind = 'change_in' | 'set_out' | 'add_day';
export type Option = { value: string; label: string };
export type Flag = { code: string; label: string };
export type Row = {
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
export type Correction = {
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

export type AttendanceProps = {
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
            todayHasBreak: boolean;
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
            photoStorage?: PhotoStorageCard;
        } | null;
        overtime: OvertimeTabData | null;
        summary: SummaryTabData | null;
    };
};

export const useAttendancePage = (props: AttendanceProps) => {
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
            tabStrip.value?.querySelector<HTMLElement>('[role="tablist"]') ??
            null;
    });
    const { measure: measureTabs, fadeStyle: tabFade } = useScrollStrip(
        tabNav,
        '[aria-selected="true"]',
    );
    const selectTab = (tab: string) => visit({ tab });

    // HR-PHOTO (Daniel): reopen the notice from My attendance; and say that Show is recorded when the day has anyone else's photo.
    const aboutPhotos = ref(false);
    const reviewHasOthersPhotos = computed(() =>
        (props.attendance.review?.rows ?? []).some(
            (r) => r.photos?.in?.needsShow || r.photos?.out?.needsShow,
        ),
    );

    // ---- Server time (Daniel: ticks every second, never in a live region) ----
    const offset = ref(0);
    const tick = ref(Date.now());
    let timer: ReturnType<typeof setInterval> | undefined;
    const syncClock = () => {
        if (props.attendance.my) {
            offset.value =
                Date.parse(props.attendance.my.serverNow) - Date.now();
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
        tabNav.value?.addEventListener('scroll', measureTabs, {
            passive: true,
        });
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
                // When the same response carries a toast (this page's own press), the toast's live region reads it, so the
                // region here stays quiet and the change is read once. A change from elsewhere, with no toast, is still read.
                if (
                    (page as unknown as { flash?: { toast?: unknown } }).flash
                        ?.toast
                ) {
                    announcement.value = '';

                    return;
                }

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
                // One line, as when this lived in the Classic page (the hr-16a test reads it).
                // prettier-ignore
                send(kind, { ...extra, location_unavailable: error.code === 1 ? 'denied' : 'failed' });
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
                    correction.kind === 'add_day'
                        ? correction.branchId
                        : undefined,
                in_time:
                    correction.kind === 'set_out'
                        ? undefined
                        : correction.inTime,
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
    const myChoices = computed(
        () => props.attendance.my?.overtime.choices ?? [],
    );
    const choiceFor = (row: Row) =>
        row.session
            ? (myChoices.value.find(
                  (c) => c.sessionId === row.session?.publicId,
              ) ?? null)
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
    // Version 2 uses saveSettingsKeepingState: the page keeps its state through the save, so the other Settings cards keep
    // their unsaved typing, and `onSaved` puts this card back to the saved values (also for a version dated later).
    // Classic keeps saveSettings, unchanged.
    const sendSettings = (preserveState: boolean, onSaved?: () => void) => {
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
                ...(preserveState ? { preserveState: true } : {}),
                onSuccess: () => {
                    settingsForm.key = crypto.randomUUID();
                    onSaved?.();
                },
                onFinish: () => (settingsForm.processing = false),
            },
        );
    };
    const saveSettings = () => sendSettings(false);
    const saveSettingsKeepingState = (onSaved?: () => void) =>
        sendSettings(true, onSaved);

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

    return {
        errors,
        clockNotes,
        userName,
        tabs,
        reviewQuery,
        visit,
        tabFade,
        selectTab,
        aboutPhotos,
        reviewHasOthersPhotos,
        serverTime,
        serverDate,
        announcement,
        clock,
        press,
        locate,
        withoutLocation,
        correction,
        correctionErrors,
        kindOptions,
        formatLocal,
        compareLine,
        openCorrection,
        openForgottenDay,
        sendCorrection,
        withdrawing,
        cancelCorrection,
        decision,
        openDecision,
        confirmDecision,
        ask,
        myChoices,
        choiceFor,
        openAsk,
        openAskFor,
        settingsForm,
        saveSettings,
        saveSettingsKeepingState,
        reviewBranchOptions,
        staffOptions,
        correctionStatusTone,
    };
};
