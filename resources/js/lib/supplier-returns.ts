/** STK-7 supplier return wording. The server stays the authority for every rule. */
export type SupplierReturnAction =
    'approved' | 'rejected' | 'cancelled' | 'dispatched' | 'credit_recorded';

export const supplierReturnStateLabels: Record<string, string> = {
    awaiting_approval: 'Waiting for approval',
    approved: 'Approved, not yet dispatched',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
    dispatched: 'Dispatched, waiting for credit note',
    credited: 'Credit note recorded',
};

export const supplierReturnActionLabels: Record<string, string> = {
    requested: 'Requested',
    approved: 'Approve',
    rejected: 'Reject',
    cancelled: 'Cancel return',
    dispatched: 'Dispatch to supplier',
    credit_recorded: 'Record credit note',
};

export const supplierReturnHistoryLabels: Record<string, string> = {
    requested: 'Requested',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
    dispatched: 'Dispatched',
    credit_recorded: 'Credit note recorded',
};

export const supplierReturnReasonLabels: Record<string, string> = {
    damaged: 'Damaged',
    short_expiry: 'Expiry too short',
    quality_issue: 'Quality issue',
    recall: 'Supplier or regulator recall',
    wrong_item: 'Wrong item',
    overstock_agreed: 'Overstock, agreed with supplier',
    other_agreed: 'Other, agreed with supplier',
};

export const supplierReturnRejectLabels: Record<string, string> = {
    not_agreed_by_supplier: 'Supplier has not agreed',
    stock_still_usable: 'Stock is still usable',
    wrong_quantity: 'Wrong quantity',
    other: 'Other',
};

/**
 * Ringgit text to whole sen with exact integer arithmetic. Blank stays blank (the amount is optional);
 * anything that is not a ringgit amount is sent as a marker the server refuses instead of dropping it.
 */
export function rmToSen(value: string): string {
    const text = value.trim();
    const match = /^(\d{1,10})(?:\.(\d{1,2}))?$/.exec(text);

    if (!match) {
        // Never pass typed digits through: they could read as valid sen at a different scale.
        return text === '' ? '' : 'invalid';
    }

    return (
        BigInt(match[1]) * 100n +
        BigInt((match[2] ?? '').padEnd(2, '0'))
    ).toString();
}
