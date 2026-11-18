<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Stat from '@/components/v2/V2Stat.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useDashboardPage } from '@/composables/hr/useDashboardPage';
import type { DashboardPageProps } from '@/composables/hr/useDashboardPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 HR dashboard (shown only in V2; Classic is `pages/HR/Dashboard.vue`, untouched). Read only: it changes
 * nothing. Same props, route and permissions; the one request (the month choice) comes from `useDashboardPage`, which
 * Classic uses too. No salary, no leave type or reason, no score of a person. Names appear only in the three lists for
 * today, exactly as Classic shows them. The staff cost is one clinic-wide estimate from approved pay runs, never split
 * by person or branch.
 */
const titleCase = (name: string) =>
    name
        .toLowerCase()
        .replace(/(^|[\s-])([a-z])/g, (_, a, b) => a + b.toUpperCase());
const props = defineProps<DashboardPageProps>();
usePageCrumbs(() => [
    { title: 'KPOne HR', href: '/hr' },
    { title: 'Dashboard' },
]);

const { monthLabel, chooseMonth, hours, days } = useDashboardPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';
const minimumsHref = (id: number) => `/hr/roster?tab=minimums&branch=${id}`;
</script>

<template>
    <Head title="HR dashboard" />
    <PageFrame>
        <V2WorkLayout
            title="HR dashboard"
            description="Who is on duty today at each branch, and the month at a glance. Read only; it changes nothing."
        >
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p
                            class="text-sm text-muted-foreground"
                            data-test="as-of"
                        >
                            As of {{ dashboard.now }} (Kuala Lumpur time).
                        </p>
                        <div class="flex items-center gap-2">
                            <label
                                id="v2-dashboard-month-label"
                                class="text-sm font-medium"
                                >Month</label
                            >
                            <OperationalSelect
                                :model-value="dashboard.month"
                                :options="dashboard.months"
                                label="Month"
                                labelledby="v2-dashboard-month-label"
                                trigger-class="max-lg:h-11 min-w-48"
                                @update:model-value="chooseMonth"
                            />
                        </div>
                    </div>

                    <p
                        v-if="dashboard.workPermits"
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-[14px] border border-warning/40 bg-warning/10 px-4 py-3 text-sm"
                        data-test="dashboard-work-permits"
                    >
                        <span class="font-medium"
                            >{{ dashboard.workPermits }}
                            {{
                                dashboard.workPermits === 1
                                    ? 'work permit expires soon or has expired.'
                                    : 'work permits expire soon or have expired.'
                            }}</span
                        >
                        <Link
                            href="/hr/credentials?tab=register"
                            class="min-h-11 content-center underline underline-offset-4 lg:min-h-0"
                            >Open the credentials register</Link
                        >
                    </p>
                    <section
                        v-if="dashboard.staffCost"
                        :class="cardClass"
                        data-test="staff-cost"
                        aria-labelledby="v2-staff-cost-title"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <h2
                                id="v2-staff-cost-title"
                                class="text-base font-semibold"
                            >
                                Staff cost, {{ monthLabel }}
                            </h2>
                            <V2Chip
                                tone="warning"
                                label="Estimate"
                                data-test="staff-cost-estimate"
                            />
                        </div>
                        <p class="text-sm text-muted-foreground">
                            One figure for the whole clinic, from approved pay
                            runs only. Not split by person or branch. Includes
                            corrections approved later. An estimate until the
                            pay rules are checked against current law.
                        </p>
                        <p
                            v-if="dashboard.staffCost.runs === 0"
                            class="text-sm"
                            data-test="staff-cost-none"
                        >
                            No approved pay run for this month yet.
                        </p>
                        <div
                            v-else
                            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <V2Stat
                                label="Total staff cost"
                                :value="dashboard.staffCost.totalText"
                                data-test="staff-cost-total"
                            />
                            <V2Stat
                                label="Pay after unpaid leave"
                                :value="dashboard.staffCost.earningsText"
                                detail="Gross pay less unpaid leave; other deductions are part of this pay"
                            />
                            <V2Stat
                                label="Employer contributions"
                                :value="dashboard.staffCost.employerText"
                                detail="Added on top of pay by the clinic"
                            />
                            <V2Stat
                                label="People paid through KPOne"
                                :value="String(dashboard.staffCost.people)"
                                detail="Locums and the Director's and HR Manager's own pay are outside KPOne"
                            />
                        </div>
                        <p
                            v-if="dashboard.staffCost.waiting > 0"
                            class="text-sm text-muted-foreground"
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
                            role="status"
                            class="text-sm"
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
                        class="rounded-[14px] border bg-card p-4 text-sm"
                        data-test="rules-note"
                    >
                        <summary
                            class="min-h-11 cursor-pointer content-center font-semibold lg:min-h-0"
                        >
                            How this page counts
                        </summary>
                        <p class="mt-2 max-w-prose text-muted-foreground">
                            Enough or Short compares the people on today's
                            shifts with the minimum staffing for each session
                            today, as the roster Planner does. Minimums apply to
                            every day, so a day with a roster at a branch that
                            is closed can read Short. Short days are counted
                            from approved rosters only, by today's minimums.
                            Names appear only in the three lists for today.
                            Leave shows only on leave or half day, never the
                            type or reason.
                        </p>
                    </details>

                    <div
                        v-if="dashboard.branches.length === 0"
                        class="rounded-[14px] border bg-card p-6 text-center"
                    >
                        <p class="font-medium">No active branch.</p>
                        <p class="text-sm text-muted-foreground">
                            Add a branch first.
                        </p>
                    </div>

                    <section
                        v-for="branch in dashboard.branches"
                        :key="branch.id"
                        :aria-labelledby="`v2-branch-${branch.id}`"
                        :class="cardClass"
                        data-test="branch-card"
                    >
                        <h2
                            :id="`v2-branch-${branch.id}`"
                            class="text-lg font-semibold"
                        >
                            {{ titleCase(branch.name) }}
                        </h2>

                        <div
                            v-if="branch.today"
                            class="flex flex-col gap-3"
                            data-test="today"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold">
                                    Today, {{ branch.today.date }}
                                </h3>
                                <template v-if="branch.today.staffing">
                                    <V2Chip
                                        v-if="
                                            branch.today.staffing.state ===
                                            'enough'
                                        "
                                        tone="success"
                                        label="Enough staff today"
                                    />
                                    <V2Chip
                                        v-else-if="
                                            branch.today.staffing.state ===
                                            'short'
                                        "
                                        tone="warning"
                                        label="Short of staff today"
                                    />
                                    <V2Chip
                                        v-else-if="
                                            branch.today.staffing.state ===
                                            'no_roster'
                                        "
                                        tone="neutral"
                                        label="No shifts today"
                                    />
                                    <template
                                        v-else-if="
                                            branch.today.staffing.state ===
                                            'not_set'
                                        "
                                    >
                                        <V2Chip
                                            tone="neutral"
                                            label="Minimum staffing not set"
                                        />
                                        <Link
                                            v-if="dashboard.can.roster"
                                            :href="minimumsHref(branch.id)"
                                            :class="button"
                                            data-test="set-minimums"
                                        >
                                            Set minimum staffing
                                        </Link>
                                    </template>
                                </template>
                                <V2Chip
                                    v-if="branch.today.rostered?.noDoctor"
                                    tone="warning"
                                    label="No Resident Doctor rostered now"
                                />
                            </div>
                            <ul
                                v-if="branch.today.staffing?.messages.length"
                                class="list-disc pl-5 text-sm text-muted-foreground"
                            >
                                <li
                                    v-for="message in branch.today.staffing
                                        .messages"
                                    :key="message"
                                >
                                    {{ message }}
                                </li>
                            </ul>

                            <div class="grid gap-3 md:grid-cols-3">
                                <div
                                    v-if="branch.today.rostered"
                                    class="flex flex-col gap-1"
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
                                            v-for="(p, i) in branch.today
                                                .rostered.people"
                                            :key="`${p.name}-${i}`"
                                        >
                                            {{ p.name }}
                                            <span class="text-muted-foreground">
                                                ({{ p.role }}, until
                                                {{ p.until }})
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
                                    <p
                                        v-if="
                                            branch.today.rostered.onCall.length
                                        "
                                        class="text-sm"
                                        data-test="on-call-now"
                                    >
                                        <span class="font-medium"
                                            >On call:</span
                                        >
                                        <template
                                            v-for="(p, i) in branch.today
                                                .rostered.onCall"
                                            :key="`oncall-${p.name}-${i}`"
                                            >{{ i > 0 ? ', ' : ' '
                                            }}{{ p.name }}
                                            <span class="text-muted-foreground"
                                                >({{ p.role }}, until
                                                {{ p.until }})</span
                                            ></template
                                        >
                                        <template
                                            v-if="
                                                branch.today.rostered
                                                    .onCallCount >
                                                branch.today.rostered.onCall
                                                    .length
                                            "
                                            >, and
                                            {{
                                                branch.today.rostered
                                                    .onCallCount -
                                                branch.today.rostered.onCall
                                                    .length
                                            }}
                                            more</template
                                        >
                                    </p>
                                </div>
                                <div
                                    v-if="branch.today.clockedIn"
                                    class="flex flex-col gap-1"
                                    data-test="clocked-in"
                                >
                                    <h4 class="text-sm font-medium">
                                        Clocked in:
                                        {{ branch.today.clockedIn.count }}
                                    </h4>
                                    <p
                                        v-if="
                                            branch.today.clockedIn.count === 0
                                        "
                                        class="text-sm text-muted-foreground"
                                    >
                                        Nobody is clocked in.
                                    </p>
                                    <ul v-else class="grid gap-0.5 text-sm">
                                        <li
                                            v-for="(p, i) in branch.today
                                                .clockedIn.people"
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
                                    class="flex flex-col gap-1"
                                    data-test="on-leave"
                                >
                                    <h4 class="text-sm font-medium">
                                        On leave:
                                        {{ branch.today.onLeave.count }}
                                    </h4>
                                    <p
                                        v-if="branch.today.onLeave.count === 0"
                                        class="text-sm text-muted-foreground"
                                    >
                                        Nobody is on leave.
                                    </p>
                                    <ul v-else class="grid gap-0.5 text-sm">
                                        <li
                                            v-for="(p, i) in branch.today
                                                .onLeave.people"
                                            :key="`${p.name}-${i}`"
                                        >
                                            {{ p.name }}
                                            <span class="text-muted-foreground">
                                                ({{
                                                    p.half
                                                        ? 'half day'
                                                        : 'on leave'
                                                }})
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

                        <div
                            class="flex flex-col gap-2 border-t pt-3"
                            data-test="month"
                        >
                            <h3 class="text-sm font-semibold">
                                {{ monthLabel }}
                            </h3>
                            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                <template v-if="branch.month.attendance">
                                    <V2Stat
                                        label="Days clocked"
                                        :value="
                                            String(
                                                branch.month.attendance
                                                    .daysClocked,
                                            )
                                        "
                                    />
                                    <template
                                        v-if="dashboard.attendanceSettingsSaved"
                                    >
                                        <V2Stat
                                            label="Late"
                                            :value="
                                                String(
                                                    branch.month.attendance
                                                        .late,
                                                )
                                            "
                                        />
                                        <V2Stat
                                            label="Left early"
                                            :value="
                                                String(
                                                    branch.month.attendance
                                                        .leftEarly,
                                                )
                                            "
                                        />
                                        <V2Stat
                                            label="No clock-in"
                                            :value="
                                                String(
                                                    branch.month.attendance
                                                        .noClockIn,
                                                )
                                            "
                                        />
                                        <V2Stat
                                            label="No clock-out"
                                            :value="
                                                String(
                                                    branch.month.attendance
                                                        .noClockOut,
                                                )
                                            "
                                        />
                                    </template>
                                </template>
                                <V2Stat
                                    v-if="branch.month.overtime"
                                    label="Approved overtime"
                                    :value="
                                        hours(
                                            branch.month.overtime
                                                .approvedMinutes,
                                        )
                                    "
                                />
                                <V2Stat
                                    v-if="dashboard.can.leave"
                                    label="Leave taken"
                                    :value="
                                        branch.month.leave
                                            ? days(branch.month.leave.days)
                                            : 'Not available'
                                    "
                                    :detail="
                                        branch.month.leave
                                            ? `${branch.month.leave.people} ${branch.month.leave.people === 1 ? 'person' : 'people'}`
                                            : 'Working days not set up'
                                    "
                                />
                                <V2Stat
                                    v-if="dashboard.can.roster"
                                    label="Short days"
                                    :value="
                                        branch.month.shortfallDays !== null
                                            ? days(branch.month.shortfallDays)
                                            : 'Not available'
                                    "
                                    :detail="
                                        branch.month.shortfallDays === null
                                            ? 'Minimum staffing not set'
                                            : undefined
                                    "
                                />
                            </div>
                            <div
                                v-if="
                                    dashboard.can.roster &&
                                    branch.month.shortfallDays === null &&
                                    branch.today?.staffing?.state !== 'not_set'
                                "
                            >
                                <Link
                                    :href="minimumsHref(branch.id)"
                                    :class="button"
                                >
                                    Set minimum staffing
                                </Link>
                            </div>
                            <p
                                v-if="
                                    dashboard.can.attendance &&
                                    !dashboard.attendanceSettingsSaved
                                "
                                class="text-sm text-muted-foreground"
                            >
                                Late, left early and missing clock times stay
                                hidden until HR saves attendance settings.
                            </p>
                        </div>
                    </section>
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
