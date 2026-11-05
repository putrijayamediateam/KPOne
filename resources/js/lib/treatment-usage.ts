/** STK-8 treatment-room usage wording. The server stays the authority for every rule. */
export type UsageKind = 'usage' | 'waste';

export const usageKindLabels: Record<string, string> = {
    usage: 'Used in treatment',
    waste: 'Wasted',
};

export const usageReasonLabels: Record<string, string> = {
    procedure: 'Procedure',
    wound_care: 'Wound care or dressing',
    nebulisation: 'Nebulisation',
    injection: 'Injection',
    other_clinical_use: 'Other treatment use',
    opened_not_used: 'Opened but not used',
    spilled_or_broken: 'Spilled or broken',
    contaminated: 'Contaminated',
    other_waste: 'Other waste',
};

export const usageReversalLabels: Record<string, string> = {
    recorded_in_error: 'Recorded in error',
    wrong_item_or_batch: 'Wrong item or batch',
    wrong_quantity: 'Wrong quantity',
    wrong_location: 'Wrong location',
};

export const ineligibleBatchLabels: Record<string, string> = {
    expiry: 'expired',
    held: 'on hold',
    unavailable_status: 'not available',
    inactive_item: 'item inactive',
    inactive_sku: 'SKU inactive',
};

/** The first reason of a kind, so switching kind never leaves a reason the server would refuse. */
export function firstReason(
    reasons: Record<string, string[]>,
    kind: UsageKind,
): string {
    return reasons[kind]?.[0] ?? '';
}
