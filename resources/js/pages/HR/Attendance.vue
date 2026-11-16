<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarCheck, CalendarX, Clock } from '@lucide/vue';
import ClockPhotoNotice from '@/components/hr/attendance/ClockPhotoNotice.vue';
import ClockPhotoThumb from '@/components/hr/attendance/ClockPhotoThumb.vue';
import LocationCheckSettings from '@/components/hr/attendance/LocationCheckSettings.vue';
import MyOvertimeSection from '@/components/hr/attendance/MyOvertime.vue';
import OvertimeAskSheet from '@/components/hr/attendance/OvertimeAskSheet.vue';
import OvertimeSettingsForm from '@/components/hr/attendance/OvertimeSettingsForm.vue';
import OvertimeTab from '@/components/hr/attendance/OvertimeTab.vue';
import SummaryTab from '@/components/hr/attendance/SummaryTab.vue';
import type {
    LocationCheckCard,
    MyOvertime,
    OvertimeOptions,
    OvertimeSettingsCard,
    OvertimeTabData,
    SummaryTabData,
} from '@/components/hr/attendance/types';
import { weekTone } from '@/components/hr/attendance/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField, DateTimeField } from '@/components/ui/date-field';
import { Dialog, DialogContent } from '@/components/ui/dialog';
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
import { useAttendancePage } from '@/composables/hr/useAttendancePage';
import type {
    Tab,
    Option,
    Row,
    Correction,
} from '@/composables/hr/useAttendancePage';

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
            undo: {
                sessionId: string;
                lockVersion: number;
                pressedAt: string;
                until: string;
            } | null;
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
        } | null;
        overtime: OvertimeTabData | null;
        summary: SummaryTabData | null;
    };
}>();

const {
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
    compareLine,
    openCorrection,
    openForgottenDay,
    sendCorrection,
    withdrawing,
    cancelCorrection,
    decision,
    undo,
    openUndo,
    confirmUndo,
    openDecision,
    confirmDecision,
    ask,
    choiceFor,
    openAsk,
    openAskFor,
    settingsForm,
    saveSettings,
    reviewBranchOptions,
    staffOptions,
    correctionStatusTone,
} = useAttendancePage(props);
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
                    <!-- Undo clock-out (owner, 16 Nov 2026): for 15 minutes after the person's own clock-out. -->
                    <div
                        v-if="attendance.my.undo"
                        class="flex flex-col gap-1"
                        data-test="undo-clock-out"
                    >
                        <Button
                            variant="outline"
                            class="min-h-11 self-start"
                            data-test="undo-clock-out-button"
                            @click="openUndo"
                        >
                            Undo clock-out
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            Clocked out by mistake? You can undo this until
                            {{ attendance.my.undo.until }}.
                        </p>
                    </div>
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
                        HR can see it. Kept for 7 years (to confirm).
                    </p>
                    <!-- HR month dry run S1 (owner, 16 Nov 2026): the break comes off the worked time, so nobody clocks out for it. -->
                    <p
                        v-if="attendance.my.todayHasBreak"
                        class="text-xs text-muted-foreground"
                        data-test="break-line"
                    >
                        Stay clocked in for your break; KPOne takes the rostered
                        break off.
                    </p>
                    <button
                        type="button"
                        class="self-start text-xs font-medium text-brand-strong underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                                        <p
                                            v-if="r.undoneLabel"
                                            class="text-xs text-muted-foreground"
                                            data-test="review-undone"
                                        >
                                            {{ r.undoneLabel }}
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
                            <p
                                v-if="r.undoneLabel"
                                class="text-xs text-muted-foreground"
                                data-test="review-undone"
                            >
                                {{ r.undoneLabel }}
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
                    <p
                        v-if="reviewHasOthersPhotos"
                        class="text-xs text-muted-foreground"
                        data-test="review-photo-recorded"
                    >
                        Showing a photo is recorded.
                    </p>
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
            v-model:open="undo.open"
            :title="`Undo your clock-out at ${attendance.my?.undo?.pressedAt ?? ''}?`"
            description="You stay clocked in from your clock-in. Clock out again when you leave; that takes a new photo."
            confirm-label="Undo clock-out"
            processing-label="Undoing…"
            :processing="undo.processing"
            :error="undo.error"
            @confirm="confirmUndo"
        >
            <template #icon>
                <Clock class="size-5" />
            </template>
        </OperationalConfirmDialog>

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
        <Dialog v-model:open="aboutPhotos">
            <DialogContent
                class="flex max-h-[90vh] max-w-[min(32rem,calc(100%-2rem))] flex-col p-0"
                :show-close-button="false"
                data-test="clock-photo-about-dialog"
            >
                <ClockPhotoNotice mode="info" @cancel="aboutPhotos = false" />
            </DialogContent>
        </Dialog>
    </div>
</template>
