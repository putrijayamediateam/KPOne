/** Informational RM per stock unit, rounded half up to four places using integers. */
export function handCostPerUnit(
    totalSen: number,
    quantityScaled: number,
): string | null {
    if (
        !Number.isSafeInteger(totalSen) ||
        totalSen < 0 ||
        !Number.isSafeInteger(quantityScaled) ||
        quantityScaled <= 0
    ) {
        return null;
    }

    const quantity = BigInt(quantityScaled);
    const scaled = (BigInt(totalSen) * 100000n + quantity / 2n) / quantity;

    return `${scaled / 10000n}.${(scaled % 10000n).toString().padStart(4, '0')}`;
}
