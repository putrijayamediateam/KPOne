import { ref } from 'vue';
import { requestJson } from '@/lib/json-client';
import type { QueueRow } from '@/types/queue';

export type QueueWorkloadSection = 'held' | 'treatment' | 'checks';
export type QueueWorkloadPage = {
    branchId: number;
    scope: 'own' | 'branch';
    section: QueueWorkloadSection;
    serverNow: string;
    data: QueueRow[];
    total: number;
    currentPage: number;
    lastPage: number;
    perPage: number;
};

/** Optional private list request. Its caller must not await it in the main Queue refresh loop. */
export function useQueueWorkload() {
    const page = ref<QueueWorkloadPage | null>(null);
    const loading = ref(false);
    const unavailable = ref(false);
    let selection: string | null = null;
    let sequence = 0;
    let active: AbortController | null = null;

    const reset = () => {
        sequence += 1;
        active?.abort();
        active = null;
        selection = null;
        page.value = null;
        loading.value = false;
        unavailable.value = false;
    };
    const poll = async (
        branchId: number,
        section: QueueWorkloadSection,
        requestedPage: number,
        parentSignal: AbortSignal,
        accepts: () => boolean = () => true,
    ) => {
        const key = `${branchId}:${section}:${requestedPage}`;

        if (selection !== key) {
            reset();
            selection = key;
        }

        const current = ++sequence;
        active?.abort();
        const request = new AbortController();
        active = request;
        loading.value = true;
        const abort = () => request.abort();
        parentSignal.addEventListener('abort', abort, { once: true });

        if (parentSignal.aborted) {
            abort();
        }

        const publish = () =>
            current === sequence && !request.signal.aborted && accepts();

        try {
            const response = await requestJson<QueueWorkloadPage>(
                `/queue/workload?expected_branch_id=${branchId}&section=${section}&page=${requestedPage}`,
                { signal: request.signal },
            );

            if (publish()) {
                if (
                    response.branchId !== branchId ||
                    response.section !== section
                ) {
                    throw new Error('Queue workload response scope changed.');
                }

                page.value = response;
                unavailable.value = false;
            }
        } catch {
            if (publish()) {
                page.value = null;
                unavailable.value = true;
            }
        } finally {
            parentSignal.removeEventListener('abort', abort);

            if (active === request) {
                active = null;
                loading.value = false;
            }
        }
    };

    return { page, loading, unavailable, poll, reset };
}
