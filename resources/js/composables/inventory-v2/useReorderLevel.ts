import { router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import { requestJson } from '@/lib/json-client';
import type { InventoryOperations } from '@/types/inventory-operations';
import {
    firstInventoryOperationError,
    inventoryOperationErrorContext,
} from '@/types/inventory-operations';

/**
 * Inventory V2 page 2c (reorder levels): the reorder level lookup and save copied from the Classic
 * `components/inventory/InventoryOperationsPanel.vue`, which keeps its own copy and does not use this. Same routes,
 * payload keys, lock version and idempotency key, and the same refusal when someone else saved first. A test compares
 * the two copies.
 */
export function useReorderLevel(props: {
    readonly operations: InventoryOperations;
}) {
    const busy = ref(false);
    const operationError = ref<string | null>(null);
    const operationErrorContext = ref('Inventory operation');
    type RequestPayload = Parameters<typeof router.post>[1];
    const send = (
        method: 'post' | 'patch' | 'put',
        url: string,
        data: RequestPayload,
        done?: () => void,
        failed?: (errors: Record<string, string>) => void,
    ) => {
        if (busy.value) {
            return;
        }

        operationError.value = null;
        operationErrorContext.value = inventoryOperationErrorContext(url);
        router[method](url, data, {
            preserveScroll: true,
            errorBag: 'inventoryOperations',
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onError: (errors) => {
                failed?.(errors);
                operationError.value =
                    firstInventoryOperationError(errors) ??
                    'The Inventory operation could not be completed.';
            },
            onSuccess: () => {
                operationError.value = null;
                done?.();
            },
        });
    };
    const reorder = reactive({
        location: '',
        sku: '',
        level: '',
        max: '',
        // STK-30b: the saved level's version (null for a new level) and one key per save attempt.
        lockVersion: null as number | null,
        key: crypto.randomUUID(),
        loading: false,
        loadFailed: false,
    });
    type CurrentReorderLevel = {
        reorderLevel: string;
        maxLevel: string | null;
        lockVersion: number;
    } | null;
    let reorderLookup = 0;
    const loadReorderLevel = async () => {
        const lookup = ++reorderLookup;
        reorderErrors.max_level = undefined;
        reorder.lockVersion = null;
        reorder.key = crypto.randomUUID();
        // Never carry the previous pair's numbers over to a new pair; a failed lookup keeps Save off until one succeeds.
        reorder.level = '';
        reorder.max = '';
        reorder.loadFailed = false;

        if (!reorder.location || !reorder.sku) {
            return;
        }

        reorder.loading = true;

        try {
            const query = new URLSearchParams({
                expected_branch_id: String(props.operations.expectedBranchId),
                location_public_id: reorder.location,
                sku_public_id: reorder.sku,
            });
            const { level } = await requestJson<{ level: CurrentReorderLevel }>(
                `/inventory/reorder-levels/current?${query.toString()}`,
            );

            if (lookup !== reorderLookup) {
                return;
            }

            reorder.level = level?.reorderLevel ?? '';
            reorder.max = level?.maxLevel ?? '';
            reorder.lockVersion = level?.lockVersion ?? null;
        } catch {
            if (lookup === reorderLookup) {
                reorder.loadFailed = true;
                operationErrorContext.value = 'Reorder level action';
                operationError.value =
                    'The saved reorder level could not be loaded. Choose the location and SKU again.';
            }
        } finally {
            if (lookup === reorderLookup) {
                reorder.loading = false;
            }
        }
    };
    watch(() => [reorder.location, reorder.sku], loadReorderLevel);
    // STK-30: Daniel asked for the maximum's message under its own box as well as in the panel's banner.
    const reorderErrors = reactive<{ max_level?: string }>({});
    const saveReorder = () =>
        send(
            'put',
            '/inventory/reorder-levels',
            {
                expected_branch_id: props.operations.expectedBranchId,
                location_public_id: reorder.location,
                sku_public_id: reorder.sku,
                reorder_level: reorder.level,
                max_level: reorder.max,
                expected_lock_version: reorder.lockVersion,
                idempotency_key: reorder.key,
            },
            () => {
                reorderErrors.max_level = undefined;
                void loadReorderLevel();
            },
            (errors) => {
                reorderErrors.max_level = errors.max_level;

                if (errors.expected_lock_version) {
                    // Someone else saved this level: show the saved numbers again so they can be checked first.
                    void loadReorderLevel().then(() => {
                        operationErrorContext.value = 'Reorder level action';
                        operationError.value =
                            'Someone else changed this reorder level. The saved level is shown again; check it and save.';
                    });
                }
            },
        );

    return {
        busy,
        operationError,
        operationErrorContext,
        send,
        reorder,
        loadReorderLevel,
        reorderErrors,
        saveReorder,
    };
}
