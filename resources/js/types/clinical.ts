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
        registeredAt: string;
        lockVersion: number;
    };
    queue: {
        queueNumber: string;
        operationalDate: string;
        status: 'serving';
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
    vitals: ClinicalVitals;
    diagnoses: ClinicalDiagnosis[];
    allergies: ClinicalAllergySafety | null;
    problems: ClinicalProblemList | null;
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
    branch: { name: string };
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
