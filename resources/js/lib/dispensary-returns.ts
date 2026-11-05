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
    batches: { publicId: string; label: string }[];
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
