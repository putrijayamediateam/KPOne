export type StatusTone =
    'neutral' | 'accent' | 'success' | 'warning' | 'danger';

const statusLabels: Record<string, string> = {
    approved: 'Approved',
    // CAT-2 price list check
    price_charge_inactive: 'Charge inactive',
    price_in_use: 'In use',
    price_no_charge: 'No charge',
    price_no_panel_default: 'No default Panel price',
    price_no_self_pay: 'No Self-pay price',
    price_ok: 'OK',
    price_scheduled: 'Scheduled',
    price_unit_differs: 'Unit differs',
    price_zero: 'Free (RM 0.00)',
    cancelled: 'Cancelled',
    completed: 'Completed',
    dispensed: 'Dispensed',
    dispensing: 'Dispensing',
    finalized: 'Finalized',
    in_progress: 'In progress',
    not_dispensed: 'Not dispensed',
    partial: 'Partial',
    pending: 'Pending',
    posted: 'Posted',
    proposed: 'Proposed',
    registered: 'Registered',
    rejected: 'Rejected',
    removed: 'Removed',
    roster_approved: 'Approved',
    roster_draft: 'Draft',
    roster_short: 'Short',
    roster_submitted: 'Waiting for approval',
    roster_superseded: 'Replaced',
    // HR-2a leave (Hui Min): cancelled is neutral, unlike a cancelled visit.
    leave_pending: 'Waiting for approval',
    leave_approved: 'Approved',
    leave_rejected: 'Rejected',
    leave_cancelled: 'Cancelled',
    leave_check_pending: 'Waiting for a check',
    leave_check_confirmed: 'Confirmed',
    leave_check_rejected: 'Rejected',
    returned: 'Returned',
    serving: 'Serving',
    void: 'Void',
    waiting: 'Waiting',
    // DSP-7 medicine returns (Daniel 2026-11-05): waiting is warning; the three outcomes are neutral.
    waiting_check: 'Waiting for check',
    disposed: 'Disposed',
    quarantined: 'Quarantined',
    refused: 'Refused',
};

const toneByStatus: Record<string, StatusTone> = {
    price_charge_inactive: 'danger',
    price_in_use: 'success',
    price_no_charge: 'danger',
    price_no_panel_default: 'warning',
    price_no_self_pay: 'danger',
    price_ok: 'success',
    price_scheduled: 'neutral',
    price_unit_differs: 'danger',
    price_zero: 'warning',
    approved: 'success',
    completed: 'success',
    dispensed: 'success',
    finalized: 'success',
    posted: 'success',
    roster_approved: 'success',
    leave_approved: 'success',
    leave_check_confirmed: 'success',
    leave_pending: 'warning',
    leave_check_pending: 'warning',
    leave_check_rejected: 'danger',
    dispensing: 'accent',
    serving: 'accent',
    returned: 'accent',
    partial: 'warning',
    pending: 'warning',
    proposed: 'warning',
    waiting: 'warning',
    roster_submitted: 'warning',
    roster_short: 'warning',
    waiting_check: 'warning',
    cancelled: 'danger',
    not_dispensed: 'danger',
    rejected: 'danger',
    void: 'danger',
};

// Words that keep their capitals inside a sentence-case label: KPOne's own term "Panel" and acronyms. The status
// value arrives in lower case, so they are put back by name.
const keptWords: Record<string, string> = {
    mc: 'MC',
    panel: 'Panel',
    po: 'PO',
    tpa: 'TPA',
};

export const formatStatusLabel = (status: string): string => {
    const normalized = status.trim().toLocaleLowerCase().replaceAll('-', '_');

    if (statusLabels[normalized]) {
        return statusLabels[normalized];
    }

    const words = normalized
        .split('_')
        .filter((word) => word !== '')
        .map((word) => keptWords[word] ?? word);

    return words
        .join(' ')
        .replace(/^\p{L}/u, (character) => character.toLocaleUpperCase());
};

export const statusTone = (status: string): StatusTone =>
    toneByStatus[status.trim().toLocaleLowerCase().replaceAll('-', '_')] ??
    'neutral';

/** Token classes for a status tone (the same values StatusBadge uses). */
export const statusToneClasses: Record<StatusTone, string> = {
    neutral: 'border-border bg-muted text-foreground',
    accent: 'border-brand/25 bg-brand/10 text-brand-strong',
    success: 'border-success/25 bg-success/10 text-success',
    warning: 'border-warning/25 bg-warning/10 text-warning-strong',
    danger: 'border-destructive/25 bg-destructive/10 text-destructive',
};

/** Q-2 (Daniel's design review 2026-11-05): a running treatment step is neutral (the icon and the word
 * "started" carry the meaning, keeping amber free); a done step uses the success tone. */
export const treatmentProgressTone = (
    state: 'started' | 'done' | null | undefined,
): StatusTone => (state === 'done' ? 'success' : 'neutral');

export type DateInput = Date | number | string | null | undefined;

const dateOnlyPattern = /^\d{4}-\d{2}-\d{2}$/;
const timestampPattern =
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/i;
const presentationFallback = '—';

const validCalendarDate = (value: string): Date | null => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(0);

    date.setUTCHours(0, 0, 0, 0);
    date.setUTCFullYear(year, month - 1, day);

    return year >= 1 &&
        date.getUTCFullYear() === year &&
        date.getUTCMonth() === month - 1 &&
        date.getUTCDate() === day
        ? date
        : null;
};

const validTimestamp = (value: DateInput): Date | null => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (value instanceof Date) {
        return Number.isFinite(value.getTime())
            ? new Date(value.getTime())
            : null;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) &&
            Number.isFinite(new Date(value).getTime())
            ? new Date(value)
            : null;
    }

    if (
        !timestampPattern.test(value) ||
        validCalendarDate(value.slice(0, 10)) === null
    ) {
        return null;
    }

    const date = new Date(value);

    return Number.isFinite(date.getTime()) ? date : null;
};

const normalizeDateText = (value: string): string =>
    value
        .replace(/\bSept\b/g, 'Sep')
        .replace(/\b(am|pm)\b/gi, (period) => period.toUpperCase());

export const formatDate = (
    value: DateInput,
    timeZone = 'Asia/Kuala_Lumpur',
): string => {
    const dateOnly = typeof value === 'string' && dateOnlyPattern.test(value);
    const date = dateOnly ? validCalendarDate(value) : validTimestamp(value);

    if (date === null) {
        return presentationFallback;
    }

    try {
        return normalizeDateText(
            new Intl.DateTimeFormat('en-MY', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
                timeZone: dateOnly ? 'UTC' : timeZone,
            }).format(date),
        );
    } catch {
        return presentationFallback;
    }
};

export const formatDateTime = (
    value: DateInput,
    timeZone = 'Asia/Kuala_Lumpur',
): string => {
    const date = validTimestamp(value);

    if (date === null) {
        return presentationFallback;
    }

    try {
        return normalizeDateText(
            new Intl.DateTimeFormat('en-MY', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true,
                timeZone,
            }).format(date),
        );
    } catch {
        return presentationFallback;
    }
};

export const formatElapsedMinutes = (minutes: number): string => {
    if (!Number.isFinite(minutes)) {
        return presentationFallback;
    }

    const safeMinutes = Math.max(0, Math.floor(minutes));

    if (safeMinutes < 1) {
        return '<1 min';
    }

    if (safeMinutes < 60) {
        return `${safeMinutes} min`;
    }

    const hours = Math.floor(safeMinutes / 60);
    const remainder = safeMinutes % 60;

    return remainder ? `${hours} hr ${remainder} min` : `${hours} hr`;
};

export const formatRelativeMinutes = (minutes: number): string => {
    const elapsed = formatElapsedMinutes(minutes);

    return elapsed === presentationFallback
        ? presentationFallback
        : `${elapsed} ago`;
};

export const formatWaitingMinutes = (minutes: number): string => {
    const elapsed = formatElapsedMinutes(minutes);

    return elapsed === presentationFallback
        ? presentationFallback
        : `Waiting ${elapsed}`;
};

/** "10/11" (day first) for a chart axis; any value that is not yyyy-mm-dd comes back unchanged. */
export const formatDayMonth = (value: string): string => {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

    return match ? `${Number(match[3])}/${Number(match[2])}` : value;
};
