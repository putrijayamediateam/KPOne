export type StatusTone =
    'neutral' | 'accent' | 'success' | 'warning' | 'danger';

const statusLabels: Record<string, string> = {
    approved: 'Approved',
    cancelled: 'Cancelled',
    completed: 'Completed',
    dispensed: 'Dispensed',
    dispensing: 'Dispensing',
    finalized: 'Finalized',
    in_progress: 'In Progress',
    not_dispensed: 'Not Dispensed',
    partial: 'Partial',
    pending: 'Pending',
    posted: 'Posted',
    proposed: 'Proposed',
    registered: 'Registered',
    rejected: 'Rejected',
    removed: 'Removed',
    returned: 'Returned',
    serving: 'Serving',
    void: 'Void',
    waiting: 'Waiting',
};

const toneByStatus: Record<string, StatusTone> = {
    approved: 'success',
    completed: 'success',
    dispensed: 'success',
    finalized: 'success',
    posted: 'success',
    dispensing: 'accent',
    serving: 'accent',
    returned: 'accent',
    partial: 'warning',
    pending: 'warning',
    proposed: 'warning',
    waiting: 'warning',
    cancelled: 'danger',
    not_dispensed: 'danger',
    rejected: 'danger',
    void: 'danger',
};

export const formatStatusLabel = (status: string): string => {
    const normalized = status.trim().toLocaleLowerCase().replaceAll('-', '_');

    return (
        statusLabels[normalized] ??
        normalized
            .replaceAll('_', ' ')
            .replace(/\b\p{L}/gu, (character) => character.toLocaleUpperCase())
    );
};

export const statusTone = (status: string): StatusTone =>
    toneByStatus[status.trim().toLocaleLowerCase().replaceAll('-', '_')] ??
    'neutral';

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
