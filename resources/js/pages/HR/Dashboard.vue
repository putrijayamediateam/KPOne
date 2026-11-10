<script setup lang="ts">
// HR-9 /hr/dashboard: read-only aggregates for the Director and HR Manager (docs/teams/hr/HR-9-DESIGN.md).
// Today per branch, then the month. No salary, no leave type or reason, no score of a person.
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { ActionLink } from '@/components/ui/action-link';
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
        state: 'enough' | 'short' | 'not_set' | 'no_roster' | 'closed';
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
type StaffCost = {
    runs: number;
    waiting: number;
    people: number;
    unreadable: number;
    earningsText: string;
    employerText: string;
    totalText: string;
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
        staffCost: StaffCost | null;
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

        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-sm text-muted-foreground" data-test="as-of">
                As of {{ dashboard.now }} (Kuala Lumpur time).
            </p>
            <div class="flex items-center gap-2">
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

        <section
            v-if="dashboard.staffCost"
            class="rounded-lg border bg-card p-4"
            data-test="staff-cost"
            aria-labelledby="staff-cost-title"
        >
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="staff-cost-title" class="text-base font-semibold">
                    Staff cost, {{ monthLabel }}
                </h2>
                <StatusBadge
                    status="estimate"
                    label="Estimate"
                    tone="warning"
                    data-test="staff-cost-estimate"
                />
            </div>
            <p class="mt-1 text-sm text-muted-foreground">
                One figure for the whole clinic, from approved pay runs only.
                Not split by person or branch. Includes corrections approved
                later. An estimate until the clinic's accountant has checked the
                pay rules.
            </p>
            <p
                v-if="dashboard.staffCost.runs === 0"
                class="mt-3 text-sm"
                data-test="staff-cost-none"
            >
                No approved pay run for this month yet.
            </p>
            <dl
                v-else
                class="mt-3 flex flex-col gap-2 text-sm sm:grid sm:grid-cols-4 sm:gap-x-6"
            >
                <div class="flex items-baseline justify-between gap-4 sm:block">
                    <dt class="text-muted-foreground">Total staff cost</dt>
                    <dd
                        class="text-lg font-semibold tabular-nums"
                        data-test="staff-cost-total"
                    >
                        {{ dashboard.staffCost.totalText }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 sm:block">
                    <dt class="text-muted-foreground">
                        Pay before deductions
                        <span class="block text-xs"
                            >Unpaid leave already taken off</span
                        >
                    </dt>
                    <dd class="tabular-nums">
                        {{ dashboard.staffCost.earningsText }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 sm:block">
                    <dt class="text-muted-foreground">
                        Employer contributions
                        <span class="block text-xs"
                            >Added on top of pay by the clinic</span
                        >
                    </dt>
                    <dd class="tabular-nums">
                        {{ dashboard.staffCost.employerText }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 sm:block">
                    <dt class="text-muted-foreground">
                        People paid through KPOne
                        <span class="block text-xs"
                            >Locums and the Director's and HR Manager's own pay
                            are outside KPOne</span
                        >
                    </dt>
                    <dd class="tabular-nums">
                        {{ dashboard.staffCost.people }}
                    </dd>
                </div>
            </dl>
            <p
                v-if="dashboard.staffCost.waiting > 0"
                class="mt-2 text-sm text-muted-foreground"
                data-test="staff-cost-waiting"
            >
                {{ dashboard.staffCost.waiting }}
                {{
                    dashboard.staffCost.waiting === 1
                        ? 'pay run is'
                        : 'pay runs are'
                }}
                not approved yet and not counted.
            </p>
            <p
                v-if="dashboard.staffCost.unreadable > 0"
                class="mt-2 text-sm font-medium text-warning-strong"
                role="status"
                data-test="staff-cost-unreadable"
            >
                {{ dashboard.staffCost.unreadable }}
                {{
                    dashboard.staffCost.unreadable === 1
                        ? 'amount in an approved pay run'
                        : 'amounts in approved pay runs'
                }}
                could not be read, so this figure is incomplete.
            </p>
        </section>

        <details
            class="rounded-lg border bg-card px-4 py-3 text-sm"
            data-test="rules-note"
        >
            <summary
                class="cursor-pointer font-medium marker:text-muted-foreground"
            >
                How this page counts
            </summary>
            <p class="mt-2 max-w-prose text-muted-foreground">
                Enough or Short compares the people on today's shifts with the
                minimum staffing for each session today, as the roster Planner
                does. Minimums apply to every day, so a day with a roster at a
                branch that is closed can read Short. Short days are counted
                from approved rosters only, by today's minimums. Names appear
                only in the three lists for today. Leave shows only on leave or
                half day, never the type or reason.
            </p>
        </details>

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
                            label="Enough staff today"
                            tone="success"
                        />
                        <StatusBadge
                            v-else-if="branch.today.staffing.state === 'short'"
                            status="short"
                            label="Short of staff today"
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
                        <template
                            v-else-if="
                                branch.today.staffing.state === 'not_set'
                            "
                        >
                            <StatusBadge
                                status="not_set"
                                label="Minimum staffing not set"
                                tone="neutral"
                            />
                            <ActionLink
                                v-if="dashboard.can.roster"
                                :href="`/hr/roster?tab=minimums&branch=${branch.id}`"
                                variant="outline"
                                class="max-lg:min-h-11"
                                data-test="set-minimums"
                            >
                                Set minimum staffing
                            </ActionLink>
                        </template>
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
                    class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm lg:grid-cols-4"
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
                            <template v-else>
                                <span class="text-muted-foreground">
                                    Minimum staffing not set
                                </span>
                                <ActionLink
                                    :href="`/hr/roster?tab=minimums&branch=${branch.id}`"
                                    variant="outline"
                                    class="mt-1 max-lg:min-h-11"
                                >
                                    Set minimum staffing
                                </ActionLink>
                            </template>
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
