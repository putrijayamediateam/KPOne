import { router, useRemember } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    reactive,
    ref,
    shallowRef,
    watchEffect,
} from 'vue';
import type { Ref } from 'vue';
import { purchaseOrderActionAllowed } from '@/lib/inventory-operation-transitions';
import { formatDate } from '@/lib/presentation';
import { trimQuantity } from '@/lib/supplier-terms';
import type {
    InventoryDocument as DocumentRow,
    InventoryOperations,
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

/**
 * Inventory V2 page 2a (orders and receiving): the supplier, purchase order and goods receipt logic copied from the
 * Classic `components/inventory/InventoryOperationsPanel.vue`, which keeps its own copy and does not use this. Same
 * routes, payload keys, lock versions, idempotency keys and remembered receipt drafts. A test compares the two copies.
 */
export function useOrdersAndReceiving(props: {
    readonly operations: InventoryOperations;
    readonly receiptMemoryContext: InventoryReceiptMemoryContext;
}) {
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
    const persistRememberedReceipts = (
        state: InventoryReceiptRememberedState,
    ) => {
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
    const lineProgress = (
        row: DocumentRow,
        line: DocumentRow['lines'][number],
    ) => {
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
            confirmLabel: isActive
                ? 'Activate supplier'
                : 'Deactivate supplier',
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
                ? {
                      reason: 'Cancelled through Inventory Operations workspace.',
                  }
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

    return {
        deliveryReferences,
        busy,
        operationError,
        operationErrorContext,
        rmToSen,
        pendingConfirmation,
        requestConfirmation,
        confirmPendingAction,
        supplier,
        blankLine,
        purchaseOrder,
        receipt,
        receiptRememberContext,
        receiptRememberKey,
        rememberedReceiptVault,
        restoredReceiptOrders,
        currentRememberedReceiptVault,
        persistRememberedReceipts,
        receivableReceiptLines,
        currentRememberedReceipts,
        purchaseOrderEdits,
        canPurchase,
        needsAnotherApprover,
        shortCloseNeedsAnother,
        shortCloseReasons,
        shortCloseReasonLabel,
        shortClose,
        shortCloseReasonError,
        shortCloseNoteError,
        openShortClose,
        submitShortClose,
        lineProgress,
        purchaseOrderStatusLabel,
        lineQuantity,
        supplierOptions,
        locationOptions,
        skuOptions,
        batchOptions,
        send,
        version,
        createSupplier,
        setSupplierActive,
        confirmSupplierStatus,
        createPurchaseOrder,
        addPurchaseOrderLine,
        removePurchaseOrderLine,
        updatePurchaseOrder,
        transitionPurchaseOrder,
        confirmPurchaseOrderTransition,
        receiptPayload,
        receivePurchaseOrder,
        confirmPurchaseOrderReceipt,
    };
}
