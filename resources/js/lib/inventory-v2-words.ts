import { trimQuantity } from '@/lib/supplier-terms';

/**
 * Inventory V2 wording: "5 units", "1 unit", "2.5 tablets", "3 boxes". A stored quantity has three decimals
 * ("5.000"); decimals show only when there is a fraction (Daniel, page 2b). Without a unit, just the number.
 */
export const unitWord = (amount: number, unit: string): string =>
    unit === '' || amount === 1
        ? unit
        : /(s|x|z|ch|sh)$/i.test(unit)
          ? `${unit}es`
          : `${unit}s`;

export const quantityWithUnit = (
    value: string | number | null | undefined,
    unit?: string | null,
): string => {
    const text = trimQuantity(String(value ?? '0'));
    const word = unitWord(Number(text), unit ?? '');

    return word ? `${text} ${word}` : text;
};
