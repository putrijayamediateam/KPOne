export type VisitType = 'consultation' | 'otc';
export type VisitPriority = 'normal' | 'urgent';
export type VisitCoverage = 'self_pay' | 'panel';

export type VisitOptions = {
    branch: { id: number; code: string; name: string; timezone: string };
    idempotencyKey?: string;
    doctors: Array<{ id: number; name: string }>;
    panels: Array<{ id: number; name: string }>;
};

export type VisitReasonSelection = { publicId: string; name: string };
export type VisitReasonPresentation = {
    primary: string | null;
    additional: string[];
    legacy: string | null;
    structured: Array<{ publicId: string; label: string; position: number }>;
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
    registeredAtDate: string;
    visitReasonExcerpt: string | null;
    doctorName: string | null;
    coverageLabel: string;
    priority: VisitPriority;
    status: 'registered' | 'cancelled' | 'completed';
    queueNumber: string | null;
    queueStatus: 'waiting' | 'serving' | 'removed' | null;
    queueRemovalReason?: string | null;
    isHeld: boolean;
    holdStartedAt: string | null;
    durationMinutes: number | null;
    visitLockVersion: number;
    queueLockVersion: number | null;
    returnedFromDispensary?: boolean;
    dispensaryStatus?: 'pending' | 'dispensing';
    medicineCount?: number;
    dispensaryUrl?: string;
    billingUrl?: string | null;
    awaitingBilling?: boolean;
    completedAt?: string | null;
    can: {
        viewPatient: boolean;
        update: boolean;
        cancel: boolean;
        sendToWaiting: boolean;
        call: boolean;
        openConsultation: boolean;
        openDispensary?: boolean;
        openBilling?: boolean;
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
    branch: { code: string; name: string; timezone: string };
    visitType: VisitType;
    status: 'registered' | 'cancelled' | 'completed';
    priority: VisitPriority;
    visitReason: string | null;
    visitReasons: VisitReasonPresentation;
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
