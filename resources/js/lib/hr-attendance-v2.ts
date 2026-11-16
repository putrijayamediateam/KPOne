/**
 * Version 2 Attendance helpers (pure; no requests). The correction form sends one local "YYYY-MM-DDTHH:MM" value per
 * press, exactly as Classic's date-and-time field does; V2 shows it as a date field and a time field and joins them
 * back. A half-filled pair is empty, so the server's own "enter a time" rule speaks, never a guessed time.
 */
export type LocalParts = { date: string; time: string };

const LOCAL = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/;

/** "2026-11-13T08:30" becomes { date: "2026-11-13", time: "08:30" }; anything else is two empty parts. */
export const splitLocal = (value: string | null | undefined): LocalParts => {
    const m = LOCAL.exec(value ?? '');

    return m ? { date: m[1], time: m[2] } : { date: '', time: '' };
};

/** { date, time } back to "YYYY-MM-DDTHH:MM", or '' while either part is missing. */
export const joinLocal = (date: string, time: string): string =>
    date !== '' && time !== '' ? `${date}T${time}` : '';

/**
 * Applies one field's change to a pair the page keeps as its own state and returns the request value. The pair keeps
 * what was typed even while the other half is empty, so a date picked first is not lost when the time follows.
 */
export const updatePair = (
    pair: LocalParts,
    change: Partial<LocalParts>,
): string => {
    Object.assign(pair, change);

    return joinLocal(pair.date, pair.time);
};

const NBSP = ' ';

/**
 * Keeps a time or a duration on one line (Daniel): a non-breaking space before AM or PM, and "h" and "min" glued to
 * their numbers. "8:00 AM" stays "8:00 AM" on screen; only the spaces that must not break change.
 */
export const glueTimes = (text: string | null | undefined): string =>
    (text ?? '')
        .replace(/(\d) (AM|PM)\b/g, `$1${NBSP}$2`)
        .replace(/(\d) (h|min)\b/g, `$1${NBSP}$2`);

/**
 * "Morning, 8:00 AM to 2:00 PM" becomes { name: "Morning,", times: "8:00 AM to 2:00 PM" }, so the Shift cell may
 * wrap only between the name and the times. A label with no name is all times.
 */
export const shiftParts = (
    label: string | null | undefined,
): { name: string; times: string } => {
    const value = label ?? '';
    const at = value.indexOf(', ');

    return at === -1
        ? { name: '', times: value }
        : { name: value.slice(0, at + 1), times: value.slice(at + 2) };
};
