// V2 Dispensary (Daniel): read-only quantities read as words, for example "30 tablets" and "1 tablet". The stored and
// typed values stay exact; only count units get a plural, so "ml" and "mg" are never changed.
const countUnits = new Set([
    'tablet',
    'capsule',
    'sachet',
    'bottle',
    'tube',
    'vial',
    'ampoule',
    'strip',
    'box',
    'pack',
    'piece',
    'patch',
    'suppository',
    'lozenge',
]);

export const quantityNumber = (quantity: string | number): string => {
    const text = String(quantity).trim();

    return text.includes('.') ? text.replace(/\.?0+$/, '') : text;
};

export const quantityWords = (
    quantity: string | number,
    unit: string | null | undefined,
): string => {
    const amount = quantityNumber(quantity);
    const word = (unit ?? '').trim();

    if (word === '') {
        return amount;
    }

    if (amount === '1' || !countUnits.has(word.toLowerCase())) {
        return `${amount} ${word}`;
    }

    return `${amount} ${word}${word.toLowerCase().endsWith('x') ? 'es' : 's'}`;
};
