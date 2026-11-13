<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import InventoryPickerField from '@/components/inventory-v2/InventoryPickerField.vue';
import TransferDifferencesSheet from '@/components/inventory-v2/TransferDifferencesSheet.vue';
import TransferReceiveSheet from '@/components/inventory-v2/TransferReceiveSheet.vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import { useRequestsAndTransfers } from '@/composables/inventory-v2/useRequestsAndTransfers';
import { stockRequestActionAllowed } from '@/lib/inventory-operation-transitions';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import type { TransferReceiptLinePayload } from '@/lib/transfer-receipt';
import type {
    InventoryDocument as DocumentRow,
    InventoryOperations,
    InventoryTransferDifference,
} from '@/types/inventory-operations';

/**
 * Inventory V2 page 2b (stock requests and transfers) on the V2 blocks: request stock from another location, approve
 * or reject, dispatch with a batch per line, receive with short, damaged and extra quantities, and resolve the
 * differences. Same routes, payload keys, lock versions, retry keys and permission rules as the Classic panel
 * (`useRequestsAndTransfers` is a copy of its logic). Long forms open in tall sheets that ask before they close.
 */
const props = defineProps<{ operations: InventoryOperations }>();

const {
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
    addStockRequestLine,
    removeStockRequestLine,
    decideStockRequest,
} = useRequestsAndTransfers(props);

const page = usePage<{ errors?: Record<string, unknown> }>();
/** Field messages from the last refused save (the Classic panel's error bag). */
const fieldError = (key: string) => {
    const bag = (page.props.errors ?? {}).inventoryOperations;

    return bag && typeof bag === 'object'
        ? ((bag as Record<string, string>)[key] ?? '')
        : '';
};

const summary = ref<HTMLElement | null>(null);
watch(operationError, (value) => {
    if (value && !sheet.value) {
        void nextTick(() => summary.value?.focus());
    }
});

// ---- Sheets -------------------------------------------------------------------------------------------------------
type Sheet = 'request' | 'lines' | 'dispatch' | 'receive' | 'differences';
const sheet = ref<Sheet | null>(null);
const sheetId = ref<string | null>(null);
/** The row as the page has it now, so a sheet left open after a refresh shows the latest version. */
const sheetRow = computed(
    () =>
        props.operations.stockRequests.find(
            (row) => row.publicId === sheetId.value,
        ) ?? null,
);

const openSheet = (kind: Sheet, row: DocumentRow | null = null) => {
    if (busy.value) {
        return;
    }

    operationError.value = null;
    sheetId.value = row?.publicId ?? null;
    sheet.value = kind;
};
const closeSheet = () => {
    sheet.value = null;
    sheetId.value = null;
};

const dirty = computed(() => {
    if (sheet.value === 'request') {
        return (
            stockRequest.source !== '' ||
            stockRequest.destination !== '' ||
            stockRequest.lines.some(
                (line) => line.sku !== '' || line.quantity !== '',
            )
        );
    }

    if (sheet.value === 'dispatch' && sheetRow.value) {
        return sheetRow.value.lines.some(
            (line) => (dispatchBatch[line.publicId] ?? '') !== '',
        );
    }

    return false;
});
const resetRequest = () => {
    stockRequest.source = '';
    stockRequest.destination = '';
    stockRequest.lines.splice(0, stockRequest.lines.length, blankLine());
};
/** Esc, the X, the backdrop and Cancel: this runs once the person chose to discard, or when nothing was typed. */
const requestClose = (open: boolean) => {
    if (open) {
        return;
    }

    if (sheet.value === 'request') {
        resetRequest();
    }

    if (sheet.value === 'dispatch' && sheetRow.value) {
        for (const line of sheetRow.value.lines) {
            delete dispatchBatch[line.publicId];
        }
    }

    closeSheet();
};

// ---- Saves: the Classic requests, closing the sheet only on success -----------------------------------------------
const saveRequest = () =>
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
            resetRequest();
            closeSheet();
        },
    );
/**
 * V2 only: the server answers a request whose two locations are the same with a bare "not found" page, so the sheet
 * says it in words first and sends nothing. Everything else goes to the server as in Classic.
 */
const sameLocation = ref(false);
const sendRequest = () => {
    sameLocation.value =
        stockRequest.source !== '' &&
        stockRequest.source === stockRequest.destination;

    if (!sameLocation.value) {
        saveRequest();
    }
};
watch(
    () => [stockRequest.source, stockRequest.destination],
    () => (sameLocation.value = false),
);
const saveDispatch = (row: DocumentRow) =>
    send(
        'post',
        `/inventory/stock-requests/${row.publicId}/dispatch`,
        {
            ...version(row),
            dispatch_idempotency_key: crypto.randomUUID(),
            lines: row.lines.map((line) => ({
                line_public_id: line.publicId,
                batch_public_id: dispatchBatch[line.publicId] ?? '',
                quantity: line.requestedQuantity,
            })),
        },
        closeSheet,
    );
const saveReceipt = (
    row: DocumentRow,
    lines: TransferReceiptLinePayload[],
    retryKey: string,
) =>
    send(
        'post',
        `/inventory/stock-requests/${row.publicId}/receive`,
        {
            ...version(row),
            receive_idempotency_key: retryKey,
            lines,
        },
        closeSheet,
    );
// A difference sheet stays open so the next difference can be resolved; the page refresh shows it as resolved.
const saveResolution = (
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

// ---- Table and row menu --------------------------------------------------------------------------------------------
const STATUS: Record<
    string,
    { label: string; tone: 'neutral' | 'warning' | 'info' | 'success' }
> = {
    requested: { label: 'Waiting for approval', tone: 'warning' },
    approved: { label: 'Approved, not sent', tone: 'info' },
    dispatched: { label: 'On the way', tone: 'info' },
    received: { label: 'Received', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'neutral' },
};
const status = (row: DocumentRow) =>
    STATUS[row.status] ?? { label: row.status, tone: 'neutral' as const };
const openDifferences = (row: DocumentRow) =>
    (row.differences ?? []).filter((difference) => difference.status === 'open')
        .length;
const linesSummary = (row: DocumentRow) =>
    `${row.lines.length} ${row.lines.length === 1 ? 'line' : 'lines'}`;
/** The action fits the status and the person's permission, but this branch is the wrong end of the transfer. */
const wrongBranch = (
    row: DocumentRow,
    action: 'decide' | 'dispatch' | 'receive',
) =>
    stockRequestActionAllowed(
        row.status,
        action,
        props.operations.permissions,
    ) && !canRequest(row, action);
const SWITCH: Record<'decide' | 'dispatch' | 'receive', string> = {
    decide: 'Only the sending branch can approve or reject this request. Switch branch to act.',
    dispatch:
        'Only the sending branch can dispatch this. Switch branch to act.',
    receive:
        'Only the receiving branch can receive this. Switch branch to act.',
};
const item = (
    id: string,
    label: string,
    row: DocumentRow,
    action: 'decide' | 'dispatch' | 'receive',
) =>
    canRequest(row, action) || wrongBranch(row, action)
        ? [
              {
                  id,
                  label,
                  allowed: canRequest(row, action) && !busy.value,
                  reason: wrongBranch(row, action) ? SWITCH[action] : undefined,
              },
          ]
        : [];
const requestMenu = (row: DocumentRow): RowMenuGroup[] => {
    const groups: RowMenuGroup[] = [
        {
            label: 'Open',
            items: [
                { id: 'lines', label: 'View lines', allowed: true },
                ...((row.differences ?? []).length
                    ? [
                          {
                              id: 'differences',
                              label: openDifferences(row)
                                  ? `Differences (${openDifferences(row)} open)`
                                  : 'Differences',
                              allowed: true,
                          },
                      ]
                    : []),
            ],
        },
    ];
    const move = [
        ...item('dispatch', 'Dispatch stock', row, 'dispatch'),
        ...item('receive', 'Receive stock', row, 'receive'),
    ];

    if (move.length) {
        groups.push({ label: 'Move', items: move });
    }

    const check = item('approve', 'Approve request', row, 'decide');

    if (check.length) {
        groups.push({ label: 'Check', items: check });
    }

    const danger = item('reject', 'Reject request', row, 'decide');

    if (danger.length) {
        groups.push({ label: 'Danger', items: danger });
    }

    return groups;
};
const onRequestMenu = (row: DocumentRow, id: string) => {
    if (id === 'approve') {
        requestConfirmation({
            title: `Approve ${row.number}?`,
            description: `${row.source} can then dispatch it to ${row.destination}. No stock moves until it is dispatched.`,
            confirmLabel: 'Approve request',
            action: () => decideStockRequest(row, 'approve'),
        });

        return;
    }

    if (id === 'reject') {
        requestConfirmation({
            title: `Reject ${row.number}?`,
            description:
                'The request is closed and no stock moves. Someone has to raise a new request if the stock is still needed.',
            confirmLabel: 'Reject request',
            destructive: true,
            action: () => decideStockRequest(row, 'reject'),
        });

        return;
    }

    openSheet(id as Sheet, row);
};

// ---- Sentences said before the press --------------------------------------------------------------------------------
const dispatchReady = computed(
    () =>
        !!sheetRow.value &&
        sheetRow.value.lines.every(
            (line) => (dispatchBatch[line.publicId] ?? '') !== '',
        ),
);

// Approve and reject (a plain sentence about what happens next).
const confirmOpen = computed({
    get: () => pendingConfirmation.value !== null,
    set: (open: boolean) => {
        if (!open) {
            pendingConfirmation.value = null;
        }
    },
});
</script>

<template>
    <div class="space-y-3" data-test="v2-requests-section">
        <div
            v-if="operationError && !sheet"
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

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-muted-foreground">
                Move stock between locations. The sending branch approves and
                dispatches; the receiving branch counts what arrived.
            </p>
            <button
                v-if="operations.permissions.createRequests"
                type="button"
                class="inline-flex h-11 items-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                :disabled="busy"
                @click="openSheet('request')"
            >
                Request stock
            </button>
        </div>
        <V2Table
            label="Stock requests"
            :columns="4"
            cards="xl"
            min-width="760px"
            :empty="operations.stockRequests.length === 0"
            empty-message="No stock requests yet."
        >
            <template #head>
                <tr>
                    <th scope="col">Request</th>
                    <th scope="col">Lines</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-right">Actions</th>
                </tr>
            </template>
            <template #body>
                <tr v-for="row in operations.stockRequests" :key="row.publicId">
                    <td data-label="Request" data-primary>
                        <div class="min-w-0">
                            <span class="block font-medium break-words"
                                >{{ row.source }} → {{ row.destination }}</span
                            >
                            <span
                                class="block text-xs break-words text-muted-foreground"
                                >{{ row.number }}</span
                            >
                        </div>
                    </td>
                    <td data-label="Lines">{{ linesSummary(row) }}</td>
                    <td data-label="Status">
                        <div class="flex flex-wrap gap-1.5">
                            <V2Chip
                                :tone="status(row).tone"
                                :label="status(row).label"
                            />
                            <V2Chip
                                v-if="openDifferences(row)"
                                tone="warning"
                                :label="`${openDifferences(row)} ${openDifferences(row) === 1 ? 'difference' : 'differences'} open`"
                            />
                        </div>
                    </td>
                    <td data-actions class="text-right">
                        <V2RowMenu
                            :label="`Actions for ${row.number}`"
                            :groups="requestMenu(row)"
                            :busy="busy"
                            @select="(id) => onRequestMenu(row, id)"
                        />
                    </td>
                </tr>
            </template>
        </V2Table>
    </div>

    <!-- Request stock (long form). -->
    <V2Dialog
        :open="sheet === 'request'"
        size="tall"
        :dirty="dirty"
        title="Request stock"
        description="The sending branch approves the request, then dispatches it."
        confirm-label="Send request"
        cancel-label="Cancel"
        :busy="busy"
        @update:open="requestClose"
        @confirm="sendRequest"
    >
        <p v-if="operationError" role="alert" class="text-sm text-destructive">
            {{ operationError }}
        </p>
        <InventoryPickerField
            v-model="stockRequest.source"
            kind="locations"
            label="Take from"
            required
            :expected-branch-id="operations.expectedBranchId"
            :error="fieldError('source_location_public_id')"
        />
        <InventoryPickerField
            v-model="stockRequest.destination"
            kind="locations"
            label="Send to"
            required
            :expected-branch-id="operations.expectedBranchId"
            :error="
                sameLocation
                    ? 'Choose a different location from the one you take from.'
                    : fieldError('destination_location_public_id')
            "
        />
        <fieldset
            v-for="(line, index) in stockRequest.lines"
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
            <button
                v-if="stockRequest.lines.length > 1"
                type="button"
                class="inline-flex h-11 items-center justify-center justify-self-start rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted"
                @click="removeStockRequestLine(index)"
            >
                Remove line {{ index + 1 }}
            </button>
        </fieldset>
        <button
            type="button"
            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted disabled:opacity-50 lg:h-9"
            :disabled="busy"
            @click="addStockRequestLine"
        >
            Add a line
        </button>
    </V2Dialog>

    <!-- View lines (read only). -->
    <V2Dialog
        :open="sheet === 'lines' && !!sheetRow"
        :title="sheetRow ? `Lines on ${sheetRow.number}` : ''"
        :description="
            sheetRow ? `${sheetRow.source} → ${sheetRow.destination}` : ''
        "
        confirm-label="Close"
        single-action
        @update:open="requestClose"
        @confirm="closeSheet"
    >
        <ul v-if="sheetRow" class="grid gap-2">
            <li
                v-for="line in sheetRow.lines"
                :key="line.publicId"
                class="rounded-[14px] border p-3 text-sm"
            >
                <p class="font-medium break-words">
                    {{ line.item ?? line.sku }}
                </p>
                <p v-if="line.item" class="text-xs text-muted-foreground">
                    {{ line.sku }}
                </p>
                <p class="text-muted-foreground">
                    Requested
                    {{
                        quantityWithUnit(line.requestedQuantity, line.stockUnit)
                    }}
                    <template v-if="line.dispatchedQuantity">
                        · dispatched
                        {{
                            quantityWithUnit(
                                line.dispatchedQuantity,
                                line.stockUnit,
                            )
                        }}</template
                    >
                    <template v-if="line.batch">
                        · batch {{ line.batch }}</template
                    >
                </p>
            </li>
        </ul>
    </V2Dialog>

    <!-- Dispatch: a batch for every line (long form). -->
    <V2Dialog
        :open="sheet === 'dispatch' && !!sheetRow"
        size="tall"
        :dirty="dirty"
        :title="sheetRow ? `Dispatch ${sheetRow.number}` : ''"
        :description="
            sheetRow ? `${sheetRow.source} → ${sheetRow.destination}` : ''
        "
        confirm-label="Dispatch stock"
        cancel-label="Cancel"
        :busy="busy"
        @update:open="requestClose"
        @confirm="sheetRow && dispatchReady && saveDispatch(sheetRow)"
    >
        <template v-if="sheetRow">
            <div
                v-if="operationError"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                <p class="font-medium">This dispatch was not saved.</p>
                <p>{{ operationError }}</p>
            </div>
            <fieldset
                v-for="line in sheetRow.lines"
                :key="line.publicId"
                class="grid min-w-0 gap-3 rounded-[14px] border p-3"
            >
                <legend
                    class="max-w-full px-1 text-sm font-medium wrap-anywhere"
                >
                    {{ line.item ?? line.sku }}
                </legend>
                <p v-if="line.item" class="-mt-2 text-xs text-muted-foreground">
                    {{ line.sku }}
                </p>
                <p class="text-sm text-muted-foreground">
                    Send
                    {{
                        quantityWithUnit(line.requestedQuantity, line.stockUnit)
                    }}
                </p>
                <InventoryPickerField
                    v-model="dispatchBatch[line.publicId]"
                    kind="batches"
                    label="Batch to send"
                    required
                    :sku="line.skuPublicId"
                    :location="sheetRow.sourcePublicId"
                    :unit="line.stockUnit"
                    :expected-branch-id="operations.expectedBranchId"
                />
            </fieldset>
            <div
                class="rounded-[14px] border bg-muted/40 px-4 py-3 text-sm"
                role="status"
            >
                <p v-if="!dispatchReady">Choose a batch for every line.</p>
                <p>
                    Dispatching takes the stock out of
                    {{ sheetRow.source }} now. {{ sheetRow.destination }} can't
                    use it until they confirm receipt.
                </p>
            </div>
        </template>
    </V2Dialog>

    <TransferReceiveSheet
        v-if="sheet === 'receive' && sheetRow"
        :key="`${sheetRow.publicId}-${sheetRow.lockVersion}`"
        :row="sheetRow"
        :busy="busy"
        :open="true"
        :error="operationError"
        @submit="
            (lines, _differences, retryKey) =>
                sheetRow && saveReceipt(sheetRow, lines, retryKey)
        "
        @close="closeSheet"
    />

    <TransferDifferencesSheet
        v-if="sheet === 'differences' && sheetRow"
        :differences="sheetRow.differences ?? []"
        :number="sheetRow.number"
        :busy="busy"
        :open="true"
        :error="operationError"
        @resolve="
            (difference, outcome, reference, retryKey) =>
                sheetRow &&
                saveResolution(
                    sheetRow,
                    difference,
                    outcome,
                    reference,
                    retryKey,
                )
        "
        @close="closeSheet"
    />

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
</template>
