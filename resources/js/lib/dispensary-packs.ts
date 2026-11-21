// DSP-24: whole packs (for example bottles of syrup) at Dispensary, through Inventory's checked STK-52 conversion.
// Stock and billing stay in the base unit (ml); the page sends both the ml and the packs, and the server checks that
// they agree with the conversion's current version. These helpers mirror DrugLabelLayout::packCount() on the server.

export type PackConversion = {
    publicId: string;
    version: number;
    packUnit: string;
    basePerPack: number;
    baseUnit: string;
};

export const MAX_PACKS = 99;

// Owner answer 14 = A: bottles are offered only when the conversion's base unit is the line's own unit.
export const usablePackConversion = (
    conversion: PackConversion | null | undefined,
    unit: string,
): PackConversion | null =>
    conversion && conversion.baseUnit.trim() === unit.trim()
        ? conversion
        : null;

/** "1 bottle", "2 bottles", "2 boxes". */
export const packCount = (packs: number, packUnit: string): string => {
    let unit = packUnit.trim();

    if (packs !== 1 && !/s$/i.test(unit)) {
        unit += /(x|ch|sh)$/i.test(unit) ? 'es' : 's';
    }

    return `${packs} ${unit}`;
};

/** "Bottles" for the unit choice. */
export const packChoiceLabel = (packUnit: string): string => {
    const plural = packCount(2, packUnit).replace(/^2 /, '');

    return plural.charAt(0).toUpperCase() + plural.slice(1);
};

/** A whole number of packs from 1 to 99, or null. */
export const parsePacks = (text: string): number | null => {
    const trimmed = text.trim();

    if (!/^\d{1,2}$/.test(trimmed)) {
        return null;
    }

    const packs = Number(trimmed);

    return packs >= 1 && packs <= MAX_PACKS ? packs : null;
};

/** The base quantity in the server's three-decimal form, for example 2 × 60 → "120.000". */
export const packsToBase = (
    packs: number,
    conversion: PackConversion,
): string => `${packs * conversion.basePerPack}.000`;

// Thousandths, so "120", "120.0" and "120.000" compare equal and no binary rounding creeps in.
const thousandths = (quantity: string): number | null => {
    const match = /^(\d+)(?:\.(\d{1,3}))?$/.exec(quantity.trim());

    return match
        ? Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'))
        : null;
};

/** Whole packs in a base quantity, or null when it does not divide evenly (or falls outside 1 to 99). */
export const basesToPacks = (
    quantity: string,
    conversion: PackConversion,
): number | null => {
    const value = thousandths(quantity);
    const per = conversion.basePerPack * 1000;

    if (value === null || value === 0 || value % per !== 0) {
        return null;
    }

    const packs = value / per;

    return packs <= MAX_PACKS ? packs : null;
};

export const sameQuantity = (a: string, b: string): boolean => {
    const left = thousandths(a);

    return left !== null && left === thousandths(b);
};

/** Trailing zeros dropped for display: "120.000" → "120", "12.500" → "12.5". */
export const displayQuantity = (quantity: string): string =>
    quantity.includes('.')
        ? quantity.replace(/0+$/, '').replace(/\.$/, '')
        : quantity;

/** "2 bottles = 120 ml". */
export const conversionLine = (
    packs: number,
    conversion: PackConversion,
): string =>
    `${packCount(packs, conversion.packUnit)} = ${displayQuantity(packsToBase(packs, conversion))} ${conversion.baseUnit}`;

/** " (3 bottles)" after a batch's stock, only when it divides evenly into whole packs. */
export const packsOnHand = (
    quantity: string,
    conversion: PackConversion | null,
): string => {
    if (!conversion) {
        return '';
    }

    const value = thousandths(quantity);
    const per = conversion.basePerPack * 1000;

    return value !== null && value > 0 && value % per === 0
        ? ` (${packCount(value / per, conversion.packUnit)})`
        : '';
};
