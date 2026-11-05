/**
 * STK-6 transfer receipt helpers. Quantities are exact thousandths of the base stock unit and are compared as
 * strings converted to BigInt, never as binary floating-point numbers. The server stays the authority.
 */
export type TransferReceiptField = 'accepted' | 'short' | 'damaged' | 'extra';

export type TransferReceiptRow = {
    linePublicId: string;
    dispatched: string;
    accepted: string;
    short: string;
    damaged: string;
    extra: string;
};

export type TransferReceiptLinePayload = {
    line_public_id: string;
    accepted_quantity: string;
    short_quantity: string;
    damaged_quantity: string;
    extra_quantity: string;
};

export type TransferDifferenceKind = 'short' | 'damaged' | 'extra';

export const transferDifferenceKindLabels: Record<
    TransferDifferenceKind,
    string
> = {
    short: 'Short (did not arrive)',
    damaged: 'Damaged (arrived, not usable)',
    extra: 'Extra (more than dispatched)',
};

export const transferOutcomeLabels: Record<string, string> = {
    lost_in_transit: 'Lost in transit (stays out of stock)',
    arrived_later: 'Arrived later (add to this branch stock)',
    written_off: 'Written off (stays out of stock)',
    accepted_after_inspection:
        'Usable after inspection (add to this branch stock)',
    returned_to_source:
        'Returned to the sending location (no stock change here)',
    recorded_by_stock_count: 'Kept and brought in by a governed stock count',
};

/** Every outcome needs a delivery note, inspection log or other reference, matching the server. */
export const transferOutcomesNeedingReference = [
    'lost_in_transit',
    'arrived_later',
    'written_off',
    'accepted_after_inspection',
    'returned_to_source',
    'recorded_by_stock_count',
];

const QUANTITY = /^(\d{1,12})(?:\.(\d{1,3}))?$/;

/** Thousandths as BigInt, or null when the text is not a plain decimal with at most three places. */
export const transferQuantityThousandths = (value: string): bigint | null => {
    const match = QUANTITY.exec(value.trim());

    if (!match) {
        return null;
    }

    return BigInt(match[1]) * 1000n + BigInt((match[2] ?? '').padEnd(3, '0'));
};

export const formatTransferThousandths = (value: bigint): string => {
    const whole = value / 1000n;
    const fraction = (value % 1000n).toString().padStart(3, '0');

    return `${whole}.${fraction}`;
};

export const defaultTransferReceiptRow = (
    linePublicId: string,
    dispatched: string,
): TransferReceiptRow => ({
    linePublicId,
    dispatched,
    accepted: dispatched,
    short: '0',
    damaged: '0',
    extra: '0',
});

/** Returns an error message for the row, or null when accepted + short + damaged equals the dispatched quantity. */
export const transferReceiptRowError = (
    row: TransferReceiptRow,
): string | null => {
    const dispatched = transferQuantityThousandths(row.dispatched);
    const values = (['accepted', 'short', 'damaged', 'extra'] as const).map(
        (field) => transferQuantityThousandths(row[field]),
    );

    if (dispatched === null || values.some((value) => value === null)) {
        return 'Enter plain quantities with at most three decimal places.';
    }

    const [accepted, short, damaged] = values as bigint[];

    if (accepted + short + damaged !== dispatched) {
        return `Accepted + short + damaged must equal the dispatched ${formatTransferThousandths(dispatched)}.`;
    }

    return null;
};

export const transferReceiptPayload = (
    rows: TransferReceiptRow[],
): TransferReceiptLinePayload[] =>
    rows.map((row) => ({
        line_public_id: row.linePublicId,
        accepted_quantity: row.accepted.trim(),
        short_quantity: row.short.trim(),
        damaged_quantity: row.damaged.trim(),
        extra_quantity: row.extra.trim(),
    }));

/** Number of non-zero differences the receipt will open for independent resolution. */
export const transferReceiptDifferenceCount = (
    rows: TransferReceiptRow[],
): number =>
    rows.reduce(
        (count, row) =>
            count +
            (['short', 'damaged', 'extra'] as const).filter(
                (field) => (transferQuantityThousandths(row[field]) ?? 0n) > 0n,
            ).length,
        0,
    );
