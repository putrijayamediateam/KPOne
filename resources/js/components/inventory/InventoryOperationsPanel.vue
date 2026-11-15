<script setup lang="ts">
import { router, useRemember } from '@inertiajs/vue3';
import { Boxes } from '@lucide/vue';
import {
    computed,
    nextTick,
    reactive,
    ref,
    shallowRef,
    watch,
    watchEffect,
} from 'vue';
import type { Ref } from 'vue';
import InputError from '@/components/InputError.vue';
import InventorySearchSelect from '@/components/inventory/InventorySearchSelect.vue';
import ReceivingReviews from '@/components/inventory/ReceivingReviews.vue';
import TransferDifferences from '@/components/inventory/TransferDifferences.vue';
import TransferReceiptForm from '@/components/inventory/TransferReceiptForm.vue';
import VerifiedReceivingForm from '@/components/inventory/VerifiedReceivingForm.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { StatusBadge } from '@/components/ui/status';
import {
    purchaseOrderActionAllowed,
    stockRequestActionAllowed,
} from '@/lib/inventory-operation-transitions';
import { requestJson } from '@/lib/json-client';
import { formatDate } from '@/lib/presentation';
import { trimQuantity } from '@/lib/supplier-terms';
import type { TransferReceiptLinePayload } from '@/lib/transfer-receipt';
import type {
    InventoryDocument as DocumentRow,
    InventoryOperations,
    InventoryTransferDifference,
    InventoryReceiptMemoryContext,
    InventoryReceiptRememberedState,
    InventoryReceiptRememberedVault,
} from '@/types/inventory-operations';
import {
    consumeInventoryReceiptIntent,
    emptyInventoryReceiptRememberedVault,
    firstInventoryOperationError,
    inventoryOperationErrorContext,
    inventoryReceiptRememberContext,
    inventoryReceiptDrafts,
    inventoryReceiptSignature,
    prepareInventoryReceiptSubmission,
    restoreInventoryReceiptRememberedState,
    restoreInventoryReceiptRememberedVault,
    storeInventoryReceiptRememberedState,
    validateInventoryReceiptPayload,
} from '@/types/inventory-operations';

const props = defineProps<{
    operations: InventoryOperations;
    receiptMemoryContext: InventoryReceiptMemoryContext;
    /**
     * V2 Inventory home only (page 2a): suppliers, purchase orders and receiving are shown by the V2 orders section,
     * so this panel leaves them out there. Classic passes nothing and shows everything, as before.
     */
    hideOrders?: boolean;
    /** V2 Inventory home only (page 2b): stock requests and transfers are shown by the V2 requests section. */
    hideRequests?: boolean;
}>();
const deliveryReferences = reactive<Record<string, string>>({});
const busy = ref(false);
const operationError = ref<string | null>(null);
const operationErrorContext = ref('Inventory operation');
const rmToSen = (value: string): number | null => {
    if (value === '') {
        return null;
    }

    const match = /^(\d{1,10})(?:\.(\d{1,2}))?$/.exec(value);

    if (!match) {
        return null;
    }

    return Number(
        BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0')),
    );
};
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
const supplier = reactive({ code: '', name: '' });
const blankLine = () => ({
    key: crypto.randomUUID(),
    sku: '',
    quantity: '',
    estimatedUnitCostRm: '',
});
const purchaseOrder = reactive({
    supplier: '',
    destination: '',
    lines: [blankLine()],
});
const receipt = reactive<
    Record<
        string,
        {
            batchNumber: string;
            expiryDate: string;
            quantity: string;
            costTotalRm: string;
        }
    >
>({});
const receiptRememberContext = () =>
    inventoryReceiptRememberContext(props.receiptMemoryContext);
const receiptRememberKey = 'InventoryOperations:goods-receipts:v2';
const rememberedReceiptVault = useRemember<InventoryReceiptRememberedVault>(
    emptyInventoryReceiptRememberedVault(),
    receiptRememberKey,
) as Ref<InventoryReceiptRememberedVault>;
const restoredReceiptOrders = new Set<string>();
let activeReceiptContext: string | null = null;
const currentRememberedReceiptVault = () => {
    const context = receiptRememberContext();

    return context
        ? restoreInventoryReceiptRememberedVault(
              router.restore(receiptRememberKey) ??
                  rememberedReceiptVault.value,
              context,
          )
        : emptyInventoryReceiptRememberedVault();
};
const persistRememberedReceipts = (state: InventoryReceiptRememberedState) => {
    const vault = storeInventoryReceiptRememberedState(
        currentRememberedReceiptVault(),
        state,
    );

    rememberedReceiptVault.value = vault;
    router.remember(vault, receiptRememberKey);
};
const receivableReceiptLines = (): Record<string, string[]> =>
    Object.fromEntries(
        props.operations.purchaseOrders
            .filter((row) =>
                ['approved', 'partially_received'].includes(row.status),
            )
            .map((row) => [
                row.publicId,
                row.lines.map((line) => line.publicId),
            ]),
    );
const currentRememberedReceipts = (
    context: string,
    allowedLines = receivableReceiptLines(),
) =>
    restoreInventoryReceiptRememberedState(
        currentRememberedReceiptVault().contexts[context],
        context,
        allowedLines,
    );
const purchaseOrderEdits = reactive<
    Record<
        string,
        {
            supplier: string;
            destination: string;
            quantities: Record<string, string>;
        }
    >
>({});
const stockRequest = reactive({
    source: '',
    destination: '',
    lines: [blankLine()],
});
const dispatchBatch = reactive<Record<string, string>>({});
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
type PurchaseAction =
    'submit' | 'approve' | 'receive' | 'close' | 'short_close' | 'cancel';
const canPurchase = (row: DocumentRow, action: PurchaseAction) =>
    purchaseOrderActionAllowed(
        row.status,
        action,
        props.operations.permissions,
    ) && !needsAnotherApprover(row, action);
// STK-19: whoever raised or submitted an order never approves it, or cancels it once approved.
const needsAnotherApprover = (row: DocumentRow, action: PurchaseAction) =>
    (row.raisedByYou === true &&
        (action === 'approve' ||
            (action === 'cancel' && row.status === 'approved'))) ||
    shortCloseNeedsAnother(row, action);
// STK-21: nor closes the rest of it short.
const shortCloseNeedsAnother = (row: DocumentRow, action: PurchaseAction) =>
    row.raisedByYou === true && action === 'short_close';
const shortCloseReasons = [
    { value: 'supplier_cannot_supply', label: 'Supplier cannot supply' },
    { value: 'discontinued', label: 'Item discontinued' },
    { value: 'ordered_elsewhere', label: 'Ordered elsewhere' },
    { value: 'other', label: 'Other' },
] as const;
const shortCloseReasonLabel = (value?: string | null) =>
    shortCloseReasons.find((reason) => reason.value === value)?.label ??
    'Other';
const shortClose = reactive<{
    row: DocumentRow | null;
    reason: string;
    note: string;
}>({ row: null, reason: '', note: '' });
const shortCloseReasonError = ref('');
const shortCloseNoteError = ref('');
const openShortClose = (row: DocumentRow) => {
    if (!busy.value) {
        operationError.value = null;
        shortCloseReasonError.value = '';
        shortCloseNoteError.value = '';
        Object.assign(shortClose, { row, reason: '', note: '' });
    }
};
const submitShortClose = () => {
    const row = shortClose.row;

    if (!row) {
        return;
    }

    // Pressing Close the rest with something missing says so under that field and moves focus to it.
    shortCloseReasonError.value = shortClose.reason
        ? ''
        : 'Choose why the rest of this order will not come.';
    shortCloseNoteError.value =
        shortClose.reason === 'other' && shortClose.note.trim() === ''
            ? 'Add a note to explain why.'
            : '';

    if (shortCloseReasonError.value || shortCloseNoteError.value) {
        void nextTick(() =>
            document
                .getElementById(
                    shortCloseReasonError.value
                        ? 'short-close-reason'
                        : 'short-close-note',
                )
                ?.focus(),
        );

        return;
    }

    send(
        'post',
        `/inventory/purchase-orders/${row.publicId}/short-close`,
        {
            ...version(row),
            reason: shortClose.reason,
            note: shortClose.note.trim(),
        },
        () => (shortClose.row = null),
    );
};
/** "Ordered 100 unit · Received 60 unit · Outstanding 40 unit" (STK-21). */
const lineProgress = (row: DocumentRow, line: DocumentRow['lines'][number]) => {
    const parts = [
        `Ordered ${lineQuantity(line.orderedQuantity, line.stockUnit)}`,
        `Received ${lineQuantity(line.receivedQuantity, line.stockUnit)}`,
    ];

    if (row.closeKind === 'short') {
        parts.push(
            `Not delivered ${lineQuantity(line.notDeliveredQuantity, line.stockUnit)}`,
        );
    } else if (['approved', 'partially_received'].includes(row.status)) {
        parts.push(
            `Outstanding ${lineQuantity(line.outstandingQuantity, line.stockUnit)}`,
        );
    }

    return parts.join(' · ');
};
// Sentence case on the purchase order chip; a short close reads "Closed short".
const purchaseOrderStatusLabel = (row: DocumentRow) =>
    row.closeKind === 'short'
        ? 'Closed short'
        : ({
              draft: 'Draft',
              submitted: 'Submitted',
              approved: 'Approved',
              partially_received: 'Partially received',
              fully_received: 'Fully received',
              closed: 'Closed',
              cancelled: 'Cancelled',
          }[row.status] ?? undefined);
/** "40 unit" for a quantity in the line's stock unit, without trailing zeros. */
const lineQuantity = (value: string | null | undefined, unit?: string) =>
    `${trimQuantity(value ?? '0')}${unit ? ` ${unit}` : ''}`;
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

watchEffect(() => {
    const context = receiptRememberContext();

    if (context !== activeReceiptContext) {
        for (const linePublicId of Object.keys(receipt)) {
            delete receipt[linePublicId];
        }

        for (const key of Object.keys(deliveryReferences)) {
            delete deliveryReferences[key];
        }

        restoredReceiptOrders.clear();
        activeReceiptContext = context;
    }

    if (!context) {
        operationErrorContext.value = 'Goods receipt';
        operationError.value =
            'The secure receipt session is unavailable. Reload and sign in again.';

        return;
    }

    const vault = currentRememberedReceiptVault();
    const stored = vault.contexts[context];
    const restored = restoreInventoryReceiptRememberedState(
        stored,
        context,
        receivableReceiptLines(),
    );

    if (JSON.stringify(restored) !== JSON.stringify(stored)) {
        persistRememberedReceipts(restored);
    }

    const restoredDrafts = inventoryReceiptDrafts(restored);

    for (const row of props.operations.purchaseOrders) {
        purchaseOrderEdits[row.publicId] ??= {
            supplier: row.supplierPublicId ?? '',
            destination: row.destinationPublicId ?? '',
            quantities: Object.fromEntries(
                row.lines.map((line) => [
                    line.publicId,
                    line.orderedQuantity ?? '',
                ]),
            ),
        };

        for (const line of row.lines) {
            receipt[line.publicId] ??= {
                batchNumber: '',
                expiryDate: '',
                quantity: '',
                costTotalRm: '',
            };
        }

        const restoredOrderKey = `${context}:${row.publicId}`;

        if (!restoredReceiptOrders.has(restoredOrderKey)) {
            for (const [linePublicId, values] of Object.entries(
                restoredDrafts[row.publicId] ?? {},
            )) {
                if (receipt[linePublicId]) {
                    Object.assign(receipt[linePublicId], values, {
                        costTotalRm:
                            values.costTotalSen === undefined
                                ? ''
                                : `${Math.floor(values.costTotalSen / 100)}.${String(values.costTotalSen % 100).padStart(2, '0')}`,
                    });
                }
            }

            restoredReceiptOrders.add(restoredOrderKey);
        }
    }
});

const supplierOptions = computed(() =>
    props.operations.suppliers
        .filter((item) => item.isActive)
        .map((item) => ({
            value: item.publicId,
            label: `${item.code} · ${item.name}`,
        })),
);
const locationOptions = computed(() =>
    props.operations.locations.map((item) => ({
        value: item.publicId,
        label: `${item.name} · ${item.type}`,
    })),
);
const skuOptions = computed(() =>
    props.operations.skus.map((item) => ({
        value: item.publicId,
        label: `${item.code} · ${item.name}`,
    })),
);
const batchOptions = (sku?: string) =>
    props.operations.batches
        .filter((item) => !sku || item.skuPublicId === sku)
        .map((item) => ({
            value: item.publicId,
            label: `${item.batchNumber} · ${formatDate(item.expiryDate)}`,
        }));

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
const createSupplier = () =>
    send('post', '/inventory/suppliers', supplier, () =>
        Object.assign(supplier, { code: '', name: '' }),
    );
const setSupplierActive = (publicId: string, isActive: boolean) =>
    send('patch', `/inventory/suppliers/${publicId}/status`, {
        is_active: isActive,
    });
const confirmSupplierStatus = (publicId: string, isActive: boolean) =>
    requestConfirmation({
        title: isActive ? 'Activate supplier?' : 'Deactivate supplier?',
        description: isActive
            ? 'This supplier will become available for new Purchase Orders.'
            : 'Historical orders remain unchanged, but new Purchase Orders cannot use this supplier.',
        confirmLabel: isActive ? 'Activate supplier' : 'Deactivate supplier',
        destructive: !isActive,
        action: () => setSupplierActive(publicId, isActive),
    });
const createPurchaseOrder = () =>
    send(
        'post',
        '/inventory/purchase-orders',
        {
            expected_branch_id: props.operations.expectedBranchId,
            supplier_public_id: purchaseOrder.supplier,
            destination_location_public_id: purchaseOrder.destination,
            lines: purchaseOrder.lines.map((line) => ({
                sku_public_id: line.sku,
                quantity: line.quantity,
                unit_cost_sen: rmToSen(line.estimatedUnitCostRm),
            })),
        },
        () => {
            purchaseOrder.supplier = '';
            purchaseOrder.destination = '';
            purchaseOrder.lines.splice(
                0,
                purchaseOrder.lines.length,
                blankLine(),
            );
        },
    );
const addPurchaseOrderLine = () => purchaseOrder.lines.push(blankLine());
const removePurchaseOrderLine = (index: number) => {
    if (purchaseOrder.lines.length > 1) {
        purchaseOrder.lines.splice(index, 1);
    }
};
const updatePurchaseOrder = (row: DocumentRow) => {
    const edit = purchaseOrderEdits[row.publicId];
    send('patch', `/inventory/purchase-orders/${row.publicId}`, {
        ...version(row),
        supplier_public_id: edit.supplier,
        destination_location_public_id: edit.destination,
        lines: row.lines.map((line) => ({
            sku_public_id: line.skuPublicId,
            quantity: edit.quantities[line.publicId],
        })),
    });
};
const transitionPurchaseOrder = (row: DocumentRow, action: string) =>
    send('post', `/inventory/purchase-orders/${row.publicId}/${action}`, {
        ...version(row),
        ...(action === 'cancel'
            ? { reason: 'Cancelled through Inventory Operations workspace.' }
            : {}),
    });
const confirmPurchaseOrderTransition = (row: DocumentRow, action: string) =>
    requestConfirmation({
        title: `${action === 'approve' ? 'Approve' : action === 'cancel' ? 'Cancel' : 'Close'} ${row.number}?`,
        description:
            action === 'approve'
                ? 'Approval authorizes receiving against this Purchase Order.'
                : action === 'cancel'
                  ? 'Cancellation preserves the Purchase Order history and cannot be used after stock is received.'
                  : 'Closing records the fully received Purchase Order as complete.',
        confirmLabel: `${action[0].toUpperCase()}${action.slice(1)} Purchase Order`,
        destructive: action === 'cancel',
        action: () => transitionPurchaseOrder(row, action),
    });
const receiptPayload = (row: DocumentRow) =>
    row.lines.map((line) => ({
        line_public_id: line.publicId,
        quantity: receipt[line.publicId]?.quantity ?? '',
        batch_number: receipt[line.publicId]?.batchNumber ?? null,
        expiry_date: receipt[line.publicId]?.expiryDate ?? null,
        cost_total_rm: receipt[line.publicId]?.costTotalRm ?? '',
    }));
const receivePurchaseOrder = (row: DocumentRow) => {
    const context = receiptRememberContext();

    if (!context) {
        operationErrorContext.value = 'Goods receipt';
        operationError.value =
            'The secure receipt session is unavailable. Reload and sign in again.';

        return;
    }

    const allowedLines = receivableReceiptLines();
    const intent = prepareInventoryReceiptSubmission(
        currentRememberedReceipts(context, allowedLines),
        context,
        row.publicId,
        receiptPayload(row),
        () => crypto.randomUUID(),
    );

    if (!intent.valid) {
        operationErrorContext.value = 'Goods receipt';
        operationError.value = intent.error;

        return;
    }

    const submitted = intent.entry;
    persistRememberedReceipts(intent.state);
    send(
        'post',
        `/inventory/purchase-orders/${row.publicId}/receipts`,
        {
            ...version(row),
            idempotency_key: submitted.idempotencyKey,
            delivery_reference: deliveryReferences[row.publicId] ?? '',
            receipt_session_nonce: props.receiptMemoryContext.sessionNonce,
            lines: submitted.payload,
        },
        () => {
            const completion = consumeInventoryReceiptIntent(
                currentRememberedReceipts(context, allowedLines),
                context,
                row.publicId,
                submitted.idempotencyKey,
                submitted.signature,
            );

            if (!completion.consumed) {
                return;
            }

            persistRememberedReceipts(completion.state);

            const currentPayload = validateInventoryReceiptPayload(
                receiptPayload(row),
            );

            if (
                !currentPayload.valid ||
                inventoryReceiptSignature(currentPayload.payload) !==
                    submitted.signature
            ) {
                return;
            }

            for (const line of submitted.payload) {
                Object.assign(receipt[line.line_public_id], {
                    batchNumber: '',
                    expiryDate: '',
                    quantity: '',
                    costTotalRm: '',
                });
            }
        },
    );
};
const confirmPurchaseOrderReceipt = (row: DocumentRow) =>
    requestConfirmation({
        title: `Post receipt for ${row.number}?`,
        description:
            'This posts an append-only purchase receipt and increases stock at the destination location.',
        confirmLabel: 'Post receipt',
        action: () => receivePurchaseOrder(row),
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
const decideStockRequest = (row: DocumentRow, action: 'approve' | 'reject') =>
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
</script>

<template>
    <section class="space-y-4" aria-labelledby="inventory-operations-title">
        <div>
            <h2 id="inventory-operations-title" class="text-lg font-semibold">
                Inventory Operations
            </h2>
            <p class="text-sm text-muted-foreground">
                Procurement, branch transfers and controlled stock corrections.
                Posted movements remain read-only.
            </p>
        </div>

        <div
            v-if="operationError"
            class="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            role="alert"
            aria-live="assertive"
        >
            <p class="font-medium">
                {{ operationErrorContext }} needs attention
            </p>
            <p>{{ operationError }}</p>
        </div>

        <div
            v-if="operations.permissions.manageSuppliers && !hideOrders"
            class="rounded-xl border bg-card p-4"
        >
            <h3 class="font-semibold">Supplier Management</h3>
            <form
                class="mt-3 grid gap-3 md:grid-cols-[180px_1fr_auto]"
                @submit.prevent="createSupplier"
            >
                <Input
                    v-model="supplier.code"
                    aria-label="Supplier code"
                    placeholder="Supplier code"
                    required
                    maxlength="64"
                />
                <Input
                    v-model="supplier.name"
                    aria-label="Supplier name"
                    placeholder="Supplier name"
                    required
                    maxlength="200"
                />
                <Button type="submit" :disabled="busy">Add supplier</Button>
            </form>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <div
                    v-for="item in operations.suppliers"
                    :key="item.publicId"
                    class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-lg border p-2 [&_[data-slot=status-badge]]:max-w-full [&_[data-slot=status-badge]]:wrap-anywhere [&_[data-slot=status-badge]]:whitespace-normal"
                >
                    <StatusBadge
                        :status="item.isActive ? 'active' : 'inactive'"
                        :label="`${item.code} · ${item.name}`"
                    />
                    <Button
                        size="sm"
                        variant="secondary"
                        :disabled="busy"
                        @click="
                            confirmSupplierStatus(item.publicId, !item.isActive)
                        "
                        >{{ item.isActive ? 'Deactivate' : 'Activate' }}</Button
                    >
                </div>
            </div>
        </div>

        <div
            v-if="!(hideOrders && hideRequests)"
            class="grid gap-4"
            :class="hideOrders || hideRequests ? '' : 'xl:grid-cols-2'"
        >
            <section
                v-if="!hideOrders"
                class="space-y-3 rounded-xl border bg-card p-4"
            >
                <h3 class="font-semibold">Purchase Orders & Receiving</h3>
                <form
                    v-if="operations.permissions.createPurchaseOrders"
                    class="grid gap-2 sm:grid-cols-2"
                    @submit.prevent="createPurchaseOrder"
                >
                    <InventorySearchSelect
                        v-model="purchaseOrder.supplier"
                        label="Supplier"
                        :options="supplierOptions"
                        kind="suppliers"
                        context="operations"
                        :expected-branch-id="operations.expectedBranchId"
                    />
                    <InventorySearchSelect
                        v-model="purchaseOrder.destination"
                        label="Destination"
                        :options="locationOptions"
                        kind="locations"
                        context="operations"
                        :expected-branch-id="operations.expectedBranchId"
                    />
                    <p
                        id="po-estimate-help"
                        class="text-xs text-muted-foreground sm:col-span-2"
                    >
                        Estimated cost is for one stock unit (for example one
                        tablet), not one box.
                    </p>
                    <fieldset
                        v-for="(line, index) in purchaseOrder.lines"
                        :key="line.key"
                        class="grid gap-2 rounded-lg border p-2 sm:col-span-2 sm:grid-cols-[1fr_1fr_1fr_auto]"
                    >
                        <legend class="px-1 text-xs font-medium">
                            Order line {{ index + 1 }}
                        </legend>
                        <InventorySearchSelect
                            v-model="line.sku"
                            :label="`SKU ${index + 1}`"
                            :options="skuOptions"
                            kind="skus"
                            context="operations"
                            :expected-branch-id="operations.expectedBranchId"
                        />
                        <Input
                            v-model="line.quantity"
                            :aria-label="`Ordered quantity ${index + 1}`"
                            placeholder="Quantity"
                            inputmode="decimal"
                            required
                        />
                        <Input
                            v-model="line.estimatedUnitCostRm"
                            :aria-label="`Estimated cost per stock unit RM ${index + 1}`"
                            aria-describedby="po-estimate-help"
                            placeholder="Estimated cost per stock unit (RM)"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            step="0.01"
                        />
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            :disabled="purchaseOrder.lines.length === 1"
                            @click="removePurchaseOrderLine(index)"
                            >Remove</Button
                        >
                    </fieldset>
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="busy"
                        @click="addPurchaseOrderLine"
                        >Add PO line</Button
                    >
                    <Button type="submit" :disabled="busy" class="sm:col-span-2"
                        >Create PO draft</Button
                    >
                </form>
                <article
                    v-for="row in operations.purchaseOrders"
                    :key="row.publicId"
                    class="space-y-2 rounded-lg border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-mono text-sm">{{ row.number }}</span
                        ><StatusBadge
                            :status="row.status"
                            :label="purchaseOrderStatusLabel(row)"
                        />
                    </div>
                    <p class="text-sm">
                        {{ row.supplier }} → {{ row.destination }}
                    </p>
                    <p
                        v-if="row.fromRequest"
                        class="text-sm text-muted-foreground"
                        data-testid="po-from-request"
                    >
                        From purchase request {{ row.fromRequest }}
                    </p>
                    <p
                        v-for="line in row.lines"
                        :key="line.publicId"
                        class="text-xs text-muted-foreground"
                        data-testid="po-line-progress"
                    >
                        <span class="text-foreground">{{
                            line.item ?? line.sku
                        }}</span>
                        <template v-if="line.item"> · {{ line.sku }}</template>
                        · {{ lineProgress(row, line) }}
                    </p>
                    <div
                        v-if="row.closeKind === 'short'"
                        class="text-sm"
                        data-testid="po-short-closed"
                    >
                        <p>
                            Closed short:
                            {{ shortCloseReasonLabel(row.shortCloseReason)
                            }}<template v-if="row.shortCloseNote">
                                · {{ row.shortCloseNote }}</template
                            >
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Closed short by {{ row.closedBy }} on
                            {{ row.closedOn }}
                        </p>
                    </div>
                    <form
                        v-if="
                            row.status === 'draft' &&
                            operations.permissions.createPurchaseOrders
                        "
                        class="grid gap-2 rounded-lg bg-muted/40 p-2 sm:grid-cols-2"
                        @submit.prevent="updatePurchaseOrder(row)"
                    >
                        <InventorySearchSelect
                            v-model="purchaseOrderEdits[row.publicId].supplier"
                            label="Draft supplier"
                            :options="supplierOptions"
                            kind="suppliers"
                            context="operations"
                            :expected-branch-id="operations.expectedBranchId"
                        />
                        <InventorySearchSelect
                            v-model="
                                purchaseOrderEdits[row.publicId].destination
                            "
                            label="Draft destination"
                            :options="locationOptions"
                            kind="locations"
                            context="operations"
                            :expected-branch-id="operations.expectedBranchId"
                        />
                        <label
                            v-for="line in row.lines"
                            :key="`edit-${line.publicId}`"
                            class="grid gap-1 text-xs"
                        >
                            <span>{{ line.sku }} quantity</span>
                            <Input
                                v-model="
                                    purchaseOrderEdits[row.publicId].quantities[
                                        line.publicId
                                    ]
                                "
                                :aria-label="`${line.sku} ordered quantity`"
                                inputmode="decimal"
                                required
                            />
                        </label>
                        <Button
                            type="submit"
                            size="sm"
                            variant="secondary"
                            :disabled="busy"
                            >Update draft</Button
                        >
                    </form>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="canPurchase(row, 'submit')"
                            size="sm"
                            @click="transitionPurchaseOrder(row, 'submit')"
                            >Submit</Button
                        >
                        <Button
                            v-if="canPurchase(row, 'approve')"
                            size="sm"
                            @click="
                                confirmPurchaseOrderTransition(row, 'approve')
                            "
                            >Approve</Button
                        >
                        <Button
                            v-if="canPurchase(row, 'cancel')"
                            size="sm"
                            variant="secondary"
                            @click="
                                confirmPurchaseOrderTransition(row, 'cancel')
                            "
                            >Cancel</Button
                        >
                        <a
                            v-if="
                                operations.permissions.createPurchaseOrders ||
                                operations.permissions.approvePurchaseOrders
                            "
                            :href="`/inventory/purchase-orders/${row.publicId}/print`"
                            class="inline-flex min-h-11 items-center rounded-md border px-3 text-sm font-medium lg:min-h-9"
                            :aria-label="`Print purchase order ${row.number}`"
                            >Print PO</a
                        >
                        <p
                            v-if="
                                needsAnotherApprover(row, 'approve') &&
                                purchaseOrderActionAllowed(
                                    row.status,
                                    'approve',
                                    operations.permissions,
                                )
                            "
                            class="w-full text-sm text-muted-foreground"
                        >
                            You raised or submitted this order, so a different
                            Finance Officer or Director must approve it.
                        </p>
                        <p
                            v-if="
                                needsAnotherApprover(row, 'cancel') &&
                                purchaseOrderActionAllowed(
                                    row.status,
                                    'cancel',
                                    operations.permissions,
                                )
                            "
                            class="w-full text-sm text-muted-foreground"
                        >
                            You raised or submitted this order, so a different
                            Finance Officer or Director must cancel it now that
                            it is approved.
                        </p>
                        <Button
                            v-if="canPurchase(row, 'short_close')"
                            size="sm"
                            variant="secondary"
                            class="min-h-11 lg:min-h-9"
                            @click="openShortClose(row)"
                            >Close the rest</Button
                        >
                        <p
                            v-if="
                                needsAnotherApprover(row, 'short_close') &&
                                purchaseOrderActionAllowed(
                                    row.status,
                                    'short_close',
                                    operations.permissions,
                                )
                            "
                            class="w-full text-sm text-muted-foreground"
                        >
                            You raised or submitted this order, so a different
                            Finance Officer or Director must close the rest of
                            it.
                        </p>
                        <Button
                            v-if="canPurchase(row, 'close')"
                            size="sm"
                            @click="
                                confirmPurchaseOrderTransition(row, 'close')
                            "
                            >Close</Button
                        >
                    </div>
                    <form
                        v-if="canPurchase(row, 'receive')"
                        class="space-y-2"
                        @submit.prevent="confirmPurchaseOrderReceipt(row)"
                    >
                        <label class="grid gap-1 text-sm"
                            >Delivery note or supplier invoice reference
                            (required)
                            <Input
                                v-model="deliveryReferences[row.publicId]"
                                required
                                maxlength="120"
                                class="min-h-11"
                                :aria-label="`${row.number} delivery reference`"
                            />
                        </label>
                        <fieldset
                            v-for="line in row.lines"
                            :key="line.publicId"
                            class="grid items-start gap-2 rounded-lg border p-2 sm:grid-cols-2"
                        >
                            <legend class="px-1 text-xs font-medium">
                                {{ line.sku }} · {{ line.receivedQuantity }}/{{
                                    line.orderedQuantity
                                }}
                                · Base stock unit: {{ line.stockUnit }}
                            </legend>
                            <label class="grid min-w-0 gap-1 text-xs"
                                >Batch number (required)
                                <Input
                                    v-model="receipt[line.publicId].batchNumber"
                                    :aria-label="`${line.sku} received batch number`"
                            /></label>
                            <label class="grid min-w-0 gap-1 text-xs"
                                >Expiry date (required)
                                <DateField
                                    v-model="receipt[line.publicId].expiryDate"
                                    :aria-label="`${line.sku} expiry date`"
                                    :echo="false"
                            /></label>
                            <label class="grid min-w-0 gap-1 text-xs"
                                >Quantity this receipt ({{ line.stockUnit }},
                                required)
                                <Input
                                    v-model="receipt[line.publicId].quantity"
                                    :aria-label="`${line.sku} quantity this receipt`"
                                    inputmode="decimal"
                            /></label>
                            <div class="grid min-w-0 gap-1">
                                <label class="grid min-w-0 gap-1 text-xs"
                                    >Total paid for this batch (RM, optional)
                                    <Input
                                        v-model="
                                            receipt[line.publicId].costTotalRm
                                        "
                                        :aria-label="`${line.sku} total paid for this batch RM`"
                                        :aria-describedby="`${line.publicId}-cost-help`"
                                        placeholder="0.00"
                                        type="number"
                                        inputmode="decimal"
                                        min="0"
                                        step="0.01"
                                /></label>
                                <p
                                    :id="`${line.publicId}-cost-help`"
                                    class="text-xs text-muted-foreground"
                                >
                                    From the supplier invoice. Leave blank to
                                    use the order's estimate.
                                </p>
                            </div>
                        </fieldset>
                        <Button type="submit" :disabled="busy"
                            >Post receipt</Button
                        >
                    </form>
                    <VerifiedReceivingForm
                        v-if="canPurchase(row, 'receive')"
                        :row="row"
                        :branch-id="operations.expectedBranchId"
                        :nonce="receiptMemoryContext.sessionNonce"
                    />
                </article>
                <ReceivingReviews
                    :branch-id="operations.expectedBranchId"
                    :nonce="receiptMemoryContext.sessionNonce"
                />
            </section>

            <section
                v-if="!hideRequests"
                class="space-y-3 rounded-xl border bg-card p-4"
            >
                <h3 class="font-semibold">Stock Requests & Transfers</h3>
                <form
                    v-if="operations.permissions.createRequests"
                    class="grid gap-2 sm:grid-cols-2"
                    @submit.prevent="createStockRequest"
                >
                    <InventorySearchSelect
                        v-model="stockRequest.source"
                        label="Source location"
                        :options="locationOptions"
                        kind="locations"
                        context="operations"
                        :expected-branch-id="operations.expectedBranchId"
                    />
                    <InventorySearchSelect
                        v-model="stockRequest.destination"
                        label="Destination location"
                        :options="locationOptions"
                        kind="locations"
                        context="operations"
                        :expected-branch-id="operations.expectedBranchId"
                    />
                    <fieldset
                        v-for="(line, index) in stockRequest.lines"
                        :key="line.key"
                        class="grid gap-2 rounded-lg border p-2 sm:col-span-2 sm:grid-cols-[1fr_1fr_auto]"
                    >
                        <legend class="px-1 text-xs font-medium">
                            Request line {{ index + 1 }}
                        </legend>
                        <InventorySearchSelect
                            v-model="line.sku"
                            :label="`Requested SKU ${index + 1}`"
                            :options="skuOptions"
                            kind="skus"
                            context="operations"
                            :expected-branch-id="operations.expectedBranchId"
                        />
                        <Input
                            v-model="line.quantity"
                            :aria-label="`Requested quantity ${index + 1}`"
                            placeholder="Quantity"
                            required
                        />
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            :disabled="stockRequest.lines.length === 1"
                            @click="removeStockRequestLine(index)"
                            >Remove</Button
                        >
                    </fieldset>
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="busy"
                        @click="addStockRequestLine"
                        >Add request line</Button
                    >
                    <Button type="submit" :disabled="busy" class="sm:col-span-2"
                        >Submit stock request</Button
                    >
                </form>
                <article
                    v-for="row in operations.stockRequests"
                    :key="row.publicId"
                    class="space-y-2 rounded-lg border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-mono text-sm">{{ row.number }}</span
                        ><StatusBadge :status="row.status" />
                    </div>
                    <p class="text-sm">
                        {{ row.source }} → {{ row.destination }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="canRequest(row, 'decide')"
                            size="sm"
                            @click="decideStockRequest(row, 'approve')"
                            >Approve</Button
                        >
                        <Button
                            v-if="canRequest(row, 'decide')"
                            size="sm"
                            variant="secondary"
                            @click="decideStockRequest(row, 'reject')"
                            >Reject</Button
                        >
                    </div>
                    <TransferReceiptForm
                        v-if="canRequest(row, 'receive')"
                        :row="row"
                        :busy="busy"
                        @submit="
                            (lines, differences, retryKey) =>
                                confirmStockRequestReceipt(
                                    row,
                                    lines,
                                    differences,
                                    retryKey,
                                )
                        "
                    />
                    <TransferDifferences
                        :differences="row.differences ?? []"
                        :number="row.number"
                        :busy="busy"
                        @resolve="
                            (difference, outcome, reference, retryKey) =>
                                confirmTransferDifference(
                                    row,
                                    difference,
                                    outcome,
                                    reference,
                                    retryKey,
                                )
                        "
                    />
                    <form
                        v-if="canRequest(row, 'dispatch')"
                        class="space-y-2"
                        @submit.prevent="confirmStockRequestDispatch(row)"
                    >
                        <div
                            v-for="line in row.lines"
                            :key="line.publicId"
                            class="grid items-center gap-2 sm:grid-cols-[1fr_2fr]"
                        >
                            <span class="text-xs"
                                >{{ line.sku }} ·
                                {{ line.requestedQuantity }}</span
                            >
                            <InventorySearchSelect
                                v-model="dispatchBatch[line.publicId]"
                                label="Dispatch batch"
                                :options="batchOptions(line.skuPublicId)"
                                kind="batches"
                                context="operations"
                                :expected-branch-id="
                                    operations.expectedBranchId
                                "
                                :sku="line.skuPublicId"
                            />
                        </div>
                        <Button type="submit" size="sm" :disabled="busy"
                            >Dispatch stock</Button
                        >
                    </form>
                </article>
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="space-y-3 rounded-xl border bg-card p-4">
                <h3 class="font-semibold">Stocktake</h3>
                <p class="text-sm text-muted-foreground">
                    Legacy stocktake and manual adjustment are read-only. Use
                    the governed workflow.
                </p>
                <div class="flex flex-wrap gap-2">
                    <a
                        class="inline-flex min-h-11 items-center underline"
                        href="/inventory/counts"
                        >Stock count (governed)</a
                    >
                    <a
                        class="inline-flex min-h-11 items-center underline"
                        href="/inventory/writeoffs"
                        >Write-off (governed)</a
                    >
                </div>
                <article
                    v-for="row in operations.stocktakes"
                    :key="row.publicId"
                    class="space-y-2 rounded-lg border p-3"
                >
                    <div class="flex justify-between">
                        <span class="font-mono text-xs">{{ row.number }}</span
                        ><StatusBadge :status="row.status" />
                    </div>
                    <div
                        v-for="line in row.lines"
                        :key="line.publicId"
                        class="text-xs break-words"
                    >
                        {{ line.sku }} / {{ line.batch }} · Expected
                        {{ line.expectedQuantity }} · Counted
                        {{ line.physicalQuantity ?? 'Not recorded' }} · Variance
                        {{ line.varianceQuantity ?? 'Not recorded' }}
                    </div>
                </article>
            </section>

            <section class="space-y-3 rounded-xl border bg-card p-4">
                <h3 class="font-semibold">Manual Adjustment</h3>
                <p class="text-sm text-muted-foreground">
                    Read-only legacy history. New changes require governed
                    approval.
                </p>
                <article
                    v-for="row in operations.adjustments"
                    :key="row.publicId"
                    class="space-y-1 rounded-lg border p-3 text-sm break-words"
                >
                    <p>{{ row.sku }} / {{ row.batch }} · {{ row.location }}</p>
                    <p>
                        {{ row.direction }} {{ row.quantity }} ·
                        {{ row.reasonCode }} · {{ row.postedAt }}
                    </p>
                </article>
                <p
                    v-if="operations.adjustments.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No legacy adjustments.
                </p>
            </section>

            <section class="space-y-3 rounded-xl border bg-card p-4">
                <h3 class="font-semibold">Reorder Visibility</h3>
                <form
                    v-if="operations.permissions.manageReorder"
                    class="space-y-2"
                    @submit.prevent="saveReorder"
                >
                    <InventorySearchSelect
                        v-model="reorder.location"
                        label="Location"
                        :options="locationOptions"
                        kind="locations"
                        context="operations"
                        :expected-branch-id="operations.expectedBranchId"
                    /><InventorySearchSelect
                        v-model="reorder.sku"
                        label="SKU"
                        :options="skuOptions"
                        kind="skus"
                        context="operations"
                        :expected-branch-id="operations.expectedBranchId"
                    />
                    <label class="grid gap-1 text-sm"
                        >Reorder level
                        <Input
                            v-model="reorder.level"
                            inputmode="decimal"
                            required
                        />
                    </label>
                    <label class="grid gap-1 text-sm"
                        >Maximum (optional)
                        <Input
                            v-model="reorder.max"
                            inputmode="decimal"
                            aria-describedby="reorder-max-help reorder-max-error"
                            :aria-invalid="
                                reorderErrors.max_level ? true : undefined
                            "
                        />
                    </label>
                    <p
                        id="reorder-max-help"
                        class="text-xs text-muted-foreground"
                    >
                        With a maximum, reorder suggestions fill up to it. Leave
                        blank to remove any maximum.
                    </p>
                    <InputError
                        id="reorder-max-error"
                        :message="reorderErrors.max_level"
                    />
                    <Button
                        type="submit"
                        :disabled="
                            busy || reorder.loading || reorder.loadFailed
                        "
                        >Save level</Button
                    >
                </form>
                <p class="text-xs text-muted-foreground">
                    Up to 100 low-stock entries and 100 near-expiry batches are
                    shown. Use the Stock and Batches tabs to search the full
                    inventory.
                </p>
                <p
                    v-if="operations.lowStock.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No SKU is at or below its reorder level.
                </p>
                <div
                    v-for="row in operations.lowStock"
                    :key="`${row.location}-${row.sku}`"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-2 text-sm dark:bg-amber-950/20"
                >
                    <strong>{{ row.sku }}</strong> ·
                    {{ row.availableQuantity }} available /
                    {{ row.reorderLevel }} reorder
                    <template v-if="row.maxLevel !== null">
                        · max {{ row.maxLevel }}
                    </template>
                </div>
                <h4 class="pt-2 text-sm font-semibold">Expiring in 90 days</h4>
                <p
                    v-if="operations.expiringBatches.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No available branch batch is nearing expiry.
                </p>
                <div
                    v-for="row in operations.expiringBatches"
                    :key="`${row.sku}-${row.batch}-${row.location}`"
                    class="space-y-1 rounded-lg border bg-muted/30 p-2 text-xs break-words whitespace-normal"
                >
                    {{ row.sku }} · {{ row.batch }} · {{ row.expiryDate }}
                    <span class="block break-words whitespace-normal">
                        Location: {{ row.location }} · {{ row.quantity }} in
                        stock
                    </span>
                </div>
            </section>
        </div>

        <OperationalConfirmDialog
            :open="pendingConfirmation !== null"
            :title="pendingConfirmation?.title ?? ''"
            :description="pendingConfirmation?.description ?? ''"
            :confirm-label="pendingConfirmation?.confirmLabel ?? 'Confirm'"
            :destructive="pendingConfirmation?.destructive"
            :processing="busy"
            @update:open="(open) => !open && (pendingConfirmation = null)"
            @confirm="confirmPendingAction"
        >
            <template #icon><Boxes class="size-5" /></template>
        </OperationalConfirmDialog>
        <OperationalConfirmDialog
            :open="shortClose.row !== null"
            :title="`Close the rest of ${shortClose.row?.number ?? ''}?`"
            description="Nothing more will be received on this order. Stock already received stays as it is, and no money changes."
            confirm-label="Close the rest"
            :processing="busy"
            :error="shortClose.row ? (operationError ?? '') : ''"
            @update:open="(open) => !open && (shortClose.row = null)"
            @confirm="submitShortClose"
        >
            <template #icon><Boxes class="size-5" /></template>
            <div class="space-y-3" data-testid="short-close-form">
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="line in shortClose.row?.lines ?? []"
                        :key="line.publicId"
                    >
                        <span class="font-medium">{{
                            line.item ?? line.sku
                        }}</span>
                        <span v-if="line.item" class="text-muted-foreground">
                            · {{ line.sku }}</span
                        >: not delivered
                        {{
                            lineQuantity(
                                line.outstandingQuantity,
                                line.stockUnit,
                            )
                        }}
                    </li>
                </ul>
                <fieldset
                    class="space-y-1"
                    :aria-invalid="shortCloseReasonError !== ''"
                    :aria-describedby="
                        shortCloseReasonError
                            ? 'short-close-reason-error'
                            : undefined
                    "
                >
                    <legend class="mb-1 text-sm font-medium">
                        Why will the rest not come? (required)
                    </legend>
                    <label
                        v-for="(reason, index) in shortCloseReasons"
                        :key="reason.value"
                        class="flex min-h-11 items-center gap-2 text-sm lg:min-h-9"
                    >
                        <input
                            :id="index === 0 ? 'short-close-reason' : undefined"
                            v-model="shortClose.reason"
                            type="radio"
                            name="short-close-reason"
                            :value="reason.value"
                            required
                            class="size-4"
                            @change="shortCloseReasonError = ''"
                        />
                        {{ reason.label }}
                    </label>
                    <p
                        v-if="shortCloseReasonError"
                        id="short-close-reason-error"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ shortCloseReasonError }}
                    </p>
                </fieldset>
                <label class="grid gap-1 text-sm" for="short-close-note">
                    <span
                        >Note{{
                            shortClose.reason === 'other'
                                ? ' (required)'
                                : ' (optional)'
                        }}</span
                    >
                    <Input
                        id="short-close-note"
                        v-model="shortClose.note"
                        name="note"
                        maxlength="200"
                        :required="shortClose.reason === 'other'"
                        :aria-invalid="shortCloseNoteError !== ''"
                        :aria-describedby="
                            shortCloseNoteError
                                ? 'short-close-note-error short-close-note-help'
                                : 'short-close-note-help'
                        "
                        class="h-11 lg:h-9"
                        @input="shortCloseNoteError = ''"
                    />
                    <span
                        id="short-close-note-help"
                        class="text-xs text-muted-foreground"
                        >Up to 200 characters.</span
                    >
                    <span
                        v-if="shortCloseNoteError"
                        id="short-close-note-error"
                        role="alert"
                        class="text-sm text-destructive"
                        >{{ shortCloseNoteError }}</span
                    >
                </label>
                <p class="text-sm font-medium" data-testid="short-close-final">
                    This cannot be undone. To get the rest later, raise a new
                    order.
                </p>
            </div>
        </OperationalConfirmDialog>
    </section>
</template>
