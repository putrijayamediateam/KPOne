export type QueueStatus = 'waiting' | 'serving' | 'removed';

export type QueueRow = {
    queueNumber: string;
    operationalDate: string;
    patientNumber: string;
    patientName: string;
    visitNumber: string;
    visitReasonExcerpt: string | null;
    doctorName: string | null;
    doctorEligible: boolean;
    coverageLabel: string;
    priority: 'normal' | 'urgent';
    status: QueueStatus;
    queuedAt: string;
    queuedTime: string;
    calledAt: string | null;
    returnedFromDispensary: boolean;
    waitingMinutes: number | null;
    visitLockVersion: number;
    queueLockVersion: number;
    canCall: boolean;
    canOpenEncounter: boolean;
    can: {
        viewPatient: boolean;
        update: boolean;
        cancel: boolean;
    };
};

export type QueueSnapshot = {
    branch: { id: number; code: string; name: string; timezone: string };
    scope: 'branch' | 'own';
    serverNow: string;
    operationalDate: string;
    waiting: {
        data: QueueRow[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
    carryOver: {
        data: QueueRow[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
    serving: QueueRow[];
    removed: QueueRow[];
    doctors: Array<{ id: number; name: string }>;
};
