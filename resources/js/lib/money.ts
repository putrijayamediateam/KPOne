export const rmToSen = (amount: string | number) => {
    const normalized =
        typeof amount === 'number' ? String(amount) : amount.trim();

    return normalized === '' ? null : Math.round(Number(normalized) * 100);
};
