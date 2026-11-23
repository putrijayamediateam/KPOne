import type {
    CallReadiness,
    TreatmentProgress,
    TriageZoneSummary,
} from './patientBoard';
export type QueueStatus = 'waiting' | 'serving' | 'removed';

export type QueueRow = {
    callHold?: CallReadiness | null;
    /** TRI-1c: the triage zone, or null for a viewer who may not see it. */
    triage?: TriageZoneSummary | null;
    treatmentProgress?: TreatmentProgress | null;
    canWhatsapp: boolean;
    queueNumber: string;
    operationalDate: string;
    patientNumber: string;
    patientName: string;
    visitNumber: string;
    visitReasonExcerpt: string | null;
    doctorName: string | null;
    doctorEligible: boolean;
    coverageLabel: string;
    panelNote?: string | null;
    priority: 'normal' | 'urgent';
    status: QueueStatus;
    queuedAt: string;
    queuedTime: string;
    calledAt: string | null;
    isHeld: boolean;
    isHeldTooLong: boolean;
    holdStartedAt: string | null;
    heldMinutes: number;
    activeMinutes: number;
    removalReason: string | null;
    visitStatus: 'registered' | 'cancelled' | 'completed';
    returnedFromDispensary: boolean;
    /** Q-13: the doctor's own removed row whose dispensary case waits for the doctor's check. */
    dispensaryCheckWaiting?: boolean;
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
    tabCounts?: {
        all: number;
        waiting: number;
        serving: number;
        removed: number;
    };
    /** Q-15: dispensary cases waiting for this doctor's check (0 for everyone else). */
    checksWaiting?: number;
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
    /** Q-4: who "Call next" would call; null when this board cannot call. */
    callNext?: CallNextBlock | null;
};

export type CallNextCandidate = {
    visitNumber: string;
    queueNumber: string;
    patientName: string;
    patientNumber: string;
    priority: string;
    carryOver: boolean;
    operationalDate: string;
    roomName: string | null;
    /** TRI-1c: the zone, or triaged false; absent or null for a caller who may not see it. */
    triage?: TriageZoneSummary | null;
    visitLockVersion: number;
    queueLockVersion: number;
};

export type CallNextBlock = {
    mode: 'own' | 'branch';
    state:
        | 'ready'
        | 'no_doctor'
        | 'doctor_unavailable'
        | 'busy'
        | 'no_room'
        | 'none_waiting'
        | 'all_not_ready';
    reason: string | null;
    doctorUserId: number | null;
    doctorName: string | null;
    candidate: CallNextCandidate | null;
};

export type QueueSnapshot = QueuePollSnapshot & {
    branch: { id: number; code: string; name: string; timezone: string };
    doctors: Array<{ id: number; name: string }>;
};
