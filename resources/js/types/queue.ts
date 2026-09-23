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
    isHeld: boolean;
    holdStartedAt: string | null;
    heldMinutes: number;
    activeMinutes: number;
    removalReason: string | null;
    visitStatus: 'registered' | 'cancelled' | 'completed';
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

export type QueuePollSnapshot = {
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
};

export type QueueSnapshot = QueuePollSnapshot & {
    branch: { id: number; code: string; name: string; timezone: string };
    doctors: Array<{ id: number; name: string }>;
};
