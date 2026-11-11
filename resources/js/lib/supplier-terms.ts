/**
 * STK-13: supplier pack prices are typed in ringgit and stored in whole sen, converted with whole-number
 * arithmetic only (the STK-10B rule), so a quoted price is never rounded.
 */

/** Sen for a typed RM amount: null when blank, undefined when it is not a valid amount. */
export const ringgitToSen = (
    value: string | number | null | undefined,
): number | null | undefined => {
    const text = String(value ?? '').trim();

    if (text === '') {
        return null;
    }

    const match = /^(\d{1,10})(?:\.(\d{1,2}))?$/.exec(text);

    if (!match) {
        return undefined;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
};

/** "12.34" for 1234 sen; an empty string for no price. */
export const formatRinggit = (sen: number | null | undefined): string =>
    sen === null || sen === undefined
        ? ''
        : `${Math.trunc(sen / 100)}.${String(sen % 100).padStart(2, '0')}`;
