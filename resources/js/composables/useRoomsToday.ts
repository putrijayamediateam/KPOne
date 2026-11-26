import { ref } from 'vue';
import { requestJson } from '@/lib/json-client';

export type RoomsToday = {
    operationalDate: string;
    hasRooms: boolean;
    rows: Array<{
        doctorId: number;
        doctorName: string;
        roomName: string | null;
        roomSource: 'chosen' | 'roster' | null;
        state: 'serving' | 'busy_elsewhere' | 'free';
        servingNumber: string | null;
        waiting: number;
        notReady: number;
        held: number;
        heldCap: number;
    }>;
};

export function useRoomsToday() {
    const stats = ref<RoomsToday | null>(null);
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
        scope: 'own' | 'branch',
        parentSignal: AbortSignal,
        accepts: () => boolean = () => true,
    ) => {
        if (scope !== 'branch') {
            reset();

            return;
        }

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
            const response = await requestJson<RoomsToday>(
                `/queue/rooms-today?expected_branch_id=${branchId}`,
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
