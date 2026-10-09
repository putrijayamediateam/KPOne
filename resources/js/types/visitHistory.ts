import type { BillingState } from '@/types/billing';

export type VisitHistoryChangeState =
    'unchanged' | 'edited' | 'added' | 'removed';

// The ordered side is what the doctor sent (all null for a line the CA added);
// the dispensed side is the CA's final line.
export type VisitHistoryMedicine = {
    name: string;
    strength: string | null;
    dosageForm: string | null;
    unit: string;
    quantityOrdered: string | null;
    dosage: string | null;
    frequency: string | null;
    duration: string | null;
    route: string | null;
    instruction: string | null;
    indication: string | null;
    precaution: string | null;
    source: 'doctor' | 'ca';
    changeState: VisitHistoryChangeState;
    dispensedQuantity: string | null;
    dispensedStatus: string | null;
    dispensed: {
        dosage: string | null;
        frequency: string | null;
        duration: string | null;
        route: string | null;
        instruction: string | null;
        precaution: string | null;
    } | null;
};

export type VisitHistoryService = {
    name: string;
    unit: string;
    quantityOrdered: string | null;
    instruction: string | null;
    source: 'doctor' | 'ca';
    changeState: VisitHistoryChangeState;
    disposition: string | null;
    quantityPerformed: string | null;
    doctorQuantityPerformed: string | null;
    finalInstruction: string | null;
    performedAt: string | null;
};

export type VisitHistoryConsultation = {
    status: string;
    startedAt: string;
    doctor: string | null;
    note: string | null;
    vitals: {
        systolicBp: number | null;
        diastolicBp: number | null;
        pulseBpm: number | null;
        temperatureCelsius: string | number | null;
        spo2Percent: number | null;
        weightKg: string | number | null;
        heightCm: string | number | null;
        bmi: number | null;
    };
    diagnoses: Array<{ text: string; code: string | null; isPrimary: boolean }>;
};

export type VisitHistoryFinancial = {
    invoiceNumber: string;
    status: string;
    currency: string;
    state: BillingState;
    lines: Array<{
        type: string;
        name: string;
        unit: string | null;
        quantity: string;
        unitPriceSen: number;
        totalSen: number;
    }>;
    payments: Array<{
        receiptNumber: string;
        amountSen: number;
        method: string;
        status: string;
        printUrl: string | null;
    }>;
    printUrl: string | null;
};

export type VisitHistoryPage = {
    patient: {
        name: string;
        patientNumber: string;
        age: string | null;
        sex: string | null;
        profileUrl: string | null;
    };
    visit: {
        visitNumber: string;
        type: string;
        branch: string;
        doctor: string | null;
        coverage: string;
        reason: string | null;
        registeredAt: string;
        completedAt: string | null;
    };
    consultation: VisitHistoryConsultation | null;
    medicines: VisitHistoryMedicine[];
    services: VisitHistoryService[];
    financial: VisitHistoryFinancial | null;
    canSeeFinancial: boolean;
    links: {
        registration: string;
        billing: string | null;
        consultation: string | null;
    };
};
