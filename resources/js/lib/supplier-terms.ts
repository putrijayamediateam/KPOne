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

/** "100" for "100.000" and "2.5" for "2.500": an exact stock quantity without trailing zeros. */
export const trimQuantity = (value: string): string =>
    value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;

/**
 * "Box of 100" when the pack name already gives the count; otherwise "Strip (10 tablets)". A unit of one or two
 * letters (ml, g) or one already ending in "s" is not made plural.
 */
export const packLabel = (
    description: string,
    quantity: string,
    unit: string,
): string => {
    const count = trimQuantity(quantity);

    if (
        new RegExp(`(^|\\D)${count.replace('.', '\\.')}(\\D|$)`).test(
            description,
        )
    ) {
        return description;
    }

    const plural = count !== '1' && unit.length > 2 && !/s$/i.test(unit);

    return `${description} (${count} ${plural ? `${unit}s` : unit})`;
};
