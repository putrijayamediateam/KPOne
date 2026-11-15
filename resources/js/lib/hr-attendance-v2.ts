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
