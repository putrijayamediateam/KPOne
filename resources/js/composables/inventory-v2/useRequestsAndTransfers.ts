import { router } from '@inertiajs/vue3';
import { reactive, ref, shallowRef } from 'vue';
import { stockRequestActionAllowed } from '@/lib/inventory-operation-transitions';
import type { TransferReceiptLinePayload } from '@/lib/transfer-receipt';
import type {
    InventoryDocument as DocumentRow,
    InventoryOperations,
    InventoryTransferDifference,
} from '@/types/inventory-operations';
import {
    firstInventoryOperationError,
    inventoryOperationErrorContext,
} from '@/types/inventory-operations';

/**
 * Inventory V2 page 2b (stock requests and transfers): the request, approval, dispatch, receipt and difference logic
 * copied from the Classic `components/inventory/InventoryOperationsPanel.vue`, which keeps its own copy and does not
 * use this. Same routes, payload keys, lock versions and retry keys. A test compares the two copies.
 */
export function useRequestsAndTransfers(props: {
    readonly operations: InventoryOperations;
}) {
    const busy = ref(false);
    const operationError = ref<string | null>(null);
    const operationErrorContext = ref('Inventory operation');
    type PendingConfirmation = {
        title: string;
        description: string;
        confirmLabel: string;
        destructive?: boolean;
        action: () => void;
    };
    const pendingConfirmation = shallowRef<PendingConfirmation | null>(null);
    const requestConfirmation = (confirmation: PendingConfirmation) => {
        if (!busy.value) {
            pendingConfirmation.value = confirmation;
        }
    };
    const confirmPendingAction = () => {
        const action = pendingConfirmation.value?.action;
        pendingConfirmation.value = null;
        action?.();
    };
    const blankLine = () => ({
        key: crypto.randomUUID(),
        sku: '',
        quantity: '',
        estimatedUnitCostRm: '',
    });
    const stockRequest = reactive({
        source: '',
        destination: '',
        lines: [blankLine()],
    });
    const dispatchBatch = reactive<Record<string, string>>({});
    const canRequest = (
        row: DocumentRow,
        action: 'decide' | 'dispatch' | 'receive',
    ) =>
        stockRequestActionAllowed(
            row.status,
            action,
            props.operations.permissions,
            {
                atRequesting: row.atRequesting,
                atSource: row.atSource,
                atDestination: row.atDestination,
            },
        );
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
    const version = (row: DocumentRow) => ({
        expected_branch_id: props.operations.expectedBranchId,
        lock_version: row.lockVersion,
    });
    const createStockRequest = () =>
        send(
            'post',
            '/inventory/stock-requests',
            {
                expected_branch_id: props.operations.expectedBranchId,
                source_location_public_id: stockRequest.source,
                destination_location_public_id: stockRequest.destination,
                lines: stockRequest.lines.map((line) => ({
                    sku_public_id: line.sku,
                    quantity: line.quantity,
                })),
            },
            () => {
                stockRequest.source = '';
                stockRequest.destination = '';
                stockRequest.lines.splice(
                    0,
                    stockRequest.lines.length,
                    blankLine(),
                );
            },
        );
    const addStockRequestLine = () => stockRequest.lines.push(blankLine());
    const removeStockRequestLine = (index: number) => {
        if (stockRequest.lines.length > 1) {
            stockRequest.lines.splice(index, 1);
        }
    };
    const decideStockRequest = (
        row: DocumentRow,
        action: 'approve' | 'reject',
    ) =>
        send('post', `/inventory/stock-requests/${row.publicId}/${action}`, {
            ...version(row),
            ...(action === 'reject'
                ? { reason: 'Rejected through Inventory Operations workspace.' }
                : {}),
        });
    const dispatchStockRequest = (row: DocumentRow) =>
        send('post', `/inventory/stock-requests/${row.publicId}/dispatch`, {
            ...version(row),
            dispatch_idempotency_key: crypto.randomUUID(),
            lines: row.lines.map((line) => ({
                line_public_id: line.publicId,
                batch_public_id: dispatchBatch[line.publicId] ?? '',
                quantity: line.requestedQuantity,
            })),
        });
    const confirmStockRequestDispatch = (row: DocumentRow) =>
        requestConfirmation({
            title: `Dispatch ${row.number}?`,
            description:
                'This debits source stock. Destination stock remains unavailable until receipt is confirmed.',
            confirmLabel: 'Dispatch stock',
            action: () => dispatchStockRequest(row),
        });
    const receiveStockRequest = (
        row: DocumentRow,
        lines: TransferReceiptLinePayload[],
        retryKey: string,
    ) =>
        send('post', `/inventory/stock-requests/${row.publicId}/receive`, {
            ...version(row),
            receive_idempotency_key: retryKey,
            lines,
        });
    const confirmStockRequestReceipt = (
        row: DocumentRow,
        lines: TransferReceiptLinePayload[],
        differences: number,
        retryKey: string,
    ) =>
        requestConfirmation({
            title: `Confirm receipt of ${row.number}?`,
            description:
                differences === 0
                    ? 'This credits the accepted quantities to the destination location exactly once.'
                    : `This credits only the accepted quantities. ${differences} difference${differences === 1 ? '' : 's'} will stay open until someone who did not receive this transfer resolves them.`,
            confirmLabel: 'Confirm receipt',
            action: () => receiveStockRequest(row, lines, retryKey),
        });
    const resolveTransferDifference = (
        row: DocumentRow,
        difference: InventoryTransferDifference,
        outcome: string,
        reference: string,
        retryKey: string,
    ) =>
        send('post', `/inventory/stock-requests/${row.publicId}/differences`, {
            ...version(row),
            retry_key: retryKey,
            difference_public_id: difference.publicId,
            outcome,
            reference,
        });
    const confirmTransferDifference = (
        row: DocumentRow,
        difference: InventoryTransferDifference,
        outcome: string,
        reference: string,
        retryKey: string,
    ) =>
        requestConfirmation({
            title: `Resolve this ${difference.kind} difference?`,
            description:
                'The resolution is permanent and recorded against your account. Original receipt evidence is kept.',
            confirmLabel: 'Resolve difference',
            action: () =>
                resolveTransferDifference(
                    row,
                    difference,
                    outcome,
                    reference,
                    retryKey,
                ),
        });

    return {
        busy,
        operationError,
        operationErrorContext,
        pendingConfirmation,
        requestConfirmation,
        confirmPendingAction,
        blankLine,
        stockRequest,
        dispatchBatch,
        canRequest,
        send,
        version,
        createStockRequest,
        addStockRequestLine,
        removeStockRequestLine,
        decideStockRequest,
        dispatchStockRequest,
        confirmStockRequestDispatch,
        receiveStockRequest,
        confirmStockRequestReceipt,
        resolveTransferDifference,
        confirmTransferDifference,
    };
}
