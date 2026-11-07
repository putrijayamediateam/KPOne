// HR-7a payroll setup (HR-PLAN 14.6). Shared shapes and words for the page and its components.
export type Option = { value: string; label: string };

export type ProfileStatus =
    'waiting' | 'confirmed' | 'rejected' | 'withdrawn' | 'replaced';

export type TableStatus =
    'entered' | 'checked' | 'rejected' | 'withdrawn' | 'replaced';

export type Profile = {
    id: string;
    status: ProfileStatus;
    payGroup: string;
    payType: 'monthly' | 'hourly';
    rateSen: number | null;
    schemes: string[];
    effectiveFrom: string;
    items: { name: string; amountSen: number | null }[];
    enteredBy: string;
    enteredAt: string;
    decidedBy: string | null;
    decidedAt: string | null;
    reason: string | null;
    lockVersion: number;
    canDecide: boolean;
    decideRefusal: string | null;
    canWithdraw: boolean;
};

export type Person = {
    id: string;
    name: string;
    active: boolean;
    /** A Director or HR Manager: their own pay stays outside KPOne in v1. */
    outside: boolean;
    enterRefusal: string | null;
    current: Profile | null;
    upcoming: Profile | null;
    waiting: Profile | null;
    earlier: Profile[];
};

export type StatutoryTable = {
    id: string;
    scheme: string;
    schemeLabel: string;
    category: string;
    effectiveFrom: string;
    rounding: string;
    roundingLabel: string;
    sourceNote: string;
    status: TableStatus;
    rows: {
        from: string;
        to: string | null;
        employee: string;
        employer: string;
    }[];
    enteredBy: string;
    enteredAt: string;
    decidedBy: string | null;
    decidedAt: string | null;
    reason: string | null;
    lockVersion: number;
    canDecide: boolean;
    decideRefusal: string | null;
    canWithdraw: boolean;
};

export type MtdSchedule = {
    id: string;
    year: number;
    status: TableStatus;
    rounding: string;
    minimum: string;
    reliefs: { label: string; amount: string }[];
    sourceNote: string;
    rows: {
        from: string;
        to: string | null;
        rate: string;
        baseCat13: string;
        baseCat2: string;
    }[];
    enteredBy: string;
    enteredAt: string;
    decidedBy: string | null;
    decidedAt: string | null;
    reason: string | null;
    lockVersion: number;
    canDecide: boolean;
    decideRefusal: string | null;
    canWithdraw: boolean;
};

export const profileWords: Record<ProfileStatus, string> = {
    waiting: 'Waiting for confirmation',
    confirmed: 'Confirmed',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
    replaced: 'Replaced',
};

export const tableWords: Record<TableStatus, string> = {
    entered: 'Waiting to be checked',
    checked: 'Checked',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
    replaced: 'Replaced',
};

/** Confirmed and Checked are success; everything else is neutral. None is red. */
export const tone = (status: string): 'neutral' | 'success' =>
    status === 'confirmed' || status === 'checked' ? 'success' : 'neutral';

/** "30 Oct 2026" for a Y-m-d date (HR-PLAN 14.10 item 10). */
export const day = (date: string): string =>
    new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/** "30 Oct 2026" for an ISO timestamp, in the viewer's time zone. */
export const on = (iso: string): string =>
    new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/** Sen to the text an RM field shows ("2500.00"). */
export const rmText = (sen: number | null): string =>
    sen === null
        ? ''
        : `${Math.trunc(sen / 100)}.${String(sen % 100).padStart(2, '0')}`;

export type RunStatus = 'draft' | 'sent' | 'approved' | 'locked';

export type RunRow = {
    id: string;
    kind: 'regular' | 'adjustment';
    month: string;
    monthValue: string;
    group: string;
    status: RunStatus;
    people: number;
    netSen: number;
    errors: number;
    lockedAt: string | null;
};

/** HR-7c: a locked run on the Exports tab (the Director only in v1). */
export type ExportRun = {
    id: string;
    kind: 'regular' | 'adjustment';
    month: string;
    group: string;
    people: number;
    netSen: number;
    lockedAt: string;
    missingBank: string[];
    exports: number;
    statutory: {
        scheme: string;
        people: number;
        employeeSen: number;
        employerSen: number;
    }[];
};

/** HR-7c: one of your own payslips on My HR details, listed with no amount. */
export type PayslipRow = {
    id: string;
    period: string;
    kind: 'regular' | 'adjustment';
    group: string;
    issuedOn: string;
};

export const runWords: Record<RunStatus, string> = {
    draft: 'Draft',
    sent: 'Waiting for approval',
    approved: 'Approved',
    locked: 'Locked',
};
