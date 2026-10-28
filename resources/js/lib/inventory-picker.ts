export type InventoryPickerOption = {
    value: string | number;
    label: string;
    disabled?: boolean;
};
export type InventoryPickerRow = Record<
    string,
    string | number | boolean | null
>;
export function inventoryPickerUrl(input: {
    kind: string;
    context: string;
    expectedBranchId: number;
    query: string;
    sku?: string;
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

    return `/inventory/picker?${params}`;
}
export function inventoryPickerOptions(
    rows: InventoryPickerRow[],
    kind: string,
): InventoryPickerOption[] {
    return rows.slice(0, 100).map((row) => ({
        value: String(row.publicId ?? row.id),
        label:
            (row.held ? `Held: ${row.holdReason} · ` : '') +
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
