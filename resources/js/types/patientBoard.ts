export type PatientBoardStatusTone =
    'neutral' | 'waiting' | 'serving' | 'held' | 'cancelled' | 'removed';

export type PatientBoardRow = {
    key: string;
    canWhatsapp: boolean;
    patientName: string;
    patientNumber: string;
    visitNumber: string;
    queueNumber: string | null;
    arrivedDate: string;
    arrivedTime: string;
    visitNotes: string | null;
    doctorName: string | null;
    coverageLabel: string;
    panelNote?: string | null;
    durationLabel: string;
    priority: 'normal' | 'urgent';
    returnedFromDispensary?: boolean;
    dispensaryUrl?: string;
    billingUrl?: string | null;
    historyUrl?: string | null;
    completedAt?: string | null;
    statusLabel: string;
    statusTone: PatientBoardStatusTone;
    can: {
        viewPatient: boolean;
        update: boolean;
        cancel: boolean;
        sendToWaiting: boolean;
        call: boolean;
        recall?: boolean;
        callTreatment?: boolean;
        openConsultation: boolean;
        openDispensary?: boolean;
        openBilling?: boolean;
        dispense?: boolean;
        callDispensary?: boolean;
    };
    source: unknown;
};
