// DSP-7 medicine returns: the shape of the completed-case `returns` prop (DispensaryReturnService::panel).
export type DispensaryReturnRow = {
    publicId: string;
    quantity: string;
    reason: string;
    note: string | null;
    condition: string;
    status: string;
    recordedBy: string;
    recordedByMe: boolean;
    recordedAt: string;
    canCheck: boolean;
    allowedOutcomes: string[];
    check: {
        outcome: string;
        checkedBy: string;
        checkedAt: string;
        locationName: string | null;
        refuseReason: string | null;
        refuseNote: string | null;
        // DSP-15: the batch or batches a quarantined return went back to, earliest expiry first.
        batches: { label: string; quantity: string }[];
    } | null;
};

export type DispensaryReturnLine = {
    publicId: string;
    name: string;
    strength: string | null;
    dosageForm: string | null;
    code: string | null;
    unit: string;
    controlled: boolean;
    dispensed: string;
    returned: string;
    returnable: string;
    returnCount: number;
    // DSP-15: earliest expiry first; available is what can still go back to the batch (checkers only).
    batches: { publicId: string; label: string; available: string }[];
    quarantineAvailable: string | null;
    returns: DispensaryReturnRow[];
};

export type DispensaryReturnsPanel = {
    casePublicId: string;
    branchId: number;
    canRecord: boolean;
    canCheck: boolean;
    reasons: string[];
    conditions: string[];
    refuseReasons: string[];
    noteMax: number;
    returnsLocations: { publicId: string; name: string }[];
    lines: DispensaryReturnLine[];
};

export const returnReasonLabels: Record<string, string> = {
    not_used: 'Patient did not use it',
    wrong_medicine_or_strength: 'Wrong medicine or strength given',
    patient_reaction: 'Patient reaction',
    dispensing_error: 'Dispensing error',
    other: 'Other',
};

export const returnConditionLabels: Record<string, string> = {
    sealed_in_date: 'Sealed and in date',
    opened_or_damaged: 'Opened or damaged',
    cannot_tell: 'Cannot tell',
};

export const refuseReasonLabels: Record<string, string> = {
    wrong_case_or_patient: 'Wrong case or patient',
    wrong_medicine_line: 'Wrong medicine line',
    wrong_quantity: 'Wrong quantity',
    recorded_twice: 'Recorded twice',
    other: 'Other',
};

// DSP-15: thousandths of a quantity typed by a checker ("2.5" → 2500); null when it is not a valid number.
export const toThousandths = (value: string): number | null => {
    const trimmed = value.trim();

    if (trimmed === '') {
        return 0;
    }

    const match = /^(\d{1,11})(?:\.(\d{1,3}))?$/.exec(trimmed);

    if (match === null) {
        return null;
    }

    return Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));
};

// DSP-15: thousandths back to the short form the panel uses ("2500" → "2.5", "3000" → "3").
export const fromThousandths = (value: number): string => {
    const whole = Math.trunc(value / 1000);
    const part = String(Math.abs(value % 1000))
        .padStart(3, '0')
        .replace(/0+$/, '');

    return part === '' ? String(whole) : `${whole}.${part}`;
};

// DSP-15: the history line under a quarantined return.
export const batchesLine = (
    batches: { label: string; quantity: string }[],
    unit: string,
): string | null => {
    if (batches.length === 0) {
        return null;
    }

    if (batches.length === 1) {
        return `Batch ${batches[0].label}`;
    }

    return `Batches: ${batches.map((b) => `${b.label} (${b.quantity} ${unit})`).join(', ')}`;
};
