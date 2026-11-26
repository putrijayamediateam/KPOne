export type TreatmentRoomRecord = {
    id: number;
    procedureCode: string;
    procedureLabel: string;
    outcomeCode: 'done' | 'not_successful';
    outcomeLabel: string;
    reasonCode: string | null;
    reasonLabel: string | null;
    startedAt: string;
    finishedAt: string;
    recordedAt: string;
    recordedBy: string | null;
    status: 'current' | 'superseded' | 'voided';
    supersedesRecordId: number | null;
    correctionCode: 'wrong_procedure' | 'wrong_outcome' | null;
    voidReason: string | null;
    voidedAt: string | null;
    voidedBy: string | null;
};
export type TreatmentRoomRecords = {
    current: TreatmentRoomRecord[];
    history: TreatmentRoomRecord[];
};
export type TreatmentRoomStep = {
    id: number;
    code: string;
    label: string;
    state: string;
    lockVersion: number;
    startedAt: string;
    doneAt: string | null;
    hasOutcome: boolean;
    canRecordOutcome: boolean;
};
export type TreatmentRoomVisit = {
    branchId: number;
    visitStatus: string;
    queueStatus: string;
    isHeld: boolean;
    visitNumber: string;
    patientNumber: string;
    patientName: string;
    queueNumber: string;
    visitLockVersion: number;
    queueLockVersion: number;
    canRecord: boolean;
    canCallTreatment: boolean;
    steps: TreatmentRoomStep[];
};
export type TreatmentRoomOptions = {
    branch: { id: number; name: string; code: string; timezone: string };
    canRecord: boolean;
    canCallTreatment: boolean;
    procedures: { value: string; label: string }[];
    reasons: { value: string; label: string }[];
};
export type TreatmentRoomDirectory = TreatmentRoomOptions & {
    rows: TreatmentRoomVisit[];
};
