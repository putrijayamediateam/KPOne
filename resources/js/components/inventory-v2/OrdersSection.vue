<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import InventoryPickerField from '@/components/inventory-v2/InventoryPickerField.vue';
import ReceiveSheet from '@/components/inventory-v2/ReceiveSheet.vue';
import ReceivingReviewsSection from '@/components/inventory-v2/ReceivingReviewsSection.vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import { useOrdersAndReceiving } from '@/composables/inventory-v2/useOrdersAndReceiving';
import { purchaseOrderActionAllowed } from '@/lib/inventory-operation-transitions';
import type {
    InventoryDocument as DocumentRow,
    InventoryOperations,
    InventoryReceiptMemoryContext,
} from '@/types/inventory-operations';

/**
 * Inventory V2 page 2a (orders and receiving), part 1: suppliers and the purchase order lifecycle on the V2 blocks.
 * Same routes, payload keys, lock versions and permission rules as the Classic panel (`useOrdersAndReceiving` is a
 * copy of its logic). Long forms open in sheets; a sheet with typed values asks before it closes (Daniel, page 2).
 */
const props = defineProps<{
    operations: InventoryOperations;
    receiptMemoryContext: InventoryReceiptMemoryContext;
    /** The branch time zone, so the receive sheet knows the branch's today for expiry dates. */
    timezone: string;
}>();

const orders = useOrdersAndReceiving(props);
const {
    busy,
    operationError,
    operationErrorContext,
    pendingConfirmation,
    confirmPendingAction,
    supplier,
    purchaseOrder,
    purchaseOrderEdits,
    canPurchase,
    needsAnotherApprover,
    shortCloseReasons,
    shortClose,
    shortCloseReasonError,
    shortCloseNoteError,
    openShortClose,
    submitShortClose,
    lineProgress,
    purchaseOrderStatusLabel,
    send,
    version,
    rmToSen,
    blankLine,
    confirmSupplierStatus,
    addPurchaseOrderLine,
    removePurchaseOrderLine,
    transitionPurchaseOrder,
    confirmPurchaseOrderTransition,
} = orders;

const page = usePage<{ errors?: Record<string, unknown> }>();
/** Field messages from the last refused save (the Classic panel's error bag). */
const fieldErrors = computed<Record<string, string>>(() => {
    const bag = (page.props.errors ?? {}).inventoryOperations;

    return bag && typeof bag === 'object'
        ? (bag as Record<string, string>)
        : {};
});
const fieldError = (key: string) => fieldErrors.value[key] ?? '';

const summary = ref<HTMLElement | null>(null);
watch(operationError, (value) => {
    if (value) {
        void nextTick(() => summary.value?.focus());
    }
});

// ---- Sheets -------------------------------------------------------------------------------------------------------
type Sheet = 'supplier' | 'purchase-order' | 'edit-draft' | 'lines' | null;
const sheet = ref<Sheet>(null);
const sheetRow = ref<DocumentRow | null>(null);

const dirty = computed(() => {
    if (sheet.value === 'supplier') {
        return supplier.code !== '' || supplier.name !== '';
    }

    if (sheet.value === 'purchase-order') {
        return (
            purchaseOrder.supplier !== '' ||
            purchaseOrder.destination !== '' ||
            purchaseOrder.lines.some(
                (line) =>
                    line.sku !== '' ||
                    line.quantity !== '' ||
                    line.estimatedUnitCostRm !== '',
            )
        );
    }

    if (sheet.value === 'edit-draft' && sheetRow.value) {
        const row = sheetRow.value;
        const edit = purchaseOrderEdits[row.publicId];

        return (
            !!edit &&
            (edit.supplier !== (row.supplierPublicId ?? '') ||
                edit.destination !== (row.destinationPublicId ?? '') ||
                row.lines.some(
                    (line) =>
                        edit.quantities[line.publicId] !==
                        (line.orderedQuantity ?? ''),
                ))
        );
    }

    return false;
});

const openSheet = (
    kind: Exclude<Sheet, null>,
    row: DocumentRow | null = null,
) => {
    if (busy.value) {
        return;
    }

    operationError.value = null;
    sheetRow.value = row;
    sheet.value = kind;
};
const closeSheet = () => {
    sheet.value = null;
    sheetRow.value = null;
};
/**
 * Esc, the X, the backdrop and Cancel: a sheet with typed values first asks "Discard your changes?" (V2Dialog `dirty`),
 * so this only runs once the person chose to discard, or when nothing was typed.
 */
const requestClose = (open: boolean) => {
    if (!open) {
        discard();
    }
};
const discard = () => {
    if (sheet.value === 'supplier') {
        Object.assign(supplier, { code: '', name: '' });
    }

    if (sheet.value === 'purchase-order') {
        purchaseOrder.supplier = '';
        purchaseOrder.destination = '';
        purchaseOrder.lines.splice(0, purchaseOrder.lines.length, blankLine());
    }

    if (sheet.value === 'edit-draft' && sheetRow.value) {
        const row = sheetRow.value;
        purchaseOrderEdits[row.publicId] = {
            supplier: row.supplierPublicId ?? '',
            destination: row.destinationPublicId ?? '',
            quantities: Object.fromEntries(
                row.lines.map((line) => [
                    line.publicId,
                    line.orderedQuantity ?? '',
                ]),
            ),
        };
    }

    closeSheet();
};

// The same requests and payloads as the Classic panel's createSupplier, createPurchaseOrder and updatePurchaseOrder;
// on success the sheet closes, on a refusal it stays open with every value.
const saveSupplier = () =>
    send('post', '/inventory/suppliers', supplier, () => {
        Object.assign(supplier, { code: '', name: '' });
        closeSheet();
    });
const savePurchaseOrder = () =>
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
            closeSheet();
        },
    );
const saveDraft = () => {
    const row = sheetRow.value;

    if (!row) {
        return;
    }

    const edit = purchaseOrderEdits[row.publicId];
    send(
        'patch',
        `/inventory/purchase-orders/${row.publicId}`,
        {
            ...version(row),
            supplier_public_id: edit.supplier,
            destination_location_public_id: edit.destination,
            lines: row.lines.map((line) => ({
                sku_public_id: line.skuPublicId,
                quantity: edit.quantities[line.publicId],
            })),
        },
        closeSheet,
    );
};

// ---- Row menus ----------------------------------------------------------------------------------------------------
const supplierMenu = (
    item: InventoryOperations['suppliers'][number],
): RowMenuGroup[] => [
    {
        label: '',
        items: [
            {
                id: item.isActive ? 'deactivate' : 'activate',
                label: item.isActive
                    ? 'Deactivate supplier'
                    : 'Activate supplier',
                allowed: !busy.value,
                tone: item.isActive ? 'danger' : 'default',
            },
        ],
    },
];
const onSupplierMenu = (item: InventoryOperations['suppliers'][number]) =>
    confirmSupplierStatus(item.publicId, !item.isActive);

const otherApproverReason =
    'Needs a different Finance Officer or Director: you raised or submitted this order';
/** Offered when the status and permissions allow it; shown disabled only when someone else must do it. */
const purchaseAction = (
    row: DocumentRow,
    action: Parameters<typeof canPurchase>[1],
) =>
    purchaseOrderActionAllowed(
        row.status,
        action,
        props.operations.permissions,
    );
const orderMenu = (row: DocumentRow): RowMenuGroup[] => {
    const groups: RowMenuGroup[] = [];
    const canPrint =
        props.operations.permissions.createPurchaseOrders ||
        props.operations.permissions.approvePurchaseOrders;
    groups.push({
        label: 'Open',
        items: [
            { id: 'lines', label: 'View lines', allowed: true },
            ...(canPrint
                ? [
                      {
                          id: 'print',
                          label: 'Print purchase order',
                          allowed: true,
                          href: `/inventory/purchase-orders/${row.publicId}/print`,
                      },
                  ]
                : []),
        ],
    });

    const move = [
        ...(row.status === 'draft' &&
        props.operations.permissions.createPurchaseOrders
            ? [{ id: 'edit', label: 'Edit draft', allowed: !busy.value }]
            : []),
        ...(purchaseAction(row, 'receive')
            ? [
                  {
                      id: 'receive',
                      label: 'Receive goods',
                      allowed: canPurchase(row, 'receive') && !busy.value,
                  },
              ]
            : []),
        ...(purchaseAction(row, 'submit')
            ? [
                  {
                      id: 'submit',
                      label: 'Submit for approval',
                      allowed: canPurchase(row, 'submit') && !busy.value,
                  },
              ]
            : []),
    ];

    if (move.length) {
        groups.push({ label: 'Move', items: move });
    }

    const check = [
        ...(purchaseAction(row, 'approve')
            ? [
                  {
                      id: 'approve',
                      label: 'Approve order',
                      allowed: canPurchase(row, 'approve') && !busy.value,
                      reason: needsAnotherApprover(row, 'approve')
                          ? otherApproverReason
                          : undefined,
                  },
              ]
            : []),
        ...(purchaseAction(row, 'close')
            ? [
                  {
                      id: 'close',
                      label: 'Close as fully received',
                      allowed: canPurchase(row, 'close') && !busy.value,
                  },
              ]
            : []),
    ];

    if (check.length) {
        groups.push({ label: 'Check', items: check });
    }

    const danger = [
        ...(purchaseAction(row, 'short_close')
            ? [
                  {
                      id: 'short-close',
                      label: 'Close the rest',
                      allowed: canPurchase(row, 'short_close') && !busy.value,
                      reason: needsAnotherApprover(row, 'short_close')
                          ? otherApproverReason
                          : undefined,
                      tone: 'danger' as const,
                  },
              ]
            : []),
        ...(purchaseAction(row, 'cancel')
            ? [
                  {
                      id: 'cancel',
                      label: 'Cancel order',
                      allowed: canPurchase(row, 'cancel') && !busy.value,
                      reason: needsAnotherApprover(row, 'cancel')
                          ? otherApproverReason
                          : undefined,
                      tone: 'danger' as const,
                  },
              ]
            : []),
    ];

    if (danger.length) {
        groups.push({ label: 'Danger', items: danger });
    }

    return groups;
};
const onOrderMenu = (row: DocumentRow, id: string) => {
    if (id === 'lines') {
        openSheet('lines', row);
    } else if (id === 'receive') {
        receiveRow.value = row;
    } else if (id === 'edit') {
        openSheet('edit-draft', row);
    } else if (id === 'submit') {
        transitionPurchaseOrder(row, 'submit');
    } else if (id === 'approve' || id === 'cancel' || id === 'close') {
        confirmPurchaseOrderTransition(row, id);
    } else if (id === 'short-close') {
        openShortClose(row);
    }
};

// Receive against an order (the verified receiving form as a V2 sheet).
const receiveRow = ref<DocumentRow | null>(null);
const receiving = computed(() =>
    receiveRow.value
        ? (props.operations.purchaseOrders.find(
              (row) => row.publicId === receiveRow.value?.publicId,
          ) ?? null)
        : null,
);

const statusTone = (row: DocumentRow) =>
    row.closeKind === 'short' || row.status === 'cancelled'
        ? 'neutral'
        : row.status === 'submitted'
          ? 'warning'
          : ['approved', 'partially_received'].includes(row.status)
            ? 'info'
            : row.status === 'draft'
              ? 'neutral'
              : 'success';
const linesSummary = (row: DocumentRow) =>
    `${row.lines.length} ${row.lines.length === 1 ? 'line' : 'lines'}`;

// Confirm dialog for approve, cancel, close and a supplier's status (the Classic confirmations, as V2Dialog).
const confirmOpen = computed({
    get: () => pendingConfirmation.value !== null,
    set: (open: boolean) => {
        if (!open) {
            pendingConfirmation.value = null;
        }
    },
});
const shortCloseOpen = computed({
    get: () => shortClose.row !== null,
    set: (open: boolean) => {
        if (!open) {
            shortClose.row = null;
        }
    },
});
const shortCloseRow = computed(() => shortClose.row);
</script>

<template>
    <div class="space-y-5" data-test="v2-orders-section">
        <div
            v-if="operationError && !sheet && !shortClose.row"
            ref="summary"
            tabindex="-1"
            role="alert"
            class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive outline-none"
        >
            <p class="font-medium">
                {{ operationErrorContext }} needs attention
            </p>
            <p>{{ operationError }}</p>
        </div>

        <section
            v-if="operations.permissions.manageSuppliers"
            class="space-y-3"
            aria-labelledby="v2-suppliers-title"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="v2-suppliers-title" class="text-[15px] font-semibold">
                    Suppliers
                </h3>
                <button
                    type="button"
                    class="inline-flex h-11 items-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    :disabled="busy"
                    @click="openSheet('supplier')"
                >
                    Add supplier
                </button>
            </div>
            <V2Table
                label="Suppliers"
                :columns="3"
                dense
                cards="xl"
                min-width="560px"
                :empty="operations.suppliers.length === 0"
                empty-message="No suppliers yet."
            >
                <template #head>
                    <tr>
                        <th scope="col">Supplier</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="item in operations.suppliers"
                        :key="item.publicId"
                    >
                        <td data-label="Supplier" data-primary>
                            <span class="block font-medium break-words">{{
                                item.name
                            }}</span>
                            <span
                                class="block font-mono text-xs text-muted-foreground"
                                >{{ item.code }}</span
                            >
                        </td>
                        <td data-label="Status">
                            <span class="whitespace-nowrap"
                                ><V2Chip
                                    :tone="
                                        item.isActive ? 'success' : 'neutral'
                                    "
                                    :label="
                                        item.isActive ? 'Active' : 'Inactive'
                                    "
                            /></span>
                        </td>
                        <td data-actions class="text-right">
                            <V2RowMenu
                                :label="`Actions for ${item.name}`"
                                :groups="supplierMenu(item)"
                                :busy="busy"
                                @select="onSupplierMenu(item)"
                            />
                        </td>
                    </tr>
                </template>
            </V2Table>
        </section>

        <section class="space-y-3" aria-labelledby="v2-purchase-orders-title">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3
                    id="v2-purchase-orders-title"
                    class="text-[15px] font-semibold"
                >
                    Purchase orders
                </h3>
                <button
                    v-if="operations.permissions.createPurchaseOrders"
                    type="button"
                    class="inline-flex h-11 items-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    :disabled="busy"
                    @click="openSheet('purchase-order')"
                >
                    New purchase order
                </button>
            </div>
            <V2Table
                label="Purchase orders"
                :columns="4"
                cards="xl"
                min-width="760px"
                :empty="operations.purchaseOrders.length === 0"
                empty-message="No purchase orders yet."
            >
                <template #head>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Lines</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="row in operations.purchaseOrders"
                        :key="row.publicId"
                    >
                        <td data-label="Order" data-primary>
                            <div class="min-w-0">
                                <span class="block font-medium break-words">{{
                                    row.supplier
                                }}</span>
                                <span
                                    class="block text-xs break-words text-muted-foreground"
                                    >{{ row.number }} · to
                                    {{ row.destination }}</span
                                >
                            </div>
                        </td>
                        <td data-label="Lines">{{ linesSummary(row) }}</td>
                        <td data-label="Status">
                            <span class="whitespace-nowrap"
                                ><V2Chip
                                    :tone="statusTone(row)"
                                    :label="
                                        purchaseOrderStatusLabel(row) ??
                                        row.status
                                    "
                            /></span>
                        </td>
                        <td data-actions class="text-right">
                            <V2RowMenu
                                :label="`Actions for ${row.number}`"
                                :groups="orderMenu(row)"
                                :busy="busy"
                                @select="(id) => onOrderMenu(row, id)"
                            />
                        </td>
                    </tr>
                </template>
            </V2Table>
        </section>

        <ReceivingReviewsSection
            :branch-id="operations.expectedBranchId"
            :nonce="receiptMemoryContext.sessionNonce"
        />
    </div>

    <!-- Add supplier (short form). -->
    <V2Dialog
        :open="sheet === 'supplier'"
        :dirty="dirty"
        title="Add supplier"
        confirm-label="Add supplier"
        cancel-label="Cancel"
        :busy="busy"
        @update:open="requestClose"
        @confirm="saveSupplier"
    >
        <p v-if="operationError" role="alert" class="text-sm text-destructive">
            {{ operationError }}
        </p>
        <label class="grid gap-1.5 text-sm font-medium"
            >Supplier code <span class="sr-only">(required)</span>
            <input
                v-model="supplier.code"
                required
                maxlength="64"
                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                :aria-invalid="!!fieldError('code')"
            />
            <span v-if="fieldError('code')" class="text-sm text-destructive">{{
                fieldError('code')
            }}</span>
        </label>
        <label class="grid gap-1.5 text-sm font-medium"
            >Supplier name <span class="sr-only">(required)</span>
            <input
                v-model="supplier.name"
                required
                maxlength="200"
                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                :aria-invalid="!!fieldError('name')"
            />
            <span v-if="fieldError('name')" class="text-sm text-destructive">{{
                fieldError('name')
            }}</span>
        </label>
    </V2Dialog>

    <!-- New purchase order (long form). -->
    <V2Dialog
        :open="sheet === 'purchase-order'"
        size="tall"
        :dirty="dirty"
        title="New purchase order"
        description="Saved as a draft. Submit it for approval when it is ready."
        confirm-label="Save draft"
        cancel-label="Cancel"
        :busy="busy"
        @update:open="requestClose"
        @confirm="savePurchaseOrder"
    >
        <p v-if="operationError" role="alert" class="text-sm text-destructive">
            {{ operationError }}
        </p>
        <InventoryPickerField
            v-model="purchaseOrder.supplier"
            kind="suppliers"
            label="Supplier"
            required
            :expected-branch-id="operations.expectedBranchId"
            :error="fieldError('supplier_public_id')"
        />
        <InventoryPickerField
            v-model="purchaseOrder.destination"
            kind="locations"
            label="Deliver to"
            required
            :expected-branch-id="operations.expectedBranchId"
            :error="fieldError('destination_location_public_id')"
        />
        <fieldset
            v-for="(line, index) in purchaseOrder.lines"
            :key="line.key"
            class="grid gap-3 rounded-[14px] border p-3"
        >
            <legend class="px-1 text-sm font-medium">
                Line {{ index + 1 }}
            </legend>
            <InventoryPickerField
                v-model="line.sku"
                kind="skus"
                label="Item"
                required
                :expected-branch-id="operations.expectedBranchId"
                :error="fieldError(`lines.${index}.sku_public_id`)"
            />
            <div class="grid items-start gap-3 sm:grid-cols-2">
                <label class="grid gap-1.5 text-sm font-medium"
                    >Quantity (stock units)
                    <span class="sr-only">(required)</span>
                    <input
                        v-model="line.quantity"
                        inputmode="decimal"
                        required
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                    <span
                        v-if="fieldError(`lines.${index}.quantity`)"
                        class="text-sm text-destructive"
                        >{{ fieldError(`lines.${index}.quantity`) }}</span
                    >
                </label>
                <label class="grid gap-1.5 text-sm font-medium"
                    >Estimated cost per stock unit, RM (optional)
                    <input
                        v-model="line.estimatedUnitCostRm"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="0.01"
                        :aria-describedby="`v2-po-estimate-${line.key}`"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                    <span
                        :id="`v2-po-estimate-${line.key}`"
                        class="text-xs font-normal text-muted-foreground"
                        >For one stock unit (for example one tablet), not one
                        box. Leave empty if not known; 0.00 means free.</span
                    >
                </label>
            </div>
            <button
                v-if="purchaseOrder.lines.length > 1"
                type="button"
                class="inline-flex h-11 items-center justify-center justify-self-start rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted"
                @click="removePurchaseOrderLine(index)"
            >
                Remove line {{ index + 1 }}
            </button>
        </fieldset>
        <button
            type="button"
            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted lg:h-9"
            :disabled="busy"
            @click="addPurchaseOrderLine"
        >
            Add a line
        </button>
    </V2Dialog>

    <!-- Edit draft. -->
    <V2Dialog
        :open="sheet === 'edit-draft' && !!sheetRow"
        size="tall"
        :dirty="dirty"
        :title="`Edit draft ${sheetRow?.number ?? ''}`"
        confirm-label="Save changes"
        cancel-label="Cancel"
        :busy="busy"
        @update:open="requestClose"
        @confirm="saveDraft"
    >
        <template v-if="sheetRow && purchaseOrderEdits[sheetRow.publicId]">
            <p
                v-if="operationError"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ operationError }}
            </p>
            <InventoryPickerField
                v-model="purchaseOrderEdits[sheetRow.publicId].supplier"
                kind="suppliers"
                label="Supplier"
                required
                :selected-label="sheetRow.supplier"
                :expected-branch-id="operations.expectedBranchId"
            />
            <InventoryPickerField
                v-model="purchaseOrderEdits[sheetRow.publicId].destination"
                kind="locations"
                label="Deliver to"
                required
                :selected-label="sheetRow.destination"
                :expected-branch-id="operations.expectedBranchId"
            />
            <label
                v-for="line in sheetRow.lines"
                :key="line.publicId"
                class="grid gap-1.5 text-sm font-medium"
                >{{ line.item ? `${line.item} (${line.sku})` : line.sku }}:
                quantity (stock units)
                <input
                    v-model="
                        purchaseOrderEdits[sheetRow.publicId].quantities[
                            line.publicId
                        ]
                    "
                    inputmode="decimal"
                    required
                    class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                />
            </label>
        </template>
    </V2Dialog>

    <!-- View lines (read only). -->
    <V2Dialog
        :open="sheet === 'lines' && !!sheetRow"
        :title="sheetRow ? `${sheetRow.number} lines` : 'Lines'"
        :description="
            sheetRow
                ? `${sheetRow.supplier} · to ${sheetRow.destination}`
                : undefined
        "
        confirm-label="Done"
        cancel-label="Close"
        @update:open="requestClose"
        @confirm="closeSheet"
    >
        <ul v-if="sheetRow" class="divide-y rounded-[14px] border">
            <li
                v-for="line in sheetRow.lines"
                :key="line.publicId"
                class="px-3 py-2.5"
            >
                <p class="font-medium break-words">
                    {{ line.item ? `${line.item} (${line.sku})` : line.sku }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ lineProgress(sheetRow, line) }}
                </p>
            </li>
        </ul>
    </V2Dialog>

    <!-- Approve, cancel, close and supplier status (a plain sentence about what happens next). -->
    <V2Dialog
        v-model:open="confirmOpen"
        :title="pendingConfirmation?.title ?? ''"
        :description="pendingConfirmation?.description"
        :confirm-label="pendingConfirmation?.confirmLabel ?? 'Confirm'"
        cancel-label="Go back"
        :tone="pendingConfirmation?.destructive ? 'danger' : 'default'"
        :busy="busy"
        @confirm="confirmPendingAction"
    />

    <!-- Close the rest (STK-21), with the reason required as in Classic. -->
    <V2Dialog
        v-model:open="shortCloseOpen"
        :title="
            shortCloseRow ? `Close the rest of ${shortCloseRow.number}?` : ''
        "
        description="The rest of this order will not be delivered. Stock and money do not change; this cannot be undone."
        confirm-label="Close the rest"
        cancel-label="Go back"
        tone="danger"
        :busy="busy"
        @confirm="submitShortClose"
    >
        <p v-if="operationError" role="alert" class="text-sm text-destructive">
            {{ operationError }}
        </p>
        <div id="short-close-reason" tabindex="-1" class="outline-none">
            <V2OptionGroup
                v-model="shortClose.reason"
                label="Why will the rest not come?"
                required
                :options="shortCloseReasons.map((reason) => ({ ...reason }))"
                :error="shortCloseReasonError || undefined"
            />
        </div>
        <label class="grid gap-1.5 text-sm font-medium"
            >Note{{ shortClose.reason === 'other' ? '' : ' (optional)' }}
            <textarea
                id="short-close-note"
                v-model="shortClose.note"
                rows="3"
                maxlength="500"
                class="w-full min-w-0 rounded-[10px] border border-input bg-card px-3 py-2 text-base md:text-[15px]"
                :aria-invalid="!!shortCloseNoteError"
            />
            <span v-if="shortCloseNoteError" class="text-sm text-destructive">{{
                shortCloseNoteError
            }}</span>
        </label>
    </V2Dialog>

    <ReceiveSheet
        v-if="receiving"
        :key="`${receiving.publicId}-${receiving.lockVersion}`"
        :row="receiving"
        :branch-id="operations.expectedBranchId"
        :nonce="receiptMemoryContext.sessionNonce"
        :timezone="timezone"
        :open="true"
        @close="receiveRow = null"
    />
</template>
