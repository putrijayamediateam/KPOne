import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { RowAction } from '@/lib/registration-v2';
import type { VisitRow } from '@/types';

/**
 * Version 2 Registration row actions: the same mutations as the Classic board (`pages/Registration/Index.vue`),
 * with the same routes, payload keys, lock versions and `from: 'registration'`. Each runs through the same
 * `runMutation`: Inertia post or patch with `preserveScroll` and `preserveState`, the first error shown under the
 * filters, and a board refresh only after success.
 */
export type RowActionDeps = {
    branchId: number;
    onError: (message: string) => void;
    onSucceeded: () => void;
    openCancel: (visit: VisitRow) => void;
};

export const mutationRequest = (
    action: Exclude<RowAction, 'cancel'>,
    visit: VisitRow,
    branchId: number,
): {
    method: 'post' | 'patch';
    url: string;
    data: Record<string, number | string>;
} => {
    const base = `/visits/${encodeURIComponent(visit.visitNumber)}`;

    switch (action) {
        case 'sendToWaiting':
            return {
                method: 'post',
                url: `${base}/queue`,
                data: {
                    expected_branch_id: branchId,
                    visit_lock_version: visit.visitLockVersion,
                    from: 'registration',
                },
            };
        case 'call':
            return {
                method: 'patch',
                url: `${base}/queue/call`,
                data: {
                    expected_branch_id: branchId,
                    visit_lock_version: visit.visitLockVersion,
                    queue_lock_version: visit.queueLockVersion!,
                    from: 'registration',
                },
            };
        case 'openConsultation':
            return {
                method: 'post',
                url: `${base}/encounter`,
                data: {
                    expected_branch_id: branchId,
                    visit_lock_version: visit.visitLockVersion,
                    queue_lock_version: visit.queueLockVersion!,
                },
            };
        case 'dispense':
            return {
                method: 'post',
                url: `${base}/dispense`,
                data: { expected_branch_id: branchId },
            };
        case 'callDispensary':
            return {
                method: 'post',
                url: `${base}/otc-queue/call`,
                data: { expected_branch_id: branchId },
            };
    }
};

export function useRegistrationRowActions(deps: RowActionDeps) {
    const busyKey = ref<string | null>(null);

    const run = (visit: VisitRow, action: RowAction) => {
        if (action === 'cancel') {
            deps.openCancel(visit);

            return;
        }

        const request = mutationRequest(action, visit, deps.branchId);
        let succeeded = false;

        busyKey.value = visit.visitNumber;
        router[request.method](request.url, request.data, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                succeeded = true;
            },
            onError: (errors) => {
                deps.onError(
                    Object.values(errors)[0] ??
                        'The action could not be completed.',
                );
            },
            onFinish: () => {
                busyKey.value = null;

                if (succeeded) {
                    deps.onSucceeded();
                }
            },
        });
    };

    return { busyKey, run };
}
