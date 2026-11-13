/**
 * Time-of-day helpers for V2TimeField. No Date objects, no time zones: a time of day is just hours and minutes.
 * The canonical form is 24-hour "HH:MM" ("08:00", "14:30"); people read and type 12-hour "8:00 AM".
 */
export const TIME_ERROR = 'Enter a time such as 8:00 AM.';
export const TIME_ERROR_AMPM = 'Add AM or PM, such as 5 PM.';

export type ParsedTime = { hour: number; minute: number };

type Reading = { time: ParsedTime; ambiguous: boolean };

/**
 * Reads the text. `ambiguous` is true when there is no AM or PM and the text could be read either way ("5", "5:30",
 * "830", "12"). A time with no AM or PM is clear ONLY as a 24-hour form: an hour of 13 to 23 ("14:30", "17"), or a
 * leading zero ("0830", "08:30", "08") or midnight ("0:00", "00:00").
 */
function read(text: string): Reading | null {
    const t = text
        .trim()
        .toLowerCase()
        .replace(/\./g, ':')
        .replace(/\s+/g, ' ');

    const match = /^(\d{1,4})(?::(\d{2}))?\s?(a|p|am|pm)?$/.exec(t);

    if (!match) {
        return null;
    }

    let minute: number;
    let hourText: string;

    if (match[2] !== undefined) {
        if (match[1].length > 2) {
            return null;
        }

        hourText = match[1];
        minute = Number(match[2]);
    } else if (match[1].length <= 2) {
        hourText = match[1];
        minute = 0;
    } else {
        // "830" and "0800": the last two digits are the minutes.
        hourText = match[1].slice(0, -2);
        minute = Number(match[1].slice(-2));
    }

    const hour = Number(hourText);

    if (minute > 59) {
        return null;
    }

    const meridiem = match[3]?.[0];

    if (meridiem) {
        if (hour < 1 || hour > 12) {
            return null;
        }

        return {
            time: { hour: (hour % 12) + (meridiem === 'p' ? 12 : 0), minute },
            ambiguous: false,
        };
    }

    if (hour > 23) {
        return null;
    }

    const clear24 =
        hour >= 13 ||
        hour === 0 ||
        (hourText.length === 2 && hourText[0] === '0');

    return { time: { hour, minute }, ambiguous: !clear24 };
}

/** Reads "8:00 AM", "8am", "8.30 pm", "0830", "1430", "14:30". Ambiguous bare hours ("5", "830") and junk give null. */
export function parseTime(text: string): ParsedTime | null {
    const reading = read(text);

    return reading && !reading.ambiguous ? reading.time : null;
}

/** Why the text is not a time: 'ambiguous' (add AM or PM), 'invalid', or null when it is a clear time. */
export function timeProblem(text: string): 'ambiguous' | 'invalid' | null {
    const reading = read(text);

    return reading === null
        ? 'invalid'
        : reading.ambiguous
          ? 'ambiguous'
          : null;
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
