export type PatientBoardStatusTone =
    'neutral' | 'waiting' | 'serving' | 'cancelled' | 'removed';

export type PatientBoardRow = {
    key: string;
    patientName: string;
    patientNumber: string;
    visitNumber: string;
    queueNumber: string | null;
    arrivedDate: string;
    arrivedTime: string;
    visitNotes: string | null;
    doctorName: string | null;
    coverageLabel: string;
    durationLabel: string;
    priority: 'normal' | 'urgent';
    statusLabel: string;
    statusTone: PatientBoardStatusTone;
    can: {
        viewPatient: boolean;
        update: boolean;
        cancel: boolean;
        sendToWaiting: boolean;
        call: boolean;
        openConsultation: boolean;
    };
    source: unknown;
};
