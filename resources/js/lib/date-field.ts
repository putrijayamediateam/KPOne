// Pure helpers for the KPOne date, date-time and month fields (UX-17).
// Staff see and type dd/mm/yyyy whatever the browser or operating system language is;
// the value that leaves the field is the same yyyy-mm-dd, yyyy-mm-ddTHH:mm or yyyy-mm
// string a native input sends, so nothing on the server changes.

const pad = (value: number, length = 2): string =>
    String(value).padStart(length, '0');

export const daysInMonth = (year: number, month: number): number =>
    new Date(year, month, 0).getDate();

export const isRealDate = (year: number, month: number, day: number): boolean =>
    Number.isInteger(year) &&
    year >= 1000 &&
    year <= 9999 &&
    month >= 1 &&
    month <= 12 &&
    day >= 1 &&
    day <= daysInMonth(year, month);

export const toIso = (year: number, month: number, day: number): string =>
    `${pad(year, 4)}-${pad(month)}-${pad(day)}`;

/** The yyyy-mm-dd string when it is a real calendar date, otherwise null. */
export const normaliseIso = (
    value: string | null | undefined,
): string | null => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');

    if (!match) {
        return null;
    }

    const [year, month, day] = [
        Number(match[1]),
        Number(match[2]),
        Number(match[3]),
    ];

    return isRealDate(year, month, day) ? toIso(year, month, day) : null;
};

/** "10/11/2026" for "2026-11-10"; empty when the value is not a real date. */
export const formatDisplayDate = (iso: string | null | undefined): string => {
    const clean = normaliseIso(iso);

    return clean
        ? `${clean.slice(8, 10)}/${clean.slice(5, 7)}/${clean.slice(0, 4)}`
        : '';
};

/**
 * Reads what a person typed or pasted. Accepts d/m/yyyy, dd/mm/yyyy, the same with - or .
 * as separators, eight digits with no separator (10112026) and a pasted yyyy-mm-dd.
 * Returns yyyy-mm-dd, or null when it is not a complete real date.
 */
export const parseDisplayDate = (
    text: string | null | undefined,
): string | null => {
    const value = (text ?? '').trim();

    if (/^\d{4}-\d{1,2}-\d{1,2}$/.test(value)) {
        const [year, month, day] = value.split('-').map(Number);

        return isRealDate(year, month, day) ? toIso(year, month, day) : null;
    }

    const parts = /^\d{8}$/.test(value)
        ? [value.slice(0, 2), value.slice(2, 4), value.slice(4)]
        : value.split(/[/.-]/);

    if (parts.length !== 3 || parts.some((part) => !/^\d+$/.test(part))) {
        return null;
    }

    const [day, month, year] = parts.map(Number);

    if (parts[2].length !== 4) {
        return null;
    }

    return isRealDate(year, month, day) ? toIso(year, month, day) : null;
};

/**
 * Keeps the box tidy while someone types: digits and separators only, the slash added after
 * a complete day or month, at most dd/mm/yyyy. Deleting is never rewritten.
 */
export const maskDateInput = (next: string, previous = ''): string => {
    if (next.length < previous.length) {
        return next;
    }

    // A paste adds more than one character at once: read it whole (10112026, 10-11-2026, 2026-11-10).
    if (next.length - previous.length > 1) {
        const pasted = parseDisplayDate(next);

        if (pasted) {
            return formatDisplayDate(pasted);
        }
    }

    const cleaned = next
        .replace(/[-.]/g, '/')
        .replace(/[^\d/]/g, '')
        .replace(/\/{2,}/g, '/');
    const parts = cleaned.split('/').slice(0, 3);

    parts[0] = parts[0].slice(0, 2);

    if (parts[1] !== undefined) {
        parts[1] = parts[1].slice(0, 2);
    }

    if (parts[2] !== undefined) {
        parts[2] = parts[2].slice(0, 4);
    }

    let result = parts.join('/');

    if (
        parts.length < 3 &&
        parts[parts.length - 1].length === 2 &&
        !cleaned.endsWith('/')
    ) {
        result += '/';
    }

    return result.startsWith('/') ? result.slice(1) : result;
};

/** True when the date is inside the optional min and max (both inclusive, yyyy-mm-dd). */
export const withinRange = (
    iso: string,
    min?: string | null,
    max?: string | null,
): boolean => (!min || iso >= min) && (!max || iso <= max);

/** "11/2026" for "2026-11". */
export const formatDisplayMonth = (
    value: string | null | undefined,
): string => {
    const match = /^(\d{4})-(\d{2})$/.exec(value ?? '');

    return match && Number(match[2]) >= 1 && Number(match[2]) <= 12
        ? `${match[2]}/${match[1]}`
        : '';
};

/** Reads "11/2026", "1/2026" or a pasted "2026-11"; returns yyyy-mm or null. */
export const parseDisplayMonth = (
    text: string | null | undefined,
): string | null => {
    const value = (text ?? '').trim();
    const iso = /^(\d{4})-(\d{1,2})$/.exec(value);
    const display = /^(\d{1,2})[/.-](\d{4})$/.exec(value);
    const [year, month] = iso
        ? [Number(iso[1]), Number(iso[2])]
        : display
          ? [Number(display[2]), Number(display[1])]
          : [NaN, NaN];

    return Number.isInteger(year) && year >= 1000 && month >= 1 && month <= 12
        ? `${pad(year, 4)}-${pad(month)}`
        : null;
};

export const maskMonthInput = (next: string, previous = ''): string => {
    if (next.length < previous.length) {
        return next;
    }

    if (next.length - previous.length > 1) {
        const pasted = parseDisplayMonth(next);

        if (pasted) {
            return formatDisplayMonth(pasted);
        }
    }

    const cleaned = next
        .replace(/[-.]/g, '/')
        .replace(/[^\d/]/g, '')
        .replace(/\/{2,}/g, '/');
    const parts = cleaned.split('/').slice(0, 2);

    parts[0] = parts[0].slice(0, 2);

    if (parts[1] !== undefined) {
        parts[1] = parts[1].slice(0, 4);
    }

    let result = parts.join('/');

    if (parts.length < 2 && parts[0].length === 2 && !cleaned.endsWith('/')) {
        result += '/';
    }

    return result.startsWith('/') ? result.slice(1) : result;
};

export type Meridiem = 'AM' | 'PM';

/** "17:16" becomes { hour: '5', minute: '16', meridiem: 'PM' } (12-hour, as the screens show time). */
export const to12Hour = (
    time: string | null | undefined,
): { hour: string; minute: string; meridiem: Meridiem } | null => {
    const match = /^(\d{2}):(\d{2})$/.exec(time ?? '');

    if (!match || Number(match[1]) > 23 || Number(match[2]) > 59) {
        return null;
    }

    const hour24 = Number(match[1]);

    return {
        hour: String(hour24 % 12 === 0 ? 12 : hour24 % 12),
        minute: match[2],
        meridiem: hour24 >= 12 ? 'PM' : 'AM',
    };
};

/** 12-hour parts back to "HH:mm"; null unless hour is 1 to 12 and minute 0 to 59. */
export const from12Hour = (
    hour: string,
    minute: string,
    meridiem: Meridiem,
): string | null => {
    if (!/^\d{1,2}$/.test(hour) || !/^\d{1,2}$/.test(minute)) {
        return null;
    }

    const [h, m] = [Number(hour), Number(minute)];

    if (h < 1 || h > 12 || m > 59) {
        return null;
    }

    const hour24 = (h % 12) + (meridiem === 'PM' ? 12 : 0);

    return `${pad(hour24)}:${pad(m)}`;
};

export const splitDateTime = (
    value: string | null | undefined,
): { date: string; time: string } => {
    const match = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/.exec(value ?? '');

    return match ? { date: match[1], time: match[2] } : { date: '', time: '' };
};

export type CalendarCell = { iso: string; day: number; inMonth: boolean };

/** Weeks (Monday first, the owner's choice) covering one month, for the calendar grid. */
export const calendarWeeks = (
    year: number,
    month: number,
): CalendarCell[][] => {
    const first = new Date(year, month - 1, 1);
    const start = new Date(year, month - 1, 1 - ((first.getDay() + 6) % 7));
    const weeks: CalendarCell[][] = [];

    for (let week = 0; week < 6; week += 1) {
        const cells: CalendarCell[] = [];

        for (let dayOfWeek = 0; dayOfWeek < 7; dayOfWeek += 1) {
            const date = new Date(
                start.getFullYear(),
                start.getMonth(),
                start.getDate() + week * 7 + dayOfWeek,
            );

            cells.push({
                iso: toIso(
                    date.getFullYear(),
                    date.getMonth() + 1,
                    date.getDate(),
                ),
                day: date.getDate(),
                inMonth: date.getMonth() === month - 1,
            });
        }

        if (week >= 4 && !cells.some((cell) => cell.inMonth)) {
            break;
        }

        weeks.push(cells);
    }

    return weeks;
};

export const addDaysIso = (iso: string, amount: number): string => {
    const [year, month, day] = iso.split('-').map(Number);
    const date = new Date(year, month - 1, day + amount);

    return toIso(date.getFullYear(), date.getMonth() + 1, date.getDate());
};

/** Moves by whole months, keeping the day where the month is long enough, else the last day. */
export const addMonthsIso = (iso: string, amount: number): string => {
    const [year, month, day] = iso.split('-').map(Number);
    const target = new Date(year, month - 1 + amount, 1);
    const last = daysInMonth(target.getFullYear(), target.getMonth() + 1);

    return toIso(
        target.getFullYear(),
        target.getMonth() + 1,
        Math.min(day, last),
    );
};

/** Today in Kuala Lumpur as yyyy-mm-dd, whatever time zone the computer is set to. */
export const todayIso = (now: Date = new Date()): string => {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Kuala_Lumpur',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);
    const pick = (type: string): string =>
        parts.find((part) => part.type === type)?.value ?? '';

    return `${pick('year')}-${pick('month')}-${pick('day')}`;
};

export const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

export const MONTH_NAMES_MS = [
    'Januari',
    'Februari',
    'Mac',
    'April',
    'Mei',
    'Jun',
    'Julai',
    'Ogos',
    'September',
    'Oktober',
    'November',
    'Disember',
];

export type DateFieldLocale = 'en' | 'ms';

type FieldText = {
    choose: string;
    chooseMonth: string;
    formatHint: string;
    monthFormatHint: string;
    today: string;
    thisMonth: string;
    clear: string;
    previousMonth: string;
    nextMonth: string;
    previousYear: string;
    nextYear: string;
    invalidDate: string;
    invalidMonth: string;
    invalidTime: string;
    notRealDate: (typed: string) => string;
    notRealMonth: (typed: string) => string;
    rangeBetween: (from: string, to: string) => string;
    rangeFrom: (from: string) => string;
    rangeUntil: (to: string) => string;
    placeholder: string;
    monthPlaceholder: string;
    hour: string;
    hourOnly: string;
    minute: string;
    meridiem: string;
    weekdays: string[];
};

/** Words the fields show. Staff screens are English; the public QR form passes 'ms'. */
export const DATE_FIELD_TEXT: Record<DateFieldLocale, FieldText> = {
    en: {
        choose: 'Choose date (dd/mm/yyyy)',
        chooseMonth: 'Choose month (mm/yyyy)',
        formatHint: 'Format dd/mm/yyyy',
        monthFormatHint: 'Format mm/yyyy',
        today: 'Today',
        thisMonth: 'This month',
        clear: 'Clear',
        previousMonth: 'Previous month',
        nextMonth: 'Next month',
        previousYear: 'Previous year',
        nextYear: 'Next year',
        invalidDate: 'Enter a date as dd/mm/yyyy.',
        invalidMonth: 'Enter a month as mm/yyyy.',
        invalidTime: 'Enter a time such as 5:30.',
        notRealDate: (typed) => `${typed} is not a real date.`,
        notRealMonth: (typed) => `${typed} is not a real month.`,
        rangeBetween: (from, to) => `Choose a date from ${from} to ${to}.`,
        rangeFrom: (from) => `Choose ${from} or later.`,
        rangeUntil: (to) => `Choose ${to} or earlier.`,
        placeholder: 'dd/mm/yyyy',
        monthPlaceholder: 'mm/yyyy',
        hour: 'Time',
        hourOnly: 'Hour',
        minute: 'Minutes',
        meridiem: 'AM or PM',
        weekdays: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    },
    ms: {
        choose: 'Pilih tarikh (dd/mm/yyyy)',
        chooseMonth: 'Pilih bulan (mm/yyyy)',
        formatHint: 'Format dd/mm/yyyy',
        monthFormatHint: 'Format mm/yyyy',
        today: 'Hari ini',
        thisMonth: 'Bulan ini',
        clear: 'Kosongkan',
        previousMonth: 'Bulan sebelumnya',
        nextMonth: 'Bulan seterusnya',
        previousYear: 'Tahun sebelumnya',
        nextYear: 'Tahun seterusnya',
        invalidDate: 'Masukkan tarikh sebagai dd/mm/yyyy.',
        invalidMonth: 'Masukkan bulan sebagai mm/yyyy.',
        invalidTime: 'Masukkan masa seperti 5:30.',
        notRealDate: (typed) => `${typed} bukan tarikh yang sah.`,
        notRealMonth: (typed) => `${typed} bukan bulan yang sah.`,
        rangeBetween: (from, to) => `Pilih tarikh dari ${from} hingga ${to}.`,
        rangeFrom: (from) => `Pilih ${from} atau selepas.`,
        rangeUntil: (to) => `Pilih ${to} atau sebelum.`,
        placeholder: 'dd/mm/yyyy',
        monthPlaceholder: 'mm/yyyy',
        hour: 'Masa',
        hourOnly: 'Jam',
        minute: 'Minit',
        meridiem: 'AM atau PM',
        weekdays: ['Isn', 'Sel', 'Rab', 'Kha', 'Jum', 'Sab', 'Ahd'],
    },
};

/** The sentence for a date outside min and max, saying what the range is. */
export const rangeSentence = (
    locale: DateFieldLocale,
    min: string | undefined,
    max: string | undefined,
    format: (value: string) => string,
): string => {
    const text = DATE_FIELD_TEXT[locale];

    if (min && max) {
        return text.rangeBetween(format(min), format(max));
    }

    return min
        ? text.rangeFrom(format(min))
        : text.rangeUntil(format(max ?? ''));
};

/** What is wrong with the typed text, in words; '' when it is a usable date inside the range. */
export const dateProblem = (
    typed: string,
    min: string | undefined,
    max: string | undefined,
    locale: DateFieldLocale = 'en',
): string => {
    const value = typed.trim();
    const text = DATE_FIELD_TEXT[locale];

    if (value === '') {
        return '';
    }

    const iso = parseDisplayDate(value);

    if (iso === null) {
        const shaped =
            /^\d{1,2}[/.-]\d{1,2}[/.-]\d{4}$|^\d{8}$|^\d{4}-\d{1,2}-\d{1,2}$/.test(
                value,
            );

        return shaped ? text.notRealDate(value) : text.invalidDate;
    }

    return withinRange(iso, min, max)
        ? ''
        : rangeSentence(locale, min, max, formatDisplayDate);
};

export const monthProblem = (
    typed: string,
    min: string | undefined,
    max: string | undefined,
    locale: DateFieldLocale = 'en',
): string => {
    const value = typed.trim();
    const text = DATE_FIELD_TEXT[locale];

    if (value === '') {
        return '';
    }

    const month = parseDisplayMonth(value);

    if (month === null) {
        return /^\d{1,2}[/.-]\d{4}$|^\d{4}-\d{1,2}$/.test(value)
            ? text.notRealMonth(value)
            : text.invalidMonth;
    }

    return (!min || month >= min) && (!max || month <= max)
        ? ''
        : rangeSentence(locale, min, max, formatDisplayMonth);
};

const SHORT_MONTHS = [
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
const SHORT_MONTHS_MS = [
    'Jan',
    'Feb',
    'Mac',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Ogo',
    'Sep',
    'Okt',
    'Nov',
    'Dis',
];

/** "10 Nov 2026" for the echo under the box (same idea as the ringgit echo on the limits page). */
export const formatEchoDate = (
    iso: string | null | undefined,
    locale: DateFieldLocale = 'en',
): string => {
    const clean = normaliseIso(iso);

    if (!clean) {
        return '';
    }

    const names = locale === 'ms' ? SHORT_MONTHS_MS : SHORT_MONTHS;

    return `${Number(clean.slice(8, 10))} ${names[Number(clean.slice(5, 7)) - 1]} ${clean.slice(0, 4)}`;
};

/** "5:16 PM" for the echo of a date and time. */
export const formatEchoTime = (time: string | null | undefined): string => {
    const parts = to12Hour(time);

    return parts ? `${parts.hour}:${parts.minute} ${parts.meridiem}` : '';
};
