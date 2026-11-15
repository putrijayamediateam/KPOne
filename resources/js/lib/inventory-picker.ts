export type InventoryPickerOption = {
    value: string | number;
    label: string;
    disabled?: boolean;
};
export type InventoryPickerRow = Record<
    string,
    string | number | boolean | null | undefined
> & {
    holdReason?: string | null;
};
export function inventoryPickerUrl(input: {
    kind: string;
    context: string;
    expectedBranchId: number;
    query: string;
    sku?: string;
    /** Batches only (V2 page 2b): also return how much of each batch is at this location. */
    location?: string;
}): string {
    const params = new URLSearchParams({
        kind: input.kind,
        context: input.context,
        expected_branch_id: String(input.expectedBranchId),
        q: input.query,
        limit: '25',
    });

    if (input.sku) {
        params.set('sku', input.sku);
    }

    if (input.location) {
        params.set('location', input.location);
    }

    return `/inventory/picker?${params}`;
}
export function inventoryPickerOptions(
    rows: InventoryPickerRow[],
    kind: string,
): InventoryPickerOption[] {
    return rows.slice(0, 100).map((row) => ({
        value: String(row.publicId ?? row.id),
        label:
            (row.held
                ? `${formatInventoryHoldLabel(formatInventoryReason(row.holdReason))} · `
                : '') +
            String(
                row.skuCode ??
                    (row.batchNumber
                        ? `${row.batchNumber} · ${row.expiryDate}`
                        : row.genericName
                          ? `${row.code} · ${row.genericName}`
                          : row.displayName
                            ? `${row.code} · ${row.displayName}`
                            : row.code
                              ? `${row.code} · ${row.name}`
                              : row.type
                                ? `${row.name} · ${row.type}`
                                : row.name),
            ),
        disabled:
            row.held === true ||
            (kind === 'suppliers' && row.isActive === false),
    }));
}
export function mergeInventoryPickerOptions(
    rows: InventoryPickerOption[],
    empty?: InventoryPickerOption,
    selected?: InventoryPickerOption,
): InventoryPickerOption[] {
    const unique = new Map<string, InventoryPickerOption>();

    for (const option of [empty, selected, ...rows.slice(0, 100)]) {
        if (option) {
            unique.set(String(option.value), option);
        }
    }

    return [...unique.values()];
}

const reasonLabels: Record<string, string> = {
    suspected_quality_issue: 'Suspected quality issue',
    recall: 'Recall',
    damaged: 'Damaged',
    other_safety: 'Other safety concern',
    cleared_by_supplier: 'Cleared by supplier',
    inspection_passed: 'Inspection passed',
    other_approved: 'Other approved reason',
    wrong_item: 'Wrong item',
    short_expiry: 'Short expiry',
    documentation_missing: 'Missing documentation',
    overage: 'Over delivery',
    short: 'Short delivery',
    expiry_problem: 'Expiry problem',
    documentation: 'Documentation issue',
    correction: 'Receipt correction',
    duplicate_delivery: 'Duplicate delivery',
    incorrect_quantity: 'Incorrect quantity',
    count_variance: 'Count variance',
    incorrect_batch: 'Incorrect batch',
    // STK-25: an organisation-wide recall, unlike an STK-2 branch hold for "recall".
    all_branch_recall: 'Recalled at every branch',
};

export const formatInventoryReason = (
    value: string | null | undefined,
): string => {
    if (!value) {
        return '';
    }

    return (
        reasonLabels[value] ??
        value
            .replaceAll('_', ' ')
            .replace(/^./, (character) => character.toUpperCase())
    );
};

export const formatInventoryHoldLabel = (reason: string): string => {
    return reason ? `Held: ${reason}` : 'Held';
};
