import { formatElapsedMinutes } from '@/lib/presentation';

export type QueuePresentationInput = {
    status: 'waiting' | 'serving' | 'removed';
    removalReason?: string | null;
    returnedFromDispensary?: boolean;
    visitStatus?: string | null;
};

export const queuePresentationLabel = (row: QueuePresentationInput): string => {
    if (row.returnedFromDispensary && row.status !== 'removed') {
        return 'Returned to Doctor';
    }

    if (row.status === 'waiting') {
        return 'Waiting';
    }

    if (row.status === 'serving') {
        return 'Serving Now';
    }

    if (row.visitStatus === 'cancelled' || row.removalReason === 'cancelled') {
        return 'Cancelled';
    }

    if (row.removalReason === 'sent_to_dispensary') {
        return 'Sent to Dispensary';
    }

    if (row.removalReason === 'sent_to_billing') {
        return 'Awaiting Billing';
    }

    return 'Consultation Closed';
};

const prefixedElapsed = (prefix: string, minutes: number | null): string => {
    if (minutes === null || !Number.isFinite(minutes)) {
        return '—';
    }

    const elapsed = formatElapsedMinutes(minutes);

    return elapsed === '—' ? '—' : `${prefix} ${elapsed}`;
};

export const waitingDurationLabel = (minutes: number | null): string =>
    prefixedElapsed('Waiting', minutes);
export const servingDurationLabel = (minutes: number | null): string =>
    prefixedElapsed('Serving', minutes);
export const waitedDurationLabel = (minutes: number | null): string =>
    prefixedElapsed('Waited', minutes);
export const arrivedDurationLabel = (minutes: number | null): string => {
    const value = prefixedElapsed('Arrived', minutes);

    return value === '—' ? value : `${value} ago`;
};

export const treatmentSaveButtonVariant = (
    canCompleteConsultation: boolean,
): 'primary' | 'secondary' =>
    canCompleteConsultation ? 'secondary' : 'primary';
