<script setup lang="ts">
import { router, useRemember } from '@inertiajs/vue3';
import { Boxes } from '@lucide/vue';
import { computed, reactive, ref, shallowRef, watchEffect } from 'vue';
import type { Ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import {
    purchaseOrderActionAllowed,
    stockRequestActionAllowed,
    stocktakeActionAllowed,
} from '@/lib/inventory-operation-transitions';
import type {
    InventoryDocument as DocumentRow,
    InventoryIntentIdentity,
    InventoryOperations,
    InventoryReceiptMemoryContext,
    InventoryReceiptRememberedState,
    InventoryReceiptRememberedVault,
} from '@/types/inventory-operations';
import {
    consumeInventoryReceiptIntent,
    emptyInventoryReceiptRememberedVault,
    firstInventoryOperationError,
    inventoryIntentIdentity,
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
}>();
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
const supplier = reactive({ code: '', name: '' });
const blankLine = () => ({ key: crypto.randomUUID(), sku: '', quantity: '' });
const purchaseOrder = reactive({
    supplier: '',
    destination: '',
    lines: [blankLine()],
});
const receipt = reactive<
    Record<
        string,
        { batchNumber: string; expiryDate: string; quantity: string }
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
const stocktakeLocation = ref('');
const physicalCounts = reactive<Record<string, string>>({});
const adjustment = reactive({
    location: '',
    sku: '',
    batch: '',
    direction: 'in',
    quantity: '',
    reason: 'correction',
    note: '',
});
const adjustmentIdentity = ref<InventoryIntentIdentity>();
const reorder = reactive({ location: '', sku: '', level: '' });
const canPurchase = (
    row: DocumentRow,
    action: 'submit' | 'approve' | 'receive' | 'close' | 'cancel',
) =>
    purchaseOrderActionAllowed(
        row.status,
        action,
        props.operations.permissions,
    );
const canRequest = (
    row: DocumentRow,
    action: 'decide' | 'dispatch' | 'receive',
) =>
    stockRequestActionAllowed(row.status, action, props.operations.permissions);

watchEffect(() => {
    const context = receiptRememberContext();

    if (context !== activeReceiptContext) {
        for (const linePublicId of Object.keys(receipt)) {
            delete receipt[linePublicId];
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
            };
        }

        const restoredOrderKey = `${context}:${row.publicId}`;

        if (!restoredReceiptOrders.has(restoredOrderKey)) {
            for (const [linePublicId, values] of Object.entries(
                restoredDrafts[row.publicId] ?? {},
            )) {
                if (receipt[linePublicId]) {
                    Object.assign(receipt[linePublicId], values);
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
            label: `${item.batchNumber} · ${item.expiryDate}`,
        }));

type RequestPayload = Parameters<typeof router.post>[1];
const send = (
    method: 'post' | 'patch' | 'put',
    url: string,
    data: RequestPayload,
    done?: () => void,
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
const receiveStockRequest = (row: DocumentRow) =>
    send('post', `/inventory/stock-requests/${row.publicId}/receive`, {
        ...version(row),
        receive_idempotency_key: crypto.randomUUID(),
    });
const confirmStockRequestReceipt = (row: DocumentRow) =>
    requestConfirmation({
        title: `Confirm receipt of ${row.number}?`,
        description:
            'This credits the dispatched batches to the destination location exactly once.',
        confirmLabel: 'Confirm receipt',
        action: () => receiveStockRequest(row),
    });
const createStocktake = () =>
    send(
        'post',
        '/inventory/stocktakes',
        {
            expected_branch_id: props.operations.expectedBranchId,
            location_public_id: stocktakeLocation.value,
        },
        () => (stocktakeLocation.value = ''),
    );
const transitionStocktake = (row: DocumentRow, action: string) =>
    send('post', `/inventory/stocktakes/${row.publicId}/${action}`, {
        ...version(row),
        ...(action === 'count'
            ? {
                  lines: row.lines.map((line) => ({
                      line_public_id: line.publicId,
                      physical_quantity: physicalCounts[line.publicId] ?? '',
                  })),
              }
            : {}),
    });
const confirmStocktakeTransition = (row: DocumentRow, action: string) =>
    requestConfirmation({
        title:
            action === 'post'
                ? `Post ${row.number} variance?`
                : `Cancel ${row.number}?`,
        description:
            action === 'post'
                ? 'This posts append-only gain or loss movements after rechecking the captured balance versions.'
                : 'Cancellation preserves the count history and creates no stock movement.',
        confirmLabel: action === 'post' ? 'Post variance' : 'Cancel stocktake',
        destructive: action === 'cancel',
        action: () => transitionStocktake(row, action),
    });
const postAdjustment = () => {
    const signature = JSON.stringify({
        location_public_id: adjustment.location,
        sku_public_id: adjustment.sku,
        batch_public_id: adjustment.batch,
        direction: adjustment.direction,
        quantity: Number(adjustment.quantity).toFixed(3),
        reason_code: adjustment.reason,
        reason_note: adjustment.note.trim() || null,
    });
    const identity = inventoryIntentIdentity(
        adjustmentIdentity.value,
        signature,
        () => crypto.randomUUID(),
    );
    adjustmentIdentity.value = identity;
    send(
        'post',
        '/inventory/adjustments',
        {
            expected_branch_id: props.operations.expectedBranchId,
            location_public_id: adjustment.location,
            sku_public_id: adjustment.sku,
            batch_public_id: adjustment.batch,
            direction: adjustment.direction,
            quantity: adjustment.quantity,
            reason_code: adjustment.reason,
            reason_note: adjustment.note || null,
            idempotency_key: identity.key,
        },
        () => {
            adjustment.quantity = '';
            adjustment.note = '';
            adjustmentIdentity.value = undefined;
        },
    );
};
const confirmAdjustment = () =>
    requestConfirmation({
        title: 'Post manual adjustment?',
        description:
            'This creates a permanent stock movement with the selected controlled reason.',
        confirmLabel: 'Post adjustment',
        destructive: adjustment.direction === 'out',
        action: postAdjustment,
    });
const saveReorder = () =>
    send('put', '/inventory/reorder-levels', {
        expected_branch_id: props.operations.expectedBranchId,
        location_public_id: reorder.location,
        sku_public_id: reorder.sku,
        reorder_level: reorder.level,
    });
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
            v-if="operations.permissions.manageSuppliers"
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
                    class="flex items-center justify-between gap-2 rounded-lg border p-2"
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

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="space-y-3 rounded-xl border bg-card p-4">
                <h3 class="font-semibold">Purchase Orders & Receiving</h3>
                <form
                    v-if="operations.permissions.createPurchaseOrders"
                    class="grid gap-2 sm:grid-cols-2"
                    @submit.prevent="createPurchaseOrder"
                >
                    <OperationalSelect
                        v-model="purchaseOrder.supplier"
                        label="Supplier"
                        :options="supplierOptions"
                    />
                    <OperationalSelect
                        v-model="purchaseOrder.destination"
                        label="Destination"
                        :options="locationOptions"
                    />
                    <fieldset
                        v-for="(line, index) in purchaseOrder.lines"
                        :key="line.key"
                        class="grid gap-2 rounded-lg border p-2 sm:col-span-2 sm:grid-cols-[1fr_1fr_auto]"
                    >
                        <legend class="px-1 text-xs font-medium">
                            Order line {{ index + 1 }}
                        </legend>
                        <OperationalSelect
                            v-model="line.sku"
                            :label="`SKU ${index + 1}`"
                            :options="skuOptions"
                        />
                        <Input
                            v-model="line.quantity"
                            :aria-label="`Ordered quantity ${index + 1}`"
                            placeholder="Quantity"
                            inputmode="decimal"
                            required
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
                        ><StatusBadge :status="row.status" />
                    </div>
                    <p class="text-sm">
                        {{ row.supplier }} → {{ row.destination }}
                    </p>
                    <p
                        v-for="line in row.lines"
                        :key="line.publicId"
                        class="text-xs text-muted-foreground"
                    >
                        {{ line.sku }} · {{ line.receivedQuantity }}/{{
                            line.orderedQuantity
                        }}
                    </p>
                    <form
                        v-if="
                            row.status === 'draft' &&
                            operations.permissions.createPurchaseOrders
                        "
                        class="grid gap-2 rounded-lg bg-muted/40 p-2 sm:grid-cols-2"
                        @submit.prevent="updatePurchaseOrder(row)"
                    >
                        <OperationalSelect
                            v-model="purchaseOrderEdits[row.publicId].supplier"
                            label="Draft supplier"
                            :options="supplierOptions"
                        />
                        <OperationalSelect
                            v-model="
                                purchaseOrderEdits[row.publicId].destination
                            "
                            label="Draft destination"
                            :options="locationOptions"
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
                        <fieldset
                            v-for="line in row.lines"
                            :key="line.publicId"
                            class="grid gap-2 rounded-lg border p-2 sm:grid-cols-4"
                        >
                            <legend class="px-1 text-xs font-medium">
                                {{ line.sku }} · {{ line.receivedQuantity }}/{{
                                    line.orderedQuantity
                                }}
                            </legend>
                            <Input
                                v-model="receipt[line.publicId].batchNumber"
                                :aria-label="`${line.sku} received batch number`"
                                placeholder="Batch number"
                            />
                            <Input
                                v-model="receipt[line.publicId].expiryDate"
                                :aria-label="`${line.sku} received batch expiry`"
                                type="date"
                            />
                            <Input
                                v-model="receipt[line.publicId].quantity"
                                :aria-label="`${line.sku} receipt quantity`"
                                placeholder="Quantity this receipt"
                                inputmode="decimal"
                            />
                        </fieldset>
                        <Button type="submit" :disabled="busy"
                            >Post receipt</Button
                        >
                    </form>
                </article>
            </section>

            <section class="space-y-3 rounded-xl border bg-card p-4">
                <h3 class="font-semibold">Stock Requests & Transfers</h3>
                <form
                    v-if="operations.permissions.createRequests"
                    class="grid gap-2 sm:grid-cols-2"
                    @submit.prevent="createStockRequest"
                >
                    <OperationalSelect
                        v-model="stockRequest.source"
                        label="Source location"
                        :options="locationOptions"
                    />
                    <OperationalSelect
                        v-model="stockRequest.destination"
                        label="Destination location"
                        :options="locationOptions"
                    />
                    <fieldset
                        v-for="(line, index) in stockRequest.lines"
                        :key="line.key"
                        class="grid gap-2 rounded-lg border p-2 sm:col-span-2 sm:grid-cols-[1fr_1fr_auto]"
                    >
                        <legend class="px-1 text-xs font-medium">
                            Request line {{ index + 1 }}
                        </legend>
                        <OperationalSelect
                            v-model="line.sku"
                            :label="`Requested SKU ${index + 1}`"
                            :options="skuOptions"
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
                        <Button
                            v-if="canRequest(row, 'receive')"
                            size="sm"
                            @click="confirmStockRequestReceipt(row)"
                            >Confirm receipt</Button
                        >
                    </div>
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
                            <OperationalSelect
                                v-model="dispatchBatch[line.publicId]"
                                label="Dispatch batch"
                                :options="batchOptions(line.skuPublicId)"
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
                <form
                    v-if="operations.permissions.stocktake"
                    class="space-y-2"
                    @submit.prevent="createStocktake"
                >
                    <OperationalSelect
                        v-model="stocktakeLocation"
                        label="Count location"
                        :options="locationOptions"
                    /><Button type="submit" :disabled="busy"
                        >Create snapshot</Button
                    >
                </form>
                <article
                    v-for="row in operations.stocktakes"
                    :key="row.publicId"
                    class="space-y-2 rounded-lg border p-3"
                >
                    <div class="flex justify-between">
                        <span class="font-mono text-xs">{{ row.number }}</span
                        ><StatusBadge :status="row.status" />
                    </div>
                    <Button
                        v-if="
                            operations.permissions.stocktake &&
                            stocktakeActionAllowed(row.status, 'start')
                        "
                        size="sm"
                        @click="transitionStocktake(row, 'start')"
                        >Start counting</Button
                    >
                    <form
                        v-if="
                            operations.permissions.stocktake &&
                            stocktakeActionAllowed(row.status, 'count')
                        "
                        class="space-y-2"
                        @submit.prevent="transitionStocktake(row, 'count')"
                    >
                        <label
                            v-for="line in row.lines"
                            :key="line.publicId"
                            class="grid grid-cols-2 items-center gap-2 text-xs"
                            ><span
                                >{{ line.sku }} / {{ line.batch }} ({{
                                    line.expectedQuantity
                                }})</span
                            ><Input
                                v-model="physicalCounts[line.publicId]"
                                aria-label="Physical quantity"
                                required
                        /></label>
                        <Button size="sm">Send to review</Button>
                    </form>
                    <Button
                        v-if="
                            operations.permissions.stocktake &&
                            stocktakeActionAllowed(row.status, 'post')
                        "
                        size="sm"
                        @click="confirmStocktakeTransition(row, 'post')"
                        >Post variance</Button
                    >
                    <Button
                        v-if="
                            operations.permissions.stocktake &&
                            stocktakeActionAllowed(row.status, 'cancel')
                        "
                        size="sm"
                        variant="secondary"
                        @click="confirmStocktakeTransition(row, 'cancel')"
                        >Cancel stocktake</Button
                    >
                </article>
            </section>

            <section class="space-y-3 rounded-xl border bg-card p-4">
                <h3 class="font-semibold">Manual Adjustment</h3>
                <form
                    v-if="operations.permissions.adjust"
                    class="space-y-2"
                    @submit.prevent="confirmAdjustment"
                >
                    <OperationalSelect
                        v-model="adjustment.location"
                        label="Location"
                        :options="locationOptions"
                    />
                    <OperationalSelect
                        v-model="adjustment.sku"
                        label="SKU"
                        :options="skuOptions"
                    />
                    <OperationalSelect
                        v-model="adjustment.batch"
                        label="Batch"
                        :options="batchOptions(adjustment.sku)"
                    />
                    <div class="grid grid-cols-2 gap-2">
                        <OperationalSelect
                            v-model="adjustment.direction"
                            label="Direction"
                            :options="[
                                { value: 'in', label: 'Adjustment in' },
                                { value: 'out', label: 'Adjustment out' },
                            ]"
                        /><Input
                            v-model="adjustment.quantity"
                            aria-label="Adjustment quantity"
                            placeholder="Quantity"
                            required
                        />
                    </div>
                    <OperationalSelect
                        v-model="adjustment.reason"
                        label="Controlled reason"
                        :options="[
                            { value: 'correction', label: 'Correction' },
                            { value: 'damage', label: 'Damage' },
                            { value: 'found_stock', label: 'Found stock' },
                            {
                                value: 'count_variance',
                                label: 'Count variance',
                            },
                            { value: 'other', label: 'Other' },
                        ]"
                    />
                    <Input
                        v-model="adjustment.note"
                        aria-label="Adjustment note"
                        placeholder="Optional note"
                        maxlength="300"
                    />
                    <Button type="submit" :disabled="busy"
                        >Post adjustment</Button
                    >
                </form>
            </section>

            <section class="space-y-3 rounded-xl border bg-card p-4">
                <h3 class="font-semibold">Reorder Visibility</h3>
                <form
                    v-if="operations.permissions.manageReorder"
                    class="space-y-2"
                    @submit.prevent="saveReorder"
                >
                    <OperationalSelect
                        v-model="reorder.location"
                        label="Location"
                        :options="locationOptions"
                    /><OperationalSelect
                        v-model="reorder.sku"
                        label="SKU"
                        :options="skuOptions"
                    /><Input
                        v-model="reorder.level"
                        aria-label="Reorder level"
                        placeholder="Reorder level"
                        required
                    /><Button type="submit" :disabled="busy">Save level</Button>
                </form>
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
    </section>
</template>
