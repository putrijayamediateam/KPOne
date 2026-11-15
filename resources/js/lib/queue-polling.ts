import type { QueuePollSnapshot, QueueSnapshot } from '@/types';

export type QueueFilters = {
    query: string;
    doctor_id: string;
    priority: string;
    status: string;
};

export type QueuePollingLifecycle = {
    mount: () => void;
    dispose: () => void;
    invalidate: () => number;
    currentGeneration: () => number;
    accepts: (generation: number) => boolean;
    canRun: () => boolean;
};

export const createQueuePollingLifecycle = (): QueuePollingLifecycle => {
    let disposed = true;
    let generation = 0;

    return {
        mount: () => {
            disposed = false;
        },
        dispose: () => {
            disposed = true;
            generation += 1;
        },
        invalidate: () => {
            generation += 1;

            return generation;
        },
        currentGeneration: () => generation,
        accepts: (candidate) => !disposed && candidate === generation,
        canRun: () => !disposed,
    };
};

export const emptyQueueFilters = (status = ''): QueueFilters => ({
    query: '',
    doctor_id: '',
    priority: '',
    status,
});

export const normalizeQueueFilters = (filters: QueueFilters): QueueFilters => ({
    ...filters,
    query: filters.query.trim(),
});

export const queueFilterError = (filters: QueueFilters): string => {
    const query = filters.query.trim();

    if (query !== '' && !/^\d+$/.test(query) && query.length < 3) {
        return 'Enter at least 3 characters to search Patients.';
    }

    return '';
};

export const queueVisibleError = (
    draftValidationError: string,
    liveRefreshError: string,
): string => draftValidationError || liveRefreshError;

export const applyQueueFilters = (
    draft: QueueFilters,
    applied: QueueFilters,
): string => {
    const normalized = normalizeQueueFilters(draft);
    const error = queueFilterError(normalized);

    if (error) {
        return error;
    }

    Object.assign(draft, normalized);
    Object.assign(applied, normalized);

    return '';
};

export const clearQueueFilters = (
    draft: QueueFilters,
    applied: QueueFilters,
    status = '',
): void => {
    const cleared = emptyQueueFilters(status);
    Object.assign(draft, cleared);
    Object.assign(applied, cleared);
};

export const queuePollPayload = (
    filters: QueueFilters,
    page: number,
    carryPage: number,
) => ({
    ...filters,
    page,
    carry_page: carryPage,
});

export const mergeQueuePollSnapshot = (
    current: QueueSnapshot,
    poll: QueuePollSnapshot,
): QueueSnapshot => ({
    ...current,
    ...poll,
});
