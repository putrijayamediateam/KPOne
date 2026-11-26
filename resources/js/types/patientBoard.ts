export type CallReadiness = {
    id: number | null;
    lockVersion: number | null;
    active: boolean;
    overridden: boolean;
    reason: string | null;
    canSet: boolean;
    canClear: boolean;
    canOverride: boolean;
};

export type PatientBoardStatusTone =
    'neutral' | 'waiting' | 'serving' | 'held' | 'cancelled' | 'removed';

/** TRI-1c: the latest triage zone (null zone = readings only), or triaged false = "No triage yet". */
export type TriageZoneSummary = {
    zone: 'red' | 'yellow' | 'green' | null;
    triaged: boolean;
};

export type PatientBoardRow = {
    key: string;
    emergency?: { alertLabel: string; takenLabel: string };
    canWhatsapp: boolean;
    callHold?: CallReadiness | null;
    /** Q-13: the doctor's own row whose dispensary case waits for the doctor's check. */
    dispensaryCheckWaiting?: boolean;
    treatmentProgress?: TreatmentProgress | null;
    /** TRI-1c: only for CA, CA Supervisor and the patient's own doctor; null or absent for anyone else. */
    triage?: TriageZoneSummary | null;
    /** TRI-1e: the patient's own doctor's read-only triage view, when allowed. */
    triageViewUrl?: string | null;
    // Minutes since the open step started, or since it was marked done.
    treatmentMinutes?: number | null;
    patientName: string;
    patientNumber: string;
    visitNumber: string;
    queueNumber: string | null;
    arrivedDate: string;
    arrivedTime: string;
    visitNotes: string | null;
    doctorName: string | null;
    /** AUTO-1c: a neutral warning for a waiting consultation whose doctor is off roster, on leave, without a room, or busy while another doctor is free. Only for a viewer who may edit the visit. */
    doctorWarning?: {
        code: 'off_roster' | 'on_leave' | 'no_room' | 'another_free';
        label: string;
    } | null;
    coverageLabel: string;
    panelNote?: string | null;
    durationLabel: string;
    // Q-1: minutes since joining the queue, set only for a waiting, not-held
    // row. The board turns the duration amber or red past the fixed limits.
    waitingMinutes?: number | null;
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

// Q-2 treatment progress on a Serving consultation. Board and consultation page only; never the TV.
export type TreatmentRoomOutcomeSummary = {
    procedureLabel: string;
    outcomeLabel: string;
    reasonLabel: string | null;
};
export type TreatmentProgress = {
    outcome?: TreatmentRoomOutcomeSummary;
    id: number | null;
    code: string | null;
    label: string | null;
    state: 'started' | 'done' | null;
    startedAt: string | null;
    doneAt: string | null;
    lockVersion: number | null;
    canManage: boolean;
    /** Q-2c: other finished steps still waiting for the doctor, oldest first; absent when there are none. */
    waiting?: Array<{
        label: string;
        doneAt: string | null;
        outcome?: TreatmentRoomOutcomeSummary;
    }>;
};
