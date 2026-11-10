<script setup lang="ts">
// HR-9 /hr/dashboard: read-only aggregates for the Director and HR Manager (docs/teams/hr/HR-9-DESIGN.md).
// Today per branch, then the month. No salary, no leave type or reason, no score of a person.
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';

type Person = {
    name: string;
    role?: string;
    until?: string;
    since?: string;
    half?: boolean;
};
type Today = {
    date: string;
    rostered: {
        count: number;
        doctors: number;
        people: Person[];
        noDoctor: boolean;
    } | null;
    clockedIn: { count: number; people: Person[] } | null;
    onLeave: { count: number; people: Person[] } | null;
    staffing: {
        state:
            | 'enough'
            | 'short'
            | 'not_set'
            | 'no_roster'
            | 'no_shift_now'
            | 'closed';
        messages: string[];
    } | null;
};
type MonthFigures = {
    attendance: {
        daysClocked: number;
        late: number;
        leftEarly: number;
        noClockIn: number;
        noClockOut: number;
    } | null;
    overtime: { approvedMinutes: number } | null;
    leave: { days: number; people: number } | null;
    shortfallDays: number | null;
};
type BranchCard = {
    id: number;
    name: string;
    today: Today | null;
    month: MonthFigures;
};

const props = defineProps<{
    dashboard: {
        can: { roster: boolean; attendance: boolean; leave: boolean };
        now: string;
        month: string;
        months: { value: string; label: string }[];
        attendanceSettingsSaved: boolean;
        branches: BranchCard[];
    };
}>();

const monthLabel = computed(
    () =>
        props.dashboard.months.find((m) => m.value === props.dashboard.month)
            ?.label ?? props.dashboard.month,
);

function chooseMonth(value: string | number) {
    router.get(
        '/hr/dashboard',
        { month: String(value) },
        { preserveScroll: true, preserveState: true },
    );
}

function hours(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return m === 0 ? `${h} h` : `${h} h ${m} min`;
}

function days(value: number): string {
    return `${value} ${value === 1 ? 'day' : 'days'}`;
}
</script>

<template>
    <Head title="HR dashboard" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="HR dashboard"
                description="Who is on duty today at each branch, and the month at a glance. Read only; it changes nothing."
            />
            <SyntheticNote />
        </div>

        <div class="flex flex-wrap items-end justify-between gap-3">
            <p class="text-sm text-muted-foreground" data-test="as-of">
                As of {{ dashboard.now }} (Kuala Lumpur time).
            </p>
            <div class="grid gap-1">
                <Label for="dashboard-month">Month</Label>
                <OperationalSelect
                    id="dashboard-month"
                    :model-value="dashboard.month"
                    :options="dashboard.months"
                    label="Month"
                    class="min-w-48"
                    @update:model-value="chooseMonth"
                />
            </div>
        </div>

        <p class="text-xs text-muted-foreground" data-test="rules-note">
            Enough or Short compares the people on today's shifts with the
            minimum staffing for the sessions that are on now, as the roster
            Planner does. Minimums apply to every day, so a day with a roster at
            a branch that is closed can read Short. Short days are counted from
            approved rosters only, by today's minimums.
        </p>

        <EmptyState
            v-if="dashboard.branches.length === 0"
            title="No active branch."
            description="Add a branch first."
        />

        <section
            v-for="branch in dashboard.branches"
            :key="branch.id"
            class="rounded-xl border bg-card p-4"
            :aria-labelledby="`branch-${branch.id}`"
            data-test="branch-card"
        >
            <h2 :id="`branch-${branch.id}`" class="text-lg font-semibold">
                {{ branch.name }}
            </h2>

            <div v-if="branch.today" class="mt-3 grid gap-3" data-test="today">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-semibold">
                        Today, {{ branch.today.date }}
                    </h3>
                    <template v-if="branch.today.staffing">
                        <StatusBadge
                            v-if="branch.today.staffing.state === 'enough'"
                            status="enough"
                            label="Enough staff"
                            tone="success"
                        />
                        <StatusBadge
                            v-else-if="branch.today.staffing.state === 'short'"
                            status="short"
                            label="Short of staff"
                            tone="warning"
                        />
                        <StatusBadge
                            v-else-if="
                                branch.today.staffing.state === 'no_roster'
                            "
                            status="no_roster"
                            label="No shifts today"
                            tone="neutral"
                        />
                        <StatusBadge
                            v-else-if="
                                branch.today.staffing.state === 'no_shift_now'
                            "
                            status="no_shift_now"
                            label="No shift right now"
                            tone="neutral"
                        />
                        <StatusBadge
                            v-else-if="
                                branch.today.staffing.state === 'not_set'
                            "
                            status="not_set"
                            label="Minimum staffing not set"
                            tone="neutral"
                        />
                    </template>
                    <StatusBadge
                        v-if="branch.today.rostered?.noDoctor"
                        status="no_doctor"
                        label="No Resident Doctor rostered now"
                        tone="danger"
                    />
                </div>
                <ul
                    v-if="branch.today.staffing?.messages.length"
                    class="list-disc pl-5 text-sm text-muted-foreground"
                >
                    <li
                        v-for="message in branch.today.staffing.messages"
                        :key="message"
                    >
                        {{ message }}
                    </li>
                </ul>

                <div class="grid gap-3 md:grid-cols-3">
                    <div
                        v-if="branch.today.rostered"
                        class="grid content-start gap-1"
                        data-test="rostered"
                    >
                        <h4 class="text-sm font-medium">
                            Rostered now:
                            {{ branch.today.rostered.count }}
                        </h4>
                        <p
                            v-if="branch.today.rostered.count === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Nobody is on a shift right now.
                        </p>
                        <ul v-else class="grid gap-0.5 text-sm">
                            <li
                                v-for="(p, i) in branch.today.rostered.people"
                                :key="`${p.name}-${i}`"
                            >
                                {{ p.name }}
                                <span class="text-muted-foreground">
                                    ({{ p.role }}, until {{ p.until }})
                                </span>
                            </li>
                        </ul>
                        <p
                            v-if="
                                branch.today.rostered.count >
                                branch.today.rostered.people.length
                            "
                            class="text-xs text-muted-foreground"
                        >
                            and
                            {{
                                branch.today.rostered.count -
                                branch.today.rostered.people.length
                            }}
                            more
                        </p>
                    </div>
                    <div
                        v-if="branch.today.clockedIn"
                        class="grid content-start gap-1"
                        data-test="clocked-in"
                    >
                        <h4 class="text-sm font-medium">
                            Clocked in: {{ branch.today.clockedIn.count }}
                        </h4>
                        <p
                            v-if="branch.today.clockedIn.count === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Nobody is clocked in.
                        </p>
                        <ul v-else class="grid gap-0.5 text-sm">
                            <li
                                v-for="(p, i) in branch.today.clockedIn.people"
                                :key="`${p.name}-${i}`"
                            >
                                {{ p.name }}
                                <span class="text-muted-foreground">
                                    (since {{ p.since }})
                                </span>
                            </li>
                        </ul>
                        <p
                            v-if="
                                branch.today.clockedIn.count >
                                branch.today.clockedIn.people.length
                            "
                            class="text-xs text-muted-foreground"
                        >
                            and
                            {{
                                branch.today.clockedIn.count -
                                branch.today.clockedIn.people.length
                            }}
                            more
                        </p>
                    </div>
                    <div
                        v-if="branch.today.onLeave"
                        class="grid content-start gap-1"
                        data-test="on-leave"
                    >
                        <h4 class="text-sm font-medium">
                            On leave: {{ branch.today.onLeave.count }}
                        </h4>
                        <p
                            v-if="branch.today.onLeave.count === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Nobody is on leave.
                        </p>
                        <ul v-else class="grid gap-0.5 text-sm">
                            <li
                                v-for="(p, i) in branch.today.onLeave.people"
                                :key="`${p.name}-${i}`"
                            >
                                {{ p.name }}
                                <span class="text-muted-foreground">
                                    ({{ p.half ? 'half day' : 'on leave' }})
                                </span>
                            </li>
                        </ul>
                        <p
                            v-if="
                                branch.today.onLeave.count >
                                branch.today.onLeave.people.length
                            "
                            class="text-xs text-muted-foreground"
                        >
                            and
                            {{
                                branch.today.onLeave.count -
                                branch.today.onLeave.people.length
                            }}
                            more
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid gap-2" data-test="month">
                <h3 class="text-sm font-semibold">{{ monthLabel }}</h3>
                <dl
                    class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4"
                >
                    <template v-if="branch.month.attendance">
                        <div>
                            <dt class="text-muted-foreground">Days clocked</dt>
                            <dd class="font-medium">
                                {{ branch.month.attendance.daysClocked }}
                            </dd>
                        </div>
                        <template v-if="dashboard.attendanceSettingsSaved">
                            <div>
                                <dt class="text-muted-foreground">Late</dt>
                                <dd class="font-medium">
                                    {{ branch.month.attendance.late }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Left early
                                </dt>
                                <dd class="font-medium">
                                    {{ branch.month.attendance.leftEarly }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    No clock-in
                                </dt>
                                <dd class="font-medium">
                                    {{ branch.month.attendance.noClockIn }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    No clock-out
                                </dt>
                                <dd class="font-medium">
                                    {{ branch.month.attendance.noClockOut }}
                                </dd>
                            </div>
                        </template>
                    </template>
                    <div v-if="branch.month.overtime">
                        <dt class="text-muted-foreground">Approved overtime</dt>
                        <dd class="font-medium">
                            {{ hours(branch.month.overtime.approvedMinutes) }}
                        </dd>
                    </div>
                    <div v-if="dashboard.can.leave">
                        <dt class="text-muted-foreground">Leave taken</dt>
                        <dd class="font-medium">
                            <template v-if="branch.month.leave">
                                {{ days(branch.month.leave.days) }}
                                ({{ branch.month.leave.people }}
                                {{
                                    branch.month.leave.people === 1
                                        ? 'person'
                                        : 'people'
                                }})
                            </template>
                            <span v-else class="text-muted-foreground">
                                Working days not set up
                            </span>
                        </dd>
                    </div>
                    <div v-if="dashboard.can.roster">
                        <dt class="text-muted-foreground">Short days</dt>
                        <dd class="font-medium">
                            <template
                                v-if="branch.month.shortfallDays !== null"
                            >
                                {{ days(branch.month.shortfallDays) }}
                            </template>
                            <span v-else class="text-muted-foreground">
                                Minimum staffing not set
                            </span>
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="
                        dashboard.can.attendance &&
                        !dashboard.attendanceSettingsSaved
                    "
                    class="text-xs text-muted-foreground"
                >
                    Late, left early and missing clock times stay hidden until
                    HR saves attendance settings.
                </p>
            </div>
        </section>
    </div>
</template>
