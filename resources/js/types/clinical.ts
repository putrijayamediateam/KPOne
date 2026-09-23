import type { VisitReasonPresentation } from './visit';

export type ClinicalDiagnosis = {
    diagnosisText: string;
    diagnosisCode: string | null;
    codeSystem: string | null;
    isPrimary: boolean;
};

export type ClinicalHistorySummary = {
    startedAt: string;
    branch: string;
    attendingClinician: string;
    status: 'in_progress';
    visitReason: string | null;
    viewUrl: string;
};

export type ClinicalVitals = {
    observedAt: string | null;
    systolicBp: number | null;
    diastolicBp: number | null;
    pulseBpm: number | null;
    temperatureCelsius: string | null;
    spo2Percent: string | null;
    weightKg: string | null;
    heightCm: string | null;
    bmi: number | null;
};

export type ClinicalAllergyRecord = {
    publicId: string;
    allergen: string;
    category: 'medication' | 'food' | 'environmental' | 'other' | null;
    reaction: string | null;
    severity: 'mild' | 'moderate' | 'severe' | null;
    recordedAt: string;
};

export type ClinicalAllergySafety = {
    status: 'unknown' | 'no_known_allergies' | 'has_allergies';
    profileLockVersion: number | null;
    reviewedAt: string | null;
    records: ClinicalAllergyRecord[];
    encounterReview: {
        reviewedAt: string;
        reviewedVersion: number;
        isCurrent: boolean;
    } | null;
    canUpdate: boolean;
    canReview: boolean;
};

export type ClinicalProblemRecord = {
    publicId: string;
    condition: string;
    conditionCode: string | null;
    codeSystem: string | null;
    status: 'active' | 'resolved';
    onsetDate: string | null;
    resolvedDate: string | null;
    lockVersion: number;
};

export type ClinicalProblemList = {
    active: ClinicalProblemRecord[];
    resolved: ClinicalProblemRecord[];
    canUpdate: boolean;
};

export type TreatmentPlanMedicine = {
    publicId: string;
    code: string;
    displayName: string;
    strength: string | null;
    dosageForm: string | null;
    unit: string;
    quantityOrdered: string;
    dosage: string;
    frequency: string;
    duration: string | null;
    route: string | null;
    administrationInstruction: string | null;
    indication: string | null;
    precaution: string | null;
    allergyProfileVersionValidated: number;
};

export type TreatmentPlanService = {
    publicId: string;
    code: string;
    displayName: string;
    unit: string;
    quantityOrdered: string;
    clinicalInstruction: string | null;
};

export type TreatmentPlanPage = {
    present: boolean;
    status: 'in_progress' | 'ready_for_dispensing' | null;
    lockVersion: number | null;
    medicines: TreatmentPlanMedicine[];
    withdrawnMedicines: { displayName: string; withdrawnAt: string | null }[];
    services: TreatmentPlanService[];
    withdrawnServices: { displayName: string; withdrawnAt: string | null }[];
    canSave: boolean;
    canSendToDispensary: boolean;
    canCompleteConsultation: boolean;
    checkout: {
        route: 'billing' | 'dispensary';
        lockVersion: number;
        canReopen: boolean;
    } | null;
};

export type DoctorDispensaryAttention = {
    exceptionPublicId: string;
    medicineName: string;
    strength: string | null;
    unit: string;
    quantityOrdered: string;
    proposedQuantity: string;
    reason: string;
    status: 'awaiting_acknowledgement' | 'acknowledged';
    reviewAgain: boolean;
    caseLockVersion: number;
    itemLockVersion: number;
    acknowledgeUrl: string;
};

export type ClinicalEncounterPage = {
    branch: { id: number; code: string; name: string; timezone: string };
    patient: {
        patientNumber: string;
        name: string;
        dateOfBirth: string | null;
        sex: string;
    };
    visit: {
        visitNumber: string;
        priority: 'normal' | 'urgent';
        registrationReason: string | null;
        registrationReasons: VisitReasonPresentation;
        registeredAt: string;
        lockVersion: number;
    };
    queue: {
        queueNumber: string;
        operationalDate: string;
        status: 'serving';
        queuedAt: string;
        calledAt: string | null;
        lockVersion: number;
    };
    encounter: {
        status: 'in_progress';
        clinicalNote: string | null;
        startedAt: string;
        attendingClinician: string;
        lockVersion: number;
        updatedAt: string;
    };
    hold: {
        isHeld: boolean;
        startedAt: string | null;
        heldMinutes: number;
        activeMinutes: number;
        holdIdempotencyKey: string;
        resumeIdempotencyKey: string;
        canHold: boolean;
        canResume: boolean;
    };
    vitals: ClinicalVitals;
    diagnoses: ClinicalDiagnosis[];
    allergies: ClinicalAllergySafety | null;
    problems: ClinicalProblemList | null;
    treatmentPlan: TreatmentPlanPage;
    dispensaryAttention: DoctorDispensaryAttention[];
    history: ClinicalHistorySummary[];
};

export type ClinicalHistoryDetailPage = {
    patient: {
        patientNumber: string;
        name: string;
        dateOfBirth: string | null;
        sex: string;
    };
    visit: { visitNumber: string };
    branch: { name: string; timezone: string };
    encounter: {
        status: 'in_progress';
        clinicalNote: string | null;
        startedAt: string;
        attendingClinician: string;
    };
    vitals: ClinicalVitals;
    diagnoses: ClinicalDiagnosis[];
    navigation: {
        backLabel: string;
        backUrl: string;
    };
};
