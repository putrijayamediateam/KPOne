/**
 * Date helpers for V2DateField. No Date-parsing of free text, no time zones: a date is just a day, a month and a year,
 * typed DAY FIRST. The canonical form is ISO "YYYY-MM-DD"; people read and type "13 Nov 2026".
 */
export type DateProblem = { message: string };

export type ParsedDate = { iso: string; display: string; weekday: string };

export type DateReading =
    { ok: true; date: ParsedDate } | { ok: false; problem: DateProblem };

const MONTHS = [
    ['jan', 'januari', 'january'],
    ['feb', 'februari', 'february'],
    ['mar', 'mac', 'march'],
    ['apr', 'april'],
    ['may', 'mei'],
    ['jun', 'june'],
    ['jul', 'julai', 'july'],
    ['aug', 'ogos', 'august'],
    ['sep', 'sept', 'september'],
    ['oct', 'okt', 'october', 'oktober'],
    ['nov', 'november'],
    ['dec', 'dis', 'december', 'disember'],
];

const SHORT = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export const EXAMPLE = '13 Nov 2026';
export const MSG_FORMAT = `Enter a date such as ${EXAMPLE}.`;
export const MSG_YEAR = 'Type the full year, such as 2026.';
export const MSG_DAY_FIRST = 'Type the date day first, such as 13 Dec 2026.';
export const MSG_REAL = `Enter a real date such as ${EXAMPLE}.`;

const pad = (n: number) => String(n).padStart(2, '0');

const isLeap = (year: number) =>
    (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;

const daysIn = (month: number, year: number) =>
    month === 2
        ? isLeap(year)
            ? 29
            : 28
        : [4, 6, 9, 11].includes(month)
          ? 30
          : 31;

const monthFromName = (name: string): number | null => {
    const index = MONTHS.findIndex((names) => names.includes(name));

    return index < 0 ? null : index + 1;
};

export const toIso = (year: number, month: number, day: number) =>
    `${year}-${pad(month)}-${pad(day)}`;

export function describe(year: number, month: number, day: number): ParsedDate {
    // Zero o'clock UTC on that day: only used to ask which weekday it is, never shown as a time.
    const weekday =
        WEEKDAYS[new Date(Date.UTC(year, month - 1, day)).getUTCDay()];

    return {
        iso: toIso(year, month, day),
        display: `${day} ${SHORT[month - 1]} ${year}`,
        weekday,
    };
}

/** Reads "13 Nov 2026", "13/11/2026", "13-11-2026", "13.11.2026", "13112026", "13 Mei 2026". Day first, four-digit year. */
export function readDate(text: string): DateReading {
    const t = text.trim().toLowerCase().replace(/\s+/g, ' ');
    let dayText: string;
    let monthText: string;
    let yearText: string;

    const eight = /^(\d{2})(\d{2})(\d{4})$/.exec(t);
    const parts =
        /^(\d{1,2})(?: ?[/.\-] ?| )([a-z]+|\d{1,2})(?: ?[/.\-] ?| )(\d{1,4})$/.exec(
            t,
        );

    if (eight) {
        [, dayText, monthText, yearText] = eight;
    } else if (parts) {
        [, dayText, monthText, yearText] = parts;
    } else {
        return { ok: false, problem: { message: MSG_FORMAT } };
    }

    if (yearText.length !== 4) {
        return { ok: false, problem: { message: MSG_YEAR } };
    }

    const day = Number(dayText);
    const year = Number(yearText);
    const named = /^[a-z]+$/.test(monthText);
    const month = named ? monthFromName(monthText) : Number(monthText);

    if (month === null) {
        return { ok: false, problem: { message: MSG_FORMAT } };
    }

    if (!named && month > 12) {
        // "11/13/2026": a month-first date. Say so, never swap the numbers silently.
        return {
            ok: false,
            problem: { message: day <= 12 ? MSG_DAY_FIRST : MSG_REAL },
        };
    }

    if (month < 1 || day < 1 || day > 31) {
        return { ok: false, problem: { message: MSG_REAL } };
    }

    if (day > daysIn(month, year)) {
        return {
            ok: false,
            problem: {
                message:
                    month === 2 && day === 29
                        ? `There is no 29 Feb in ${year}.`
                        : `There is no ${day} ${SHORT[month - 1]}.`,
            },
        };
    }

    return { ok: true, date: describe(year, month, day) };
}

/** "2026-11-13" to its parts; anything that is not a real canonical date gives null. */
export function fromIso(value: string): ParsedDate | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return null;
    }

    const [year, month, day] = [
        Number(match[1]),
        Number(match[2]),
        Number(match[3]),
    ];

    return month >= 1 && month <= 12 && day >= 1 && day <= daysIn(month, year)
        ? describe(year, month, day)
        : null;
}

export const displayFromIso = (value: string): string =>
    fromIso(value)?.display ?? '';

/**
 * The message when `iso` is outside the optional limits, else undefined. With a `subject` the page names the thing
 * ("Expiry"): "Expiry must be on or after 13 Nov 2026."; without one: "Choose a date on or after 13 Nov 2026."
 */
export function rangeProblem(
    iso: string,
    min?: string,
    max?: string,
    subject?: string,
): string | undefined {
    const lead = subject ? `${subject} must be` : 'Choose a date';

    if (min && iso < min) {
        return `${lead} on or after ${displayFromIso(min)}.`;
    }

    if (max && iso > max) {
        return `${lead} on or before ${displayFromIso(max)}.`;
    }

    return undefined;
}
