<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ClockPhotoNotice from '@/components/hr/attendance/ClockPhotoNotice.vue';
import ClockPhotoThumb from '@/components/hr/attendance/ClockPhotoThumb.vue';
import MyOvertimeSection from '@/components/hr/attendance/MyOvertime.vue';
import OvertimeAskSheet from '@/components/hr/attendance/OvertimeAskSheet.vue';
import { weekTone } from '@/components/hr/attendance/types';
import LocationCheckCardV2 from '@/components/hr/attendance/v2/LocationCheckCardV2.vue';
import OvertimeRulesCardV2 from '@/components/hr/attendance/v2/OvertimeRulesCardV2.vue';
import OvertimeTabV2 from '@/components/hr/attendance/v2/OvertimeTabV2.vue';
import PhotoStorageCardV2 from '@/components/hr/attendance/v2/PhotoStorageCardV2.vue';
import SummaryTabV2 from '@/components/hr/attendance/v2/SummaryTabV2.vue';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2TimeField from '@/components/v2/V2TimeField.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useAttendancePage } from '@/composables/hr/useAttendancePage';
import type {
    AttendanceProps,
    Correction,
    Row,
} from '@/composables/hr/useAttendancePage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import {
    glueTimes,
    shiftParts,
    splitLocal,
    updatePair,
} from '@/lib/hr-attendance-v2';

/**
 * Version 2 Attendance (shown only in V2; Classic is `pages/HR/Attendance.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `useAttendancePage`, which Classic uses
 * too. No backend change. My attendance, Review, Settings' attendance rules and every dialog are V2; Overtime, Summary
 * and the overtime and location settings cards show their current components inside the V2 frame until the next PR
 * (Hui Min's sketch, Daniel to confirm the split).
 */
const props = defineProps<AttendanceProps>();

const {
    errors,
    clockNotes,
    userName,
    tabs,
    visit,
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
    choiceFor,
    openAsk,
    openAskFor,
    settingsForm,
    saveSettingsKeepingState,
    reviewBranchOptions,
    staffOptions,
    reviewQuery,
} = useAttendancePage(props);

usePageCrumbs(() => [
    { title: 'KPOne HR', href: '/hr' },
    { title: 'Attendance' },
]);

const correctionTone: Record<
    Correction['status'],
    'neutral' | 'success' | 'danger'
> = {
    pending: 'neutral',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

// The correction form shows each press as a date and a time; the request keeps Classic's one local value. The parts
// are page state, so a half-filled pair keeps what was typed while the request value stays '' until both are set.
const parts = reactive({
    in: { date: '', time: '' },
    out: { date: '', time: '' },
});
watch(
    () => correction.open,
    (open) => {
        if (open) {
            Object.assign(parts.in, splitLocal(correction.inTime));
            Object.assign(parts.out, splitLocal(correction.outTime));
        }
    },
    { immediate: true },
);
const setIn = (change: { date?: string; time?: string }) =>
    (correction.inTime = updatePair(parts.in, change));
const setOut = (change: { date?: string; time?: string }) =>
    (correction.outTime = updatePair(parts.out, change));

const decisionTitle = computed(() =>
    decision.approve ? 'Approve this correction?' : 'Reject this correction?',
);
const decisionLine = computed(() =>
    decision.target
        ? `${decision.target.person} · ${decision.target.dayLabel} · ${decision.target.compare}`
        : '',
);

const pressWord = computed(() => (clock.consentFor === 'out' ? 'out' : 'in'));
const flagsOf = (r: Row) => r.flags.length > 0 || r.correctionWaiting;

// Settings (Daniel): the attendance rules card's Save is filled only while the card has changes, and unsaved changes
// are guarded on a tab change (an in-page visit) as well as on leaving the page.
const rulesValue = () =>
    JSON.stringify([
        settingsForm.grace_minutes,
        settingsForm.forgotten_window_minutes,
        settingsForm.correction_days,
        settingsForm.effective_from,
    ]);
// The saved baseline comes from the server's settings, never from the form, so a reload in place cannot clear the guard.
const savedRules = () => {
    const s = props.attendance.settings;

    return JSON.stringify([
        String(s?.current?.graceMinutes ?? s?.suggested.graceMinutes ?? ''),
        String(
            s?.current?.forgottenWindowMinutes ??
                s?.suggested.forgottenWindowMinutes ??
                '',
        ),
        String(s?.current?.correctionDays ?? s?.suggested.correctionDays ?? ''),
        s?.today ?? '',
    ]);
};
const rulesSaved = computed(savedRules);
const rulesDirty = computed(
    () =>
        props.attendance.tab === 'settings' &&
        rulesValue() !== rulesSaved.value,
);
const resetRules = () => {
    const [grace, window, days, from] = JSON.parse(
        rulesSaved.value,
    ) as string[];
    Object.assign(settingsForm, {
        grace_minutes: grace,
        forgotten_window_minutes: window,
        correction_days: days,
        effective_from: from,
    });
};
// PR 2 (Daniel): three Settings cards, each with its own named Save, dirty guard and busy state, but only ONE filled
// Save at a time: the first card from the top that has changes (or has never been saved) keeps it. A card below a
// changed one shows an outline Save and "You also have unsaved changes above" (only when a card above has changes).
// Each Save keeps the page's state, so the other cards keep their unsaved typing (Daniel). The leave guard covers all three.
const overtimeDirty = ref(false);
const locationDirty = ref(false);
const overtimeCard = ref<InstanceType<typeof OvertimeRulesCardV2> | null>(null);
const locationCard = ref<InstanceType<typeof LocationCheckCardV2> | null>(null);
const filledCard = computed<'rules' | 'overtime' | 'location' | null>(() => {
    const s = props.attendance.settings;

    if (!s) {
        return null;
    }

    if (rulesDirty.value || !s.current) {
        return 'rules';
    }

    if (overtimeDirty.value || !s.overtime.current) {
        return 'overtime';
    }

    return locationDirty.value ? 'location' : null;
});
const settingsDirty = computed(
    () =>
        props.attendance.tab === 'settings' &&
        (rulesDirty.value || overtimeDirty.value || locationDirty.value),
);
const discardSettings = () => {
    resetRules();
    overtimeCard.value?.reset();
    locationCard.value?.reset();
};
const guard = ref<InstanceType<typeof V2LeaveGuard> | null>(null);
const changeTab = (tab: string) =>
    guard.value ? guard.value.ask(() => selectTab(tab)) : selectTab(tab);

// A rejection needs a reason: the filled Reject stays reachable but does nothing until one is picked (Daniel).
const decisionBlocked = computed(
    () => !decision.approve && decision.reason === '',
);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const ghostButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] px-3 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const clockButton =
    'inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-[12px] bg-primary px-4 text-base font-semibold text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50';
const textLink =
    'inline-flex min-h-11 items-center self-start text-sm font-medium text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const numberClass =
    'h-11 w-28 rounded-[10px] border border-input bg-card px-3 text-base tabular-nums outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
</script>

<template>
    <Head title="Attendance" />
    <PageFrame>
        <V2WorkLayout
            title="Attendance"
            description="Clock in and out on your own account. Someone else approves each correction."
        >
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="attendance.tab"
                        :tabs="tabs"
                        ariaLabel="Attendance sections"
                        @update:model-value="changeTab"
                    />

                    <p
                        aria-live="polite"
                        class="sr-only"
                        data-test="clock-announcer"
                    >
                        {{ announcement }}
                    </p>

                    <p
                        v-if="
                            errors.attendance &&
                            !correction.open &&
                            !decision.open
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.attendance }}
                    </p>

                    <!-- My attendance -->
                    <section
                        v-if="attendance.tab === 'my' && attendance.my"
                        aria-labelledby="v2-my-attendance"
                        class="flex max-w-[40rem] flex-col gap-4"
                    >
                        <h2 id="v2-my-attendance" class="sr-only">
                            My attendance
                        </h2>

                        <ul
                            v-if="credentialReminders?.length"
                            class="flex flex-col gap-1 text-sm"
                            data-test="credential-reminders"
                        >
                            <li v-for="r in credentialReminders" :key="r">
                                <Link
                                    href="/hr/credentials"
                                    class="text-primary underline underline-offset-4"
                                    >{{ r }}</Link
                                >
                            </li>
                        </ul>

                        <V2DisclosureBar
                            v-if="clockNotes"
                            :title="
                                attendance.my.staleLabel ??
                                'Attendance is not set up yet'
                            "
                            tone="warning"
                        >
                            <p
                                v-if="!attendance.my.settingsReady"
                                class="text-sm"
                            >
                                Attendance is not set up yet. Your times are
                                still recorded; late and early flags start once
                                HR saves the settings.
                            </p>
                            <p
                                v-if="attendance.my.staleLabel"
                                class="text-sm"
                                data-test="stale"
                            >
                                {{ attendance.my.staleLabel }}
                            </p>
                        </V2DisclosureBar>

                        <!-- The clock card: one filled action, 56 px -->
                        <section
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4 sm:p-5"
                            aria-labelledby="v2-clock-title"
                            data-test="clock-card"
                        >
                            <h3 id="v2-clock-title" class="sr-only">
                                Clock in and out
                            </h3>
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
                                class="text-lg font-semibold"
                                data-test="clocked-in"
                            >
                                {{ attendance.my.clockedInLabel }}
                            </p>
                            <p
                                v-if="attendance.my.state === 'done'"
                                class="text-lg font-semibold"
                                data-test="done-today"
                            >
                                {{ attendance.my.doneLabel }}
                            </p>
                            <button
                                v-if="attendance.my.state === 'out'"
                                type="button"
                                :class="clockButton"
                                :disabled="
                                    !attendance.my.canPress || clock.processing
                                "
                                data-test="clock-in"
                                @click="press('in')"
                            >
                                {{
                                    clock.locating
                                        ? 'Checking location…'
                                        : clock.processing
                                          ? 'Clocking in…'
                                          : 'Clock in'
                                }}
                            </button>
                            <button
                                v-else-if="attendance.my.state === 'in'"
                                type="button"
                                :class="clockButton"
                                :disabled="clock.processing"
                                data-test="clock-out"
                                @click="clock.confirmOut = true"
                            >
                                {{
                                    clock.locating
                                        ? 'Checking location…'
                                        : clock.processing
                                          ? 'Clocking out…'
                                          : 'Clock out'
                                }}
                            </button>
                            <p
                                v-if="errors.clock"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ errors.clock }}
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
                            <div
                                class="flex flex-col gap-1 text-[13px] text-muted-foreground"
                            >
                                <p
                                    class="text-sm text-foreground tabular-nums"
                                    data-test="server-time"
                                >
                                    Server time {{ serverTime }}
                                </p>
                                <p v-if="attendance.my.todayShift">
                                    Today: {{ attendance.my.todayShift }}
                                </p>
                                <p v-else>No approved shift today.</p>
                                <p
                                    v-if="attendance.my.location"
                                    data-test="location-note"
                                >
                                    This branch checks your location when you
                                    press. Only inside, outside or not available
                                    is kept, never where you are.
                                </p>
                                <p
                                    v-if="attendance.my.location?.lastResult"
                                    data-test="location-last"
                                >
                                    {{ attendance.my.location.lastResult }}
                                </p>
                                <p data-test="privacy-line">
                                    KPOne records the time and a photo of you at
                                    each press. HR can see it. Kept for 7 years
                                    (to confirm).
                                </p>
                                <p data-test="break-line">
                                    Stay clocked in for your break; KPOne takes
                                    the rostered break off.
                                </p>
                                <button
                                    type="button"
                                    :class="textLink"
                                    data-test="clock-photo-about"
                                    @click="aboutPhotos = true"
                                >
                                    About clock-in photos
                                </button>
                                <p
                                    v-if="
                                        attendance.my.state !== 'out' ||
                                        attendance.my.doneLabel
                                    "
                                >
                                    Signed in as {{ userName }}. Sign out if
                                    this is a shared computer.
                                </p>
                            </div>
                        </section>

                        <V2FormCard
                            v-if="attendance.my.attention.length > 0"
                            title="Needs your clock-out time"
                            data-test="attention"
                        >
                            <ul class="flex flex-col divide-y">
                                <li
                                    v-for="r in attendance.my.attention"
                                    :key="r.key"
                                    class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                                >
                                    <span
                                        >{{ r.dateLabel }} · Clocked in at
                                        <span
                                            class="whitespace-nowrap tabular-nums"
                                            >{{
                                                glueTimes(r.session?.in)
                                            }}</span
                                        ></span
                                    >
                                    <button
                                        type="button"
                                        :class="button"
                                        @click="openCorrection(r)"
                                    >
                                        Add clock-out time<span class="sr-only">
                                            for {{ r.dateLabel }}</span
                                        >
                                    </button>
                                </li>
                            </ul>
                        </V2FormCard>

                        <!-- This week -->
                        <div
                            class="flex flex-wrap items-end justify-between gap-2"
                        >
                            <div class="flex flex-col gap-1">
                                <h3 class="text-base font-semibold">
                                    This week
                                    <span
                                        class="text-sm font-normal text-muted-foreground"
                                        >· {{ attendance.my.weekLabel }}</span
                                    >
                                </h3>
                                <p
                                    class="flex flex-wrap items-center gap-2 text-sm"
                                    data-test="week-hours"
                                >
                                    <span class="tabular-nums"
                                        >{{
                                            attendance.my.overtime.weekHours
                                                .label
                                        }}
                                        this week</span
                                    >
                                    <V2Chip
                                        v-if="
                                            attendance.my.overtime.weekHours
                                                .chip
                                        "
                                        :tone="
                                            weekTone(
                                                attendance.my.overtime.weekHours
                                                    .level,
                                            )
                                        "
                                        :label="
                                            attendance.my.overtime.weekHours
                                                .chip
                                        "
                                    />
                                </p>
                            </div>
                            <button
                                v-if="
                                    attendance.my.settingsReady &&
                                    attendance.my.branch
                                "
                                type="button"
                                :class="button"
                                data-test="add-day"
                                @click="openForgottenDay(false)"
                            >
                                Add a day I forgot to clock
                            </button>
                        </div>
                        <p
                            v-if="attendance.my.week.length === 0"
                            class="rounded-[14px] border bg-card p-6 text-center text-sm text-muted-foreground"
                        >
                            No shifts or clock times this week yet. Your
                            approved shifts and clock times appear here.
                        </p>
                        <ul
                            v-else
                            class="flex flex-col gap-2"
                            data-test="my-week"
                        >
                            <li
                                v-for="r in attendance.my.week"
                                :key="r.key"
                                class="flex flex-col gap-2 rounded-[14px] border bg-card p-4"
                            >
                                <h4 class="text-sm font-semibold">
                                    {{ r.dateLabel }}
                                </h4>
                                <div
                                    class="flex min-w-0 flex-col gap-1 text-sm"
                                >
                                    <p v-if="r.shift">
                                        Shift
                                        {{ shiftParts(r.shift.label).name }}
                                        <span
                                            class="whitespace-nowrap tabular-nums"
                                            >{{
                                                glueTimes(
                                                    shiftParts(r.shift.label)
                                                        .times,
                                                )
                                            }}</span
                                        ><span v-if="r.shift.branch">
                                            · {{ r.shift.branch }}</span
                                        >
                                    </p>
                                    <p v-else class="text-muted-foreground">
                                        No shift
                                    </p>
                                    <p v-if="r.session" class="tabular-nums">
                                        <span class="whitespace-nowrap"
                                            >In
                                            {{ glueTimes(r.session.in) }}</span
                                        >
                                        ·
                                        <span class="whitespace-nowrap"
                                            >Out
                                            {{
                                                glueTimes(r.session.out ?? '–')
                                            }}</span
                                        ><template v-if="r.worked">
                                            ·
                                            <span class="whitespace-nowrap"
                                                >Worked
                                                {{ glueTimes(r.worked) }}</span
                                            ></template
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
                                        v-if="flagsOf(r)"
                                        class="flex flex-wrap gap-1.5"
                                    >
                                        <V2Chip
                                            v-for="f in r.flags"
                                            :key="f.code"
                                            tone="warning"
                                            :label="f.label"
                                        />
                                        <V2Chip
                                            v-if="r.correctionWaiting"
                                            tone="neutral"
                                            label="Correction waiting"
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
                                    <button
                                        v-if="choiceFor(r)"
                                        type="button"
                                        :class="button"
                                        data-test="ask-overtime"
                                        @click="openAsk(r.session.publicId)"
                                    >
                                        Ask for overtime<span class="sr-only">
                                            for {{ r.dateLabel }}</span
                                        >
                                    </button>
                                    <button
                                        type="button"
                                        :class="button"
                                        @click="openCorrection(r)"
                                    >
                                        {{
                                            r.needsClockOut
                                                ? 'Add clock-out time'
                                                : 'Correct'
                                        }}<span class="sr-only">
                                            for {{ r.dateLabel }}</span
                                        >
                                    </button>
                                </div>
                            </li>
                        </ul>

                        <section
                            v-if="attendance.my.corrections.length > 0"
                            aria-labelledby="v2-my-corrections"
                            class="flex flex-col gap-2"
                        >
                            <h3
                                id="v2-my-corrections"
                                class="text-base font-semibold"
                            >
                                My corrections
                            </h3>
                            <ul
                                class="flex flex-col divide-y rounded-[14px] border bg-card"
                                data-test="my-corrections"
                            >
                                <li
                                    v-for="c in attendance.my.corrections"
                                    :key="c.publicId"
                                    class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                                >
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <span class="font-medium"
                                            >{{ c.dayLabel }} ·
                                            {{ c.kindLabel }}</span
                                        >
                                        <span
                                            class="text-muted-foreground tabular-nums"
                                            >{{ c.compare }} ·
                                            {{ c.reason }}</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <V2Chip
                                            :tone="correctionTone[c.status]"
                                            :label="c.statusLabel"
                                        />
                                        <button
                                            v-if="c.canCancel"
                                            type="button"
                                            :class="ghostButton"
                                            :disabled="withdrawing !== ''"
                                            @click="cancelCorrection(c)"
                                        >
                                            Withdraw<span class="sr-only">
                                                correction for
                                                {{ c.dayLabel }}</span
                                            >
                                        </button>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <div
                            v-if="
                                attendance.my.overtime.eligible &&
                                attendance.my.overtime.choices.length > 0
                            "
                            class="flex flex-wrap items-center justify-between gap-2 rounded-[14px] border bg-card p-4 text-sm"
                            data-test="overtime-days"
                        >
                            <span
                                >{{
                                    attendance.my.overtime.choices.length === 1
                                        ? '1 day'
                                        : `${attendance.my.overtime.choices.length} days`
                                }}
                                with time beyond your roster can still be asked
                                for.</span
                            >
                            <button
                                type="button"
                                :class="button"
                                @click="
                                    openAsk(
                                        attendance.my.overtime.choices[0]
                                            .sessionId,
                                    )
                                "
                            >
                                Ask for overtime
                            </button>
                        </div>
                        <MyOvertimeSection :data="attendance.my.overtime" />
                    </section>

                    <!-- Review -->
                    <section
                        v-if="attendance.tab === 'review' && attendance.review"
                        aria-labelledby="v2-review-title"
                        class="flex flex-col gap-4"
                    >
                        <h2 id="v2-review-title" class="sr-only">
                            Attendance review
                        </h2>
                        <p
                            v-if="!attendance.review.branch"
                            class="rounded-[14px] border bg-card p-6 text-center text-sm text-muted-foreground"
                        >
                            No active branch. Add a branch first.
                        </p>
                        <template v-else>
                            <div
                                class="flex flex-wrap items-end gap-3"
                                data-test="review-filters"
                            >
                                <div class="flex flex-col gap-1.5">
                                    <span
                                        id="v2-review-branch"
                                        class="text-sm font-medium"
                                        >Branch</span
                                    >
                                    <OperationalSelect
                                        :model-value="
                                            attendance.review.branch.id
                                        "
                                        :options="reviewBranchOptions"
                                        label="Branch"
                                        aria-labelledby="v2-review-branch"
                                        class="min-w-48"
                                        @update:model-value="
                                            (v) =>
                                                visit({
                                                    branch: v,
                                                    date:
                                                        attendance.review
                                                            ?.date ?? undefined,
                                                })
                                        "
                                    />
                                </div>
                                <div
                                    class="flex items-end gap-2"
                                    role="group"
                                    aria-label="Day"
                                >
                                    <button
                                        type="button"
                                        :class="[button, 'gap-1.5']"
                                        @click="
                                            visit({
                                                branch: attendance.review
                                                    ?.branch?.id,
                                                date: attendance.review
                                                    ?.prevDate,
                                            })
                                        "
                                    >
                                        <span aria-hidden="true">‹</span
                                        ><span class="max-sm:sr-only"
                                            >Previous day</span
                                        >
                                    </button>
                                    <V2DateField
                                        :model-value="
                                            attendance.review.date ?? ''
                                        "
                                        label="Day"
                                        @update:model-value="
                                            (value: string) =>
                                                value &&
                                                visit({
                                                    branch: attendance.review
                                                        ?.branch?.id,
                                                    date: value,
                                                })
                                        "
                                    />
                                    <button
                                        type="button"
                                        :class="[button, 'gap-1.5']"
                                        @click="
                                            visit({
                                                branch: attendance.review
                                                    ?.branch?.id,
                                                date: attendance.review
                                                    ?.nextDate,
                                            })
                                        "
                                    >
                                        <span class="max-sm:sr-only"
                                            >Next day</span
                                        ><span aria-hidden="true">›</span>
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    :class="[button, 'sm:ml-auto']"
                                    :disabled="!attendance.review.settingsReady"
                                    data-test="review-add-day"
                                    @click="openForgottenDay(true)"
                                >
                                    Add a forgotten day
                                </button>
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
                                v-if="attendance.review.settingsReady === false"
                                class="text-sm text-muted-foreground"
                            >
                                Attendance is not set up yet. Flags start once
                                the settings are saved.
                            </p>
                            <V2Table
                                label="Roster against attendance"
                                :columns="7"
                                cards="xl"
                                min-width="960px"
                                :empty="attendance.review.rows.length === 0"
                                empty-message="Nobody is on the roster or clocked in on this day."
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
                                        <td data-primary data-label="Person">
                                            <span class="font-medium">{{
                                                r.person
                                            }}</span>
                                            <div
                                                v-if="r.week?.chip"
                                                class="mt-1"
                                            >
                                                <V2Chip
                                                    :tone="
                                                        weekTone(r.week.level)
                                                    "
                                                    :label="r.week.chip"
                                                />
                                            </div>
                                        </td>
                                        <td
                                            data-label="Shift"
                                            class="tabular-nums"
                                        >
                                            <template v-if="r.shift"
                                                ><span
                                                    v-if="
                                                        shiftParts(
                                                            r.shift.label,
                                                        ).name
                                                    "
                                                    class="block"
                                                    >{{
                                                        shiftParts(
                                                            r.shift.label,
                                                        ).name
                                                    }}</span
                                                ><span
                                                    class="whitespace-nowrap"
                                                    >{{
                                                        glueTimes(
                                                            shiftParts(
                                                                r.shift.label,
                                                            ).times,
                                                        )
                                                    }}</span
                                                ></template
                                            >
                                            <template v-else>No shift</template>
                                        </td>
                                        <td
                                            data-label="In"
                                            class="[&_button]:whitespace-nowrap"
                                        >
                                            <div
                                                class="flex flex-wrap items-center gap-2 tabular-nums"
                                            >
                                                <span
                                                    class="whitespace-nowrap"
                                                    >{{
                                                        glueTimes(
                                                            r.session?.in ??
                                                                '–',
                                                        )
                                                    }}</span
                                                >
                                                <ClockPhotoThumb
                                                    v-if="r.photos?.in"
                                                    :photo="r.photos.in"
                                                    :name="`Clock-in photo of ${r.person}`"
                                                />
                                            </div>
                                            <p
                                                v-if="r.location?.in"
                                                class="text-[13px] text-muted-foreground"
                                                data-test="review-location-in"
                                            >
                                                {{ r.location.in }}
                                            </p>
                                        </td>
                                        <td
                                            data-label="Out"
                                            class="[&_button]:whitespace-nowrap"
                                        >
                                            <div
                                                class="flex flex-wrap items-center gap-2 tabular-nums"
                                            >
                                                <span
                                                    class="whitespace-nowrap"
                                                    >{{
                                                        glueTimes(
                                                            r.session?.out ??
                                                                '–',
                                                        )
                                                    }}</span
                                                >
                                                <ClockPhotoThumb
                                                    v-if="r.photos?.out"
                                                    :photo="r.photos.out"
                                                    :name="`Clock-out photo of ${r.person}`"
                                                />
                                            </div>
                                            <p
                                                v-if="r.location?.out"
                                                class="text-[13px] text-muted-foreground"
                                                data-test="review-location-out"
                                            >
                                                {{ r.location.out }}
                                            </p>
                                        </td>
                                        <td
                                            data-label="Worked"
                                            class="whitespace-nowrap tabular-nums"
                                        >
                                            {{ glueTimes(r.worked ?? '–') }}
                                        </td>
                                        <td
                                            data-label="Flags"
                                            class="whitespace-nowrap"
                                        >
                                            <div
                                                v-if="flagsOf(r)"
                                                class="flex flex-wrap gap-1.5"
                                            >
                                                <V2Chip
                                                    v-for="f in r.flags"
                                                    :key="f.code"
                                                    tone="warning"
                                                    :label="f.label"
                                                    class="text-nowrap!"
                                                />
                                                <V2Chip
                                                    v-if="r.correctionWaiting"
                                                    tone="neutral"
                                                    label="Correction waiting"
                                                    class="text-nowrap!"
                                                />
                                            </div>
                                            <span
                                                v-else
                                                class="text-muted-foreground"
                                                >None</span
                                            >
                                        </td>
                                        <td data-actions>
                                            <div
                                                class="flex flex-wrap justify-end gap-2"
                                            >
                                                <button
                                                    v-if="r.overtimeChoice"
                                                    type="button"
                                                    :class="button"
                                                    data-test="review-ask-overtime"
                                                    @click="openAskFor(r)"
                                                >
                                                    Ask for overtime<span
                                                        class="sr-only"
                                                    >
                                                        for {{ r.person }}</span
                                                    >
                                                </button>
                                                <button
                                                    v-if="
                                                        r.session &&
                                                        !r.correctionWaiting &&
                                                        attendance.review
                                                            .settingsReady
                                                    "
                                                    type="button"
                                                    :class="button"
                                                    @click="
                                                        openCorrection(r, true)
                                                    "
                                                >
                                                    Correct<span
                                                        class="sr-only"
                                                    >
                                                        times for
                                                        {{ r.person }}</span
                                                    >
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </V2Table>
                            <p
                                v-if="reviewHasOthersPhotos"
                                class="text-[13px] text-muted-foreground"
                                data-test="review-photo-recorded"
                            >
                                Showing a photo is recorded.
                            </p>

                            <V2FormCard
                                title="Corrections waiting"
                                data-test="pending-corrections"
                            >
                                <p
                                    v-if="
                                        attendance.review.pending.length === 0
                                    "
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
                                        <div
                                            class="flex min-w-0 flex-col gap-1"
                                        >
                                            <span class="font-medium"
                                                >{{ c.person }} ·
                                                {{ c.dayLabel }} ·
                                                {{ c.branch }}</span
                                            >
                                            <span>{{ c.kindLabel }}</span>
                                            <span
                                                class="text-muted-foreground tabular-nums"
                                                >{{ c.compare }}</span
                                            >
                                            <span class="text-muted-foreground">
                                                {{ c.reason
                                                }}<span v-if="c.note"
                                                    >: {{ c.note }}</span
                                                ><span v-if="c.onBehalf">
                                                    · Proposed by
                                                    {{ c.proposedBy }}</span
                                                >
                                            </span>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <template v-if="c.canDecide">
                                                <button
                                                    type="button"
                                                    :class="button"
                                                    @click="
                                                        openDecision(c, true)
                                                    "
                                                >
                                                    Approve<span
                                                        class="sr-only"
                                                    >
                                                        correction for
                                                        {{ c.person }}</span
                                                    >
                                                </button>
                                                <button
                                                    type="button"
                                                    :class="button"
                                                    @click="
                                                        openDecision(c, false)
                                                    "
                                                >
                                                    Reject<span class="sr-only">
                                                        correction for
                                                        {{ c.person }}</span
                                                    >
                                                </button>
                                            </template>
                                            <button
                                                v-else-if="c.canCancel"
                                                type="button"
                                                :class="ghostButton"
                                                :disabled="withdrawing !== ''"
                                                @click="
                                                    cancelCorrection(c, true)
                                                "
                                            >
                                                Withdraw<span class="sr-only">
                                                    correction for
                                                    {{ c.person }}</span
                                                >
                                            </button>
                                            <span
                                                v-else
                                                class="text-[13px] text-muted-foreground"
                                                >Someone else decides this
                                                one.</span
                                            >
                                        </div>
                                    </li>
                                </ul>
                            </V2FormCard>
                        </template>
                    </section>

                    <!-- Overtime and Summary (PR 2) -->
                    <OvertimeTabV2
                        v-if="
                            attendance.tab === 'overtime' && attendance.overtime
                        "
                        :data="attendance.overtime"
                        :options="attendance.overtimeOptions"
                        :error="errors.overtime"
                    />
                    <SummaryTabV2
                        v-if="
                            attendance.tab === 'summary' && attendance.summary
                        "
                        :data="attendance.summary"
                    />

                    <!-- Settings -->
                    <section
                        v-if="
                            attendance.tab === 'settings' && attendance.settings
                        "
                        aria-labelledby="v2-settings-title"
                        class="flex max-w-[40rem] flex-col gap-4"
                    >
                        <h2 id="v2-settings-title" class="sr-only">
                            Attendance settings
                        </h2>
                        <V2FormCard
                            title="Attendance rules"
                            :description="
                                attendance.settings.current
                                    ? `In force since ${attendance.settings.current.effectiveFrom}.`
                                    : 'Not set up yet. The numbers below are suggestions from the clinic HR proposal; nothing counts until you save.'
                            "
                            data-test="settings-form"
                        >
                            <form
                                class="flex flex-col gap-4"
                                @submit.prevent="
                                    saveSettingsKeepingState(resetRules)
                                "
                            >
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Grace minutes"
                                    required
                                    hint="Minutes after the shift starts before a clock-in counts as late, and before the end for leaving early."
                                    :error="errors.grace_minutes"
                                >
                                    <input
                                        :id="id"
                                        v-model="settingsForm.grace_minutes"
                                        inputmode="numeric"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        :class="numberClass"
                                    />
                                </V2Field>
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Forgotten clock-out after (minutes)"
                                    required
                                    hint='Minutes after the shift ends before "No clock-out" shows. Nothing is closed automatically.'
                                    :error="errors.forgotten_window_minutes"
                                >
                                    <input
                                        :id="id"
                                        v-model="
                                            settingsForm.forgotten_window_minutes
                                        "
                                        inputmode="numeric"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        :class="numberClass"
                                    />
                                </V2Field>
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Corrections up to (days back)"
                                    required
                                    hint="How far back staff may propose a corrected time."
                                    :error="errors.correction_days"
                                >
                                    <input
                                        :id="id"
                                        v-model="settingsForm.correction_days"
                                        inputmode="numeric"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        :class="numberClass"
                                    />
                                </V2Field>
                                <V2DateField
                                    v-model="settingsForm.effective_from"
                                    label="Applies from"
                                    required
                                    hint="Past days keep the settings they had."
                                    :error="errors.effective_from"
                                />
                                <div>
                                    <button
                                        type="submit"
                                        :class="
                                            filledCard === 'rules'
                                                ? primaryButton
                                                : button
                                        "
                                        :disabled="settingsForm.processing"
                                    >
                                        {{
                                            settingsForm.processing
                                                ? 'Saving…'
                                                : 'Save attendance rules'
                                        }}
                                    </button>
                                </div>
                            </form>
                            <div
                                v-if="attendance.settings.history.length > 0"
                                class="mt-4 flex flex-col gap-1 border-t pt-3"
                            >
                                <h3 class="text-sm font-semibold">
                                    Saved versions
                                </h3>
                                <ul
                                    class="flex flex-col gap-1 text-[13px] text-muted-foreground"
                                >
                                    <li
                                        v-for="v in attendance.settings.history"
                                        :key="v.id"
                                    >
                                        From {{ v.effectiveFrom }}:
                                        {{ v.summary }} ({{ v.by }})
                                    </li>
                                </ul>
                            </div>
                        </V2FormCard>
                        <OvertimeRulesCardV2
                            ref="overtimeCard"
                            :card="attendance.settings.overtime"
                            :today="attendance.settings.today"
                            :primary="filledCard === 'overtime'"
                            :others-above="rulesDirty"
                            @dirty="overtimeDirty = $event"
                        />
                        <LocationCheckCardV2
                            v-if="attendance.settings.location"
                            ref="locationCard"
                            :card="attendance.settings.location"
                            :primary="filledCard === 'location'"
                            :others-above="rulesDirty || overtimeDirty"
                            @dirty="locationDirty = $event"
                        />
                        <PhotoStorageCardV2
                            v-if="attendance.settings.photoStorage"
                            :card="attendance.settings.photoStorage"
                        />
                    </section>
                </div>
            </template>
        </V2WorkLayout>
        <V2LeaveGuard
            ref="guard"
            :dirty="settingsDirty"
            @discard="discardSettings"
        />

        <!-- Clock out (only Clock out asks) -->
        <V2Dialog
            :open="clock.confirmOut"
            title="Clock out now?"
            description="Your clock-out time is the server time when you confirm."
            confirm-label="Clock out"
            :busy="clock.processing"
            @update:open="clock.confirmOut = $event"
            @confirm="press('out')"
        />

        <!-- HR-16a: consent once per version, before the first press at this branch. Two equal choices, neither
             preselected; closing records nothing and gives no consent. -->
        <V2Dialog
            :open="clock.consentFor !== null"
            title="Location check at this branch"
            :description="`Read this once. You can clock ${pressWord} without it.`"
            :confirm-label="`Clock ${pressWord} without location`"
            single-action
            single-action-style="outline"
            @update:open="(open: boolean) => !open && (clock.consentFor = null)"
            @confirm="withoutLocation(clock.consentFor ?? 'in')"
        >
            <div
                class="flex flex-col gap-4 text-sm"
                data-test="location-consent"
            >
                <p
                    class="text-[13px] text-muted-foreground"
                    data-test="location-consent-draft"
                >
                    Draft wording until the clinic's PDPA review. Synthetic data
                    only.
                </p>
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
                <button
                    type="button"
                    :class="button"
                    data-test="location-agree"
                    @click="locate(clock.consentFor ?? 'in', true)"
                >
                    I understand, check my location
                </button>
            </div>
        </V2Dialog>

        <!-- Decision: Approve or Reject; a rejection needs a reason -->
        <V2Dialog
            :open="decision.open"
            :title="decisionTitle"
            :description="decisionLine"
            :confirm-label="
                decision.approve ? 'Approve correction' : 'Reject correction'
            "
            :tone="decision.approve ? 'default' : 'danger'"
            :busy="decision.processing"
            :confirm-disabled="decisionBlocked"
            confirm-disabled-reason="Pick a reason to continue."
            @update:open="decision.open = $event"
            @confirm="confirmDecision"
        >
            <V2Field
                v-if="!decision.approve"
                v-slot="{ id }"
                label="Reason"
                required
                :error="decision.error || undefined"
            >
                <OperationalSelect
                    :id="id"
                    v-model="decision.reason"
                    :options="attendance.rejectReasons"
                    label="Reason"
                    placeholder="Choose a reason"
                />
            </V2Field>
            <p
                v-else-if="decision.error"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ decision.error }}
            </p>
        </V2Dialog>

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

        <!-- Correction -->
        <V2Dialog
            :open="correction.open"
            :title="
                correction.person
                    ? `Correct times for ${correction.person}`
                    : 'Correct my times'
            "
            description="Someone else approves it. Your original presses stay on record."
            confirm-label="Send for approval"
            size="tall"
            :busy="correction.processing"
            @update:open="correction.open = $event"
            @confirm="sendCorrection"
        >
            <form
                class="flex flex-col gap-4"
                data-test="correction-sheet"
                @submit.prevent="sendCorrection"
            >
                <p
                    v-if="correctionErrors.attendance"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ correctionErrors.attendance }}
                </p>
                <V2Field
                    v-if="correction.fromReview && correction.sessionId === ''"
                    v-slot="{ id }"
                    label="Staff member"
                    required
                    :error="correctionErrors.user_id"
                >
                    <OperationalSelect
                        :id="id"
                        v-model="correction.userId"
                        :options="staffOptions"
                        label="Staff member"
                        placeholder="Choose a staff member"
                    />
                </V2Field>
                <V2OptionGroup
                    :model-value="correction.kind"
                    :options="kindOptions"
                    label="What to correct"
                    required
                    :error="
                        correctionErrors.kind ?? correctionErrors.session_id
                    "
                    @update:model-value="
                        correction.kind = $event as typeof correction.kind
                    "
                />
                <fieldset
                    v-if="correction.kind !== 'set_out'"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <legend class="mb-1.5 text-sm font-medium">Clock-in</legend>
                    <V2DateField
                        :model-value="parts.in.date"
                        label="Date"
                        required
                        @update:model-value="setIn({ date: $event })"
                    />
                    <V2TimeField
                        :model-value="parts.in.time"
                        label="Time"
                        required
                        :error="correctionErrors.in_time"
                        @update:model-value="setIn({ time: $event })"
                    />
                </fieldset>
                <fieldset
                    v-if="correction.kind !== 'change_in'"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <legend class="mb-1.5 text-sm font-medium">
                        Clock-out
                    </legend>
                    <V2DateField
                        :model-value="parts.out.date"
                        label="Date"
                        required
                        @update:model-value="setOut({ date: $event })"
                    />
                    <V2TimeField
                        :model-value="parts.out.time"
                        label="Time"
                        required
                        :error="correctionErrors.out_time"
                        @update:model-value="setOut({ time: $event })"
                    />
                </fieldset>
                <p
                    class="text-sm text-muted-foreground tabular-nums"
                    data-test="correction-compare"
                >
                    {{ compareLine }}
                </p>
                <p
                    v-if="correctionErrors.branch_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ correctionErrors.branch_id }}
                </p>
                <V2Field
                    v-slot="{ id }"
                    label="Reason"
                    required
                    :error="correctionErrors.reason_code"
                >
                    <OperationalSelect
                        :id="id"
                        v-model="correction.reason"
                        :options="attendance.reasons"
                        label="Reason"
                        placeholder="Choose a reason"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Note"
                    hint="Do not write medical details here."
                    :error="correctionErrors.note"
                >
                    <textarea
                        :id="id"
                        v-model="correction.note"
                        maxlength="200"
                        rows="3"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="[inputClass, 'h-auto py-2']"
                    />
                </V2Field>
            </form>
        </V2Dialog>

        <Dialog v-model:open="aboutPhotos">
            <DialogContent
                class="flex max-h-[90vh] max-w-[min(32rem,calc(100%-2rem))] flex-col p-0"
                :show-close-button="false"
                data-test="clock-photo-about-dialog"
            >
                <ClockPhotoNotice mode="info" @cancel="aboutPhotos = false" />
            </DialogContent>
        </Dialog>
    </PageFrame>
</template>
