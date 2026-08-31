export type ClinicalDiagnosis = {
    diagnosisText: string;
    diagnosisCode: string | null;
    codeSystem: string | null;
    isPrimary: boolean;
};

export type ClinicalHistorySummary = {
    visitNumber: string;
    startedAt: string;
    branch: string;
    attendingClinician: string;
    diagnoses: ClinicalDiagnosis[];
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
    vitals: {
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
    diagnoses: ClinicalDiagnosis[];
    history: ClinicalHistorySummary[];
    limitations: { structuredHistory: string };
};
