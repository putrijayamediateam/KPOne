export type VisitType = 'consultation' | 'otc';
export type VisitPriority = 'normal' | 'urgent';
export type VisitCoverage = 'self_pay' | 'panel';

export type VisitOptions = {
    branch: { id: number; code: string; name: string; timezone: string };
    idempotencyKey?: string;
    doctors: Array<{ id: number; name: string }>;
    panels: Array<{ id: number; name: string }>;
};

export type PatientRegistrationSummary = {
    patientNumber: string;
    fullName: string;
    dateOfBirth: string | null;
    sex: string;
    identifier: { type: string; maskedValue: string } | null;
    maskedPhone: string | null;
};

export type VisitRow = {
    visitNumber: string;
    patientNumber: string;
    patientName: string;
    visitType: VisitType;
    registeredAt: string;
    visitReasonExcerpt: string | null;
    doctorName: string | null;
    coverageLabel: string;
    priority: VisitPriority;
    status: 'registered' | 'cancelled';
    queueNumber: string | null;
    queueStatus: 'waiting' | 'serving' | 'removed' | null;
    durationMinutes: number | null;
    visitLockVersion: number;
    queueLockVersion: number | null;
    returnedFromDispensary?: boolean;
    dispensaryStatus?: 'pending' | 'dispensing';
    medicineCount?: number;
    dispensaryUrl?: string;
    can: {
        viewPatient: boolean;
        update: boolean;
        cancel: boolean;
        sendToWaiting: boolean;
        call: boolean;
        openConsultation: boolean;
        openDispensary?: boolean;
    };
};

export type VisitDetail = {
    visitNumber: string;
    patient: {
        patientNumber: string;
        fullName: string;
        dateOfBirth: string | null;
        sex: string;
    };
    branch: { code: string; name: string };
    visitType: VisitType;
    status: 'registered' | 'cancelled';
    priority: VisitPriority;
    visitReason: string | null;
    doctor: { id: number; name: string } | null;
    coverage: {
        type: VisitCoverage;
        panelId: number | null;
        panelName: string | null;
        memberReference: string | null;
    };
    registeredAt: string;
    cancelledAt: string | null;
    cancellationReason: string | null;
    lockVersion: number;
    queue: {
        queueNumber: string;
        operationalDate: string;
        status: 'waiting' | 'serving' | 'removed';
        queuedAt: string;
        calledAt: string | null;
        lockVersion: number;
    } | null;
    can: { update: boolean; cancel: boolean; sendToWaiting: boolean };
};
