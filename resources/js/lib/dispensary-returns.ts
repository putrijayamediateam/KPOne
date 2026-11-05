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
    returns: DispensaryReturnRow[];
};

export type DispensaryReturnsPanel = {
    casePublicId: string;
    branchId: number;
    canRecord: boolean;
    reasons: string[];
    conditions: string[];
    noteMax: number;
    lines: DispensaryReturnLine[];
};
