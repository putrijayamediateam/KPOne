/**
 * Time-of-day helpers for V2TimeField. No Date objects, no time zones: a time of day is just hours and minutes.
 * The canonical form is 24-hour "HH:MM" ("08:00", "14:30"); people read and type 12-hour "8:00 AM".
 */
export const TIME_ERROR = 'Enter a time such as 8:00 AM.';

export type ParsedTime = { hour: number; minute: number };

/** Reads "8:00 AM", "8am", "8.30 pm", "0800", "830", "1430", "14:30" and "8". Anything else returns null. */
export function parseTime(text: string): ParsedTime | null {
    const t = text
        .trim()
        .toLowerCase()
        .replace(/\./g, ':')
        .replace(/\s+/g, ' ');

    const match = /^(\d{1,4})(?::(\d{2}))?\s?(a|p|am|pm)?$/.exec(t);

    if (!match) {
        return null;
    }

    let hour: number;
    let minute: number;

    if (match[2] !== undefined) {
        if (match[1].length > 2) {
            return null;
        }

        hour = Number(match[1]);
        minute = Number(match[2]);
    } else if (match[1].length <= 2) {
        hour = Number(match[1]);
        minute = 0;
    } else {
        // "830" and "0800": the last two digits are the minutes.
        hour = Number(match[1].slice(0, -2));
        minute = Number(match[1].slice(-2));
    }

    if (minute > 59) {
        return null;
    }

    const meridiem = match[3]?.[0];

    if (meridiem) {
        if (hour < 1 || hour > 12) {
            return null;
        }

        hour = (hour % 12) + (meridiem === 'p' ? 12 : 0);
    } else if (hour > 23) {
        return null;
    }

    return { hour, minute };
}

export const toCanonical = (time: ParsedTime): string =>
    `${String(time.hour).padStart(2, '0')}:${String(time.minute).padStart(2, '0')}`;

export const toDisplay = (time: ParsedTime): string => {
    const h12 = time.hour % 12 === 0 ? 12 : time.hour % 12;

    return `${h12}:${String(time.minute).padStart(2, '0')} ${time.hour < 12 ? 'AM' : 'PM'}`;
};

/** "14:30" to "2:30 PM"; anything that is not a canonical time gives ''. */
export function displayFromCanonical(value: string): string {
    const match = /^(\d{2}):(\d{2})$/.exec(value);

    if (!match) {
        return '';
    }

    const time = { hour: Number(match[1]), minute: Number(match[2]) };

    return time.hour > 23 || time.minute > 59 ? '' : toDisplay(time);
}
