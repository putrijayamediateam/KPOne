import { ref } from 'vue';
import { requestJson } from '@/lib/json-client';

export type QueueAverageWait = {
    averageWaitMinutes: number | null;
    samples: number;
    operationalDate: string;
};

export function useQueueAverageWait() {
    const stats = ref<QueueAverageWait | null>(null);
    const unavailable = ref(false);
    let sequence = 0;
    let active: AbortController | null = null;
    let branch: number | null = null;

    const reset = () => {
        sequence += 1;
        active?.abort();
        active = null;
        stats.value = null;
        unavailable.value = false;
        branch = null;
    };
    const poll = async (
        branchId: number,
        parentSignal: AbortSignal,
        accepts: () => boolean = () => true,
    ) => {
        if (branch !== branchId) {
            reset();
            branch = branchId;
        }

        const current = ++sequence;
        active?.abort();
        const request = new AbortController();
        active = request;
        const signal = request.signal;
        const abort = () => request.abort();
        parentSignal.addEventListener('abort', abort, { once: true });

        if (parentSignal.aborted) {
            abort();
        }

        try {
            const response = await requestJson<QueueAverageWait>(
                `/queue/average-wait?expected_branch_id=${branchId}`,
                { signal },
            );

            if (current === sequence && !signal.aborted && accepts()) {
                stats.value = response;
                unavailable.value = false;
            }
        } catch {
            if (current === sequence && !signal.aborted && accepts()) {
                stats.value = null;
                unavailable.value = true;
            }
        } finally {
            parentSignal.removeEventListener('abort', abort);

            if (active === request) {
                active = null;
            }
        }
    };

    return { stats, unavailable, poll, reset };
}
