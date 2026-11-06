// HR-3b overtime, weekly hours and summaries: page prop shapes shared by the attendance page and its components.
export type Option = { value: string; label: string };

export type OvertimeChoice = {
    sessionId: string;
    date: string;
    label: string;
    max: number;
    worked: string;
    rostered: string | null;
    dayType: 'normal' | 'rest_day' | 'public_holiday';
    dayLabel: string;
};

export type WeekHours = {
    minutes: number;
    label: string;
    level: 'none' | 'nearing' | 'over';
    chip: string | null;
};

export type OvertimeRequestRow = {
    publicId: string;
    person: string;
    dayLabel: string;
    branch: string;
    dayType: string;
    dayTypeLabel: string;
    minutesRequested: number;
    requestedLabel: string;
    approvedLabel: string | null;
    fewer: boolean;
    reason: string;
    note: string | null;
    status: 'pending' | 'approved' | 'rejected' | 'withdrawn' | 'reversed';
    statusLabel: string;
    decisionReason: string | null;
    onBehalf: boolean;
    requestedBy: string;
    hoursChanged: boolean;
    week: WeekHours | null;
    canDecide: boolean;
    canReverse: boolean;
    canWithdraw: boolean;
    lockVersion: number;
};

export type SummaryFigures = {
    rostered: string;
    worked: string;
    late: string;
    leftEarly: string;
    noClockIn: string;
    noClockOut: string;
    overtime: string;
    otherBranch: boolean;
};

export type OvertimeOptions = {
    reasons: Option[];
    lowerReasons: Option[];
    rejectReasons: Option[];
    reverseReasons: Option[];
};

export type MyOvertime = {
    ready: boolean;
    eligible: boolean;
    weekHours: WeekHours;
    choices: OvertimeChoice[];
    requests: OvertimeRequestRow[];
    month: { label: string; figures: SummaryFigures };
};

export type OvertimeTabData = {
    ready: boolean;
    summary: string;
    pending: OvertimeRequestRow[];
    decided: OvertimeRequestRow[];
    month: string;
    monthLabel: string;
    prevMonth: string;
    nextMonth: string;
};

export type SummaryTabData = {
    branches: { id: number; name: string }[];
    branch: { id: number; name: string } | null;
    period?: 'week' | 'month';
    date?: string;
    periodLabel?: string;
    prevDate?: string;
    nextDate?: string;
    settingsReady?: boolean;
    rows: ({ userId: number; person: string } & SummaryFigures)[];
};

export type OvertimeSettingsCard = {
    current: {
        normalMultiplier: string;
        restDayMultiplier: string;
        publicHolidayMultiplier: string;
        nearingHours: number;
        limitHours: number;
        roles: string[];
        effectiveFrom: string;
    } | null;
    suggested: {
        normalMultiplier: string;
        restDayMultiplier: string;
        publicHolidayMultiplier: string;
        nearingHours: number;
        limitHours: number;
    };
    roleOptions: Option[];
    history: {
        id: number;
        effectiveFrom: string;
        summary: string;
        by: string;
    }[];
};

export const requestTone = (row: OvertimeRequestRow): 'neutral' | 'success' =>
    row.status === 'approved' ? 'success' : 'neutral';

export const weekTone = (level: WeekHours['level']): 'warning' | 'neutral' =>
    level === 'none' ? 'neutral' : 'warning';
