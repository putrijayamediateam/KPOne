import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * HR-9 /hr/dashboard page state: the month choice and the display helpers. The one request (`GET /hr/dashboard`) lives
 * here, shared by the Classic page (`pages/HR/Dashboard.vue`) and Version 2 (`pages/HR/V2/Dashboard.vue`). Read only;
 * moved out of the Classic page unchanged and the Classic template did not change.
 */
export type Person = {
    name: string;
    role?: string;
    until?: string;
    since?: string;
    half?: boolean;
};
export type Today = {
    date: string;
    rostered: {
        count: number;
        doctors: number;
        people: Person[];
        noDoctor: boolean;
        /** HR-20: on-call people now, on their own line; never counted as the doctor on duty. */
        onCall: Person[];
        onCallCount: number;
    } | null;
    clockedIn: { count: number; people: Person[] } | null;
    onLeave: { count: number; people: Person[] } | null;
    staffing: {
        state: 'enough' | 'short' | 'not_set' | 'no_roster' | 'closed';
        messages: string[];
    } | null;
};
export type MonthFigures = {
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
export type StaffCost = {
    runs: number;
    waiting: number;
    people: number;
    unreadable: number;
    earningsText: string;
    employerText: string;
    totalText: string;
};
export type BranchCard = {
    id: number;
    name: string;
    today: Today | null;
    month: MonthFigures;
};

export type DashboardPageProps = {
    dashboard: {
        can: { roster: boolean; attendance: boolean; leave: boolean };
        now: string;
        month: string;
        months: { value: string; label: string }[];
        attendanceSettingsSaved: boolean;
        staffCost: StaffCost | null;
        /** HR-21: work permits that expire soon or have expired (credential managers only; null otherwise). */
        workPermits: number | null;
        branches: BranchCard[];
    };
};

export function useDashboardPage(props: DashboardPageProps) {
    const monthLabel = computed(
        () =>
            props.dashboard.months.find(
                (m) => m.value === props.dashboard.month,
            )?.label ?? props.dashboard.month,
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

    return { monthLabel, chooseMonth, hours, days };
}
