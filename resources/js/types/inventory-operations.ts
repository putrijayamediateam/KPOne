export type InventoryOption = {
    publicId: string;
    name?: string;
    code?: string;
    type?: string;
    branchId?: number | null;
    stockUnit?: string;
    skuPublicId?: string;
    batchNumber?: string;
    expiryDate?: string;
    isActive?: boolean;
};

export type InventoryDocumentLine = {
    publicId: string;
    skuPublicId?: string;
    sku: string;
    orderedQuantity?: string;
    receivedQuantity?: string;
    requestedQuantity?: string;
    dispatchedQuantity?: string | null;
    physicalQuantity?: string | null;
    expectedQuantity?: string;
    batch?: string | null;
};

export type InventoryDocument = {
    publicId: string;
    number: string;
    status: string;
    lockVersion: number;
    supplierPublicId?: string;
    supplier?: string;
    source?: string;
    destinationPublicId?: string;
    destination?: string;
    location?: string;
    lines: InventoryDocumentLine[];
};

export type InventoryOperations = {
    expectedBranchId: number;
    permissions: Record<string, boolean>;
    suppliers: InventoryOption[];
    locations: InventoryOption[];
    skus: InventoryOption[];
    batches: InventoryOption[];
    purchaseOrders: InventoryDocument[];
    stockRequests: InventoryDocument[];
    stocktakes: InventoryDocument[];
    adjustments: Array<Record<string, string>>;
    lowStock: Array<Record<string, string>>;
    expiringBatches: Array<Record<string, string>>;
};

export type InventoryIntentIdentity = {
    signature: string;
    key: string;
};

export type InventoryReceiptLineIntent = {
    line_public_id: string;
    quantity: string;
    batch_number: string | null;
    expiry_date: string | null;
};

export type InventoryReceiptMemoryContext = {
    version: 2;
    organisationPublicId: string;
    actorPublicId: string;
    sessionNonce: string;
    branchPublicId: string;
};

export type InventoryReceiptRememberedEntry = {
    version: 2;
    purchaseOrderPublicId: string;
    signature: string;
    idempotencyKey: string;
    pending: true;
    payload: InventoryReceiptLineIntent[];
};

export type InventoryReceiptRememberedState = {
    version: 2;
    context: string;
    entries: Record<string, InventoryReceiptRememberedEntry>;
};

export type InventoryReceiptRememberedVault = {
    version: 2;
    contexts: Record<string, InventoryReceiptRememberedState>;
};

type InventoryReceiptDraft = {
    batchNumber: string;
    expiryDate: string;
    quantity: string;
};

export type InventoryReceiptPayloadValidation =
    | { valid: true; payload: InventoryReceiptLineIntent[] }
    | { valid: false; error: string };

export type InventoryReceiptSubmissionPreparation =
    | {
          valid: true;
          state: InventoryReceiptRememberedState;
          entry: InventoryReceiptRememberedEntry;
      }
    | { valid: false; error: string };

const uuidPattern =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const publicScopeIdentityPattern = /^[0-9a-f]{64}$/i;
const receiptRememberContextPattern =
    /^v2:organisation:[0-9a-f]{64}:actor:[0-9a-f]{64}:session:[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}:branch:[0-9a-f]{64}$/;
const maximumRememberedReceiptContexts = 8;
const receiptQuantityPattern = /^(\d{1,12})(?:\.(\d{1,3}))?$/;

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

export const inventoryReceiptRememberContext = (
    value: unknown,
): string | null => {
    if (
        !isRecord(value) ||
        value.version !== 2 ||
        typeof value.organisationPublicId !== 'string' ||
        typeof value.actorPublicId !== 'string' ||
        typeof value.sessionNonce !== 'string' ||
        typeof value.branchPublicId !== 'string' ||
        !publicScopeIdentityPattern.test(value.organisationPublicId) ||
        !publicScopeIdentityPattern.test(value.actorPublicId) ||
        !uuidPattern.test(value.sessionNonce) ||
        !publicScopeIdentityPattern.test(value.branchPublicId)
    ) {
        return null;
    }

    return [
        'v2',
        'organisation',
        value.organisationPublicId.toLowerCase(),
        'actor',
        value.actorPublicId.toLowerCase(),
        'session',
        value.sessionNonce.toLowerCase(),
        'branch',
        value.branchPublicId.toLowerCase(),
    ].join(':');
};

export const normalizeInventoryReceiptQuantity = (
    value: unknown,
): string | null => {
    if (typeof value !== 'string') {
        return null;
    }

    const match = receiptQuantityPattern.exec(value);

    if (!match) {
        return null;
    }

    const whole = match[1].replace(/^0+(?=\d)/, '');
    const fraction = (match[2] ?? '').padEnd(3, '0');

    if (/^0+$/.test(whole) && /^0+$/.test(fraction)) {
        return null;
    }

    return `${whole}.${fraction}`;
};

export const validateInventoryReceiptPayload = (
    lines: Array<{
        line_public_id: string;
        quantity: string;
        batch_number: string | null;
        expiry_date: string | null;
    }>,
): InventoryReceiptPayloadValidation => {
    const payload: InventoryReceiptLineIntent[] = [];

    for (const line of lines) {
        if (line.quantity === '') {
            continue;
        }

        const quantity = normalizeInventoryReceiptQuantity(line.quantity);

        if (quantity === null) {
            return {
                valid: false,
                error: 'Each entered receipt quantity must be a positive decimal with up to 12 whole-number digits and 3 decimal places, without spaces or signs.',
            };
        }

        payload.push({
            line_public_id: line.line_public_id,
            quantity,
            batch_number: line.batch_number?.trim() || null,
            expiry_date: line.expiry_date?.trim() || null,
        });
    }

    if (payload.length === 0) {
        return {
            valid: false,
            error: 'Enter at least one positive receipt quantity.',
        };
    }

    if (
        payload.length > 100 ||
        new Set(payload.map((line) => line.line_public_id)).size !==
            payload.length
    ) {
        return {
            valid: false,
            error: 'Receipt lines must be unique and contain no more than 100 entries.',
        };
    }

    payload.sort((left, right) =>
        left.line_public_id.localeCompare(right.line_public_id),
    );

    return { valid: true, payload };
};

export const inventoryReceiptSignature = (
    payload: InventoryReceiptLineIntent[],
): string => JSON.stringify(payload);

export const emptyInventoryReceiptRememberedState = (
    context: string,
): InventoryReceiptRememberedState => ({ version: 2, context, entries: {} });

export const emptyInventoryReceiptRememberedVault =
    (): InventoryReceiptRememberedVault => ({ version: 2, contexts: {} });

export const restoreInventoryReceiptRememberedVault = (
    value: unknown,
    activeContext?: string,
): InventoryReceiptRememberedVault => {
    const restored = emptyInventoryReceiptRememberedVault();

    if (!isRecord(value) || value.version !== 2 || !isRecord(value.contexts)) {
        return restored;
    }

    for (const [context, state] of Object.entries(value.contexts)) {
        if (
            !receiptRememberContextPattern.test(context) ||
            (activeContext !== undefined && context !== activeContext) ||
            !isRecord(state) ||
            state.version !== 2 ||
            state.context !== context ||
            !isRecord(state.entries)
        ) {
            continue;
        }

        restored.contexts[context] = state as InventoryReceiptRememberedState;
    }

    return restored;
};

export const storeInventoryReceiptRememberedState = (
    vault: InventoryReceiptRememberedVault,
    state: InventoryReceiptRememberedState,
): InventoryReceiptRememberedVault => {
    const retained = Object.entries(vault.contexts)
        .filter(
            ([context]) =>
                context !== state.context &&
                receiptRememberContextPattern.test(context),
        )
        .slice(-(maximumRememberedReceiptContexts - 1));

    return {
        version: 2,
        contexts: Object.fromEntries([...retained, [state.context, state]]),
    };
};

export const restoreInventoryReceiptRememberedState = (
    value: unknown,
    context: string,
    allowedLinesByPurchaseOrder: Record<string, readonly string[]>,
): InventoryReceiptRememberedState => {
    const restored = emptyInventoryReceiptRememberedState(context);

    if (
        !isRecord(value) ||
        value.version !== 2 ||
        value.context !== context ||
        !isRecord(value.entries)
    ) {
        return restored;
    }

    const usedKeys = new Set<string>();

    for (const [purchaseOrderPublicId, candidate] of Object.entries(
        value.entries,
    )) {
        const allowedLines = allowedLinesByPurchaseOrder[purchaseOrderPublicId];

        if (
            !uuidPattern.test(purchaseOrderPublicId) ||
            !Object.prototype.hasOwnProperty.call(
                allowedLinesByPurchaseOrder,
                purchaseOrderPublicId,
            ) ||
            !Array.isArray(allowedLines) ||
            !allowedLines ||
            !isRecord(candidate) ||
            candidate.version !== 2 ||
            candidate.purchaseOrderPublicId !== purchaseOrderPublicId ||
            candidate.pending !== true ||
            typeof candidate.signature !== 'string' ||
            typeof candidate.idempotencyKey !== 'string' ||
            !uuidPattern.test(candidate.idempotencyKey) ||
            !Array.isArray(candidate.payload)
        ) {
            continue;
        }

        const validPayloadShape = candidate.payload.every(
            (line) =>
                isRecord(line) &&
                typeof line.line_public_id === 'string' &&
                typeof line.quantity === 'string' &&
                (line.batch_number === null ||
                    typeof line.batch_number === 'string') &&
                (line.expiry_date === null ||
                    typeof line.expiry_date === 'string'),
        );

        if (!validPayloadShape) {
            continue;
        }

        const rawPayload = candidate.payload.map((line) => {
            const record = line as Record<string, string | null>;

            return {
                line_public_id: record.line_public_id ?? '',
                quantity: record.quantity ?? '',
                batch_number: record.batch_number,
                expiry_date: record.expiry_date,
            };
        });
        const validation = validateInventoryReceiptPayload(rawPayload);
        const normalizedKey = candidate.idempotencyKey.toLowerCase();

        if (
            rawPayload.length !== candidate.payload.length ||
            !validation.valid ||
            validation.payload.length !== candidate.payload.length ||
            validation.payload.some(
                (line) =>
                    !uuidPattern.test(line.line_public_id) ||
                    !allowedLines.includes(line.line_public_id),
            ) ||
            inventoryReceiptSignature(validation.payload) !==
                candidate.signature ||
            usedKeys.has(normalizedKey)
        ) {
            continue;
        }

        restored.entries[purchaseOrderPublicId] = {
            version: 2,
            purchaseOrderPublicId,
            signature: candidate.signature,
            idempotencyKey: normalizedKey,
            pending: true,
            payload: validation.payload,
        };
        usedKeys.add(normalizedKey);
    }

    return restored;
};

export const rememberInventoryReceiptIntent = (
    state: InventoryReceiptRememberedState,
    context: string,
    purchaseOrderPublicId: string,
    lines: InventoryReceiptLineIntent[],
    createKey: () => string,
): {
    state: InventoryReceiptRememberedState;
    entry: InventoryReceiptRememberedEntry;
} => {
    const validation = validateInventoryReceiptPayload(lines);

    if (!validation.valid) {
        throw new Error(validation.error);
    }

    const payload = validation.payload;
    const signature = inventoryReceiptSignature(payload);
    const current =
        state.context === context
            ? state.entries[purchaseOrderPublicId]
            : undefined;
    const entry: InventoryReceiptRememberedEntry =
        current?.signature === signature
            ? { ...current, payload, pending: true }
            : {
                  version: 2,
                  purchaseOrderPublicId,
                  signature,
                  idempotencyKey: createKey(),
                  pending: true,
                  payload,
              };

    return {
        state: {
            version: 2,
            context,
            entries: {
                ...(state.context === context ? state.entries : {}),
                [purchaseOrderPublicId]: entry,
            },
        },
        entry,
    };
};

export const prepareInventoryReceiptSubmission = (
    state: InventoryReceiptRememberedState,
    context: string,
    purchaseOrderPublicId: string,
    lines: Array<{
        line_public_id: string;
        quantity: string;
        batch_number: string | null;
        expiry_date: string | null;
    }>,
    createKey: () => string,
): InventoryReceiptSubmissionPreparation => {
    const validation = validateInventoryReceiptPayload(lines);

    if (!validation.valid) {
        return validation;
    }

    return {
        valid: true,
        ...rememberInventoryReceiptIntent(
            state,
            context,
            purchaseOrderPublicId,
            validation.payload,
            createKey,
        ),
    };
};

export const consumeInventoryReceiptIntent = (
    state: InventoryReceiptRememberedState,
    context: string,
    purchaseOrderPublicId: string,
    idempotencyKey: string,
    signature: string,
): { state: InventoryReceiptRememberedState; consumed: boolean } => {
    const current = state.entries[purchaseOrderPublicId];

    if (
        state.context !== context ||
        current?.idempotencyKey !== idempotencyKey ||
        current.signature !== signature
    ) {
        return { state, consumed: false };
    }

    const entries = { ...state.entries };
    delete entries[purchaseOrderPublicId];

    return {
        state: { version: 2, context, entries },
        consumed: true,
    };
};

export const inventoryReceiptDrafts = (
    state: InventoryReceiptRememberedState,
): Record<string, Record<string, InventoryReceiptDraft>> =>
    Object.fromEntries(
        Object.entries(state.entries).map(([purchaseOrderPublicId, entry]) => [
            purchaseOrderPublicId,
            Object.fromEntries(
                entry.payload.map((line) => [
                    line.line_public_id,
                    {
                        batchNumber: line.batch_number ?? '',
                        expiryDate: line.expiry_date ?? '',
                        quantity: line.quantity,
                    },
                ]),
            ),
        ]),
    );

export const inventoryIntentIdentity = (
    current: InventoryIntentIdentity | undefined,
    signature: string,
    createKey: () => string,
): InventoryIntentIdentity =>
    current?.signature === signature
        ? current
        : { signature, key: createKey() };

export const firstInventoryOperationError = (
    errors: Record<string, string>,
): string | null => Object.values(errors)[0] ?? null;

export const inventoryOperationErrorContext = (url: string): string => {
    if (url.includes('/receipts')) {
        return 'Goods receipt';
    }

    if (url.includes('/dispatch')) {
        return 'Transfer dispatch';
    }

    if (url.includes('/stocktakes')) {
        return 'Stocktake';
    }

    if (url.includes('/adjustments')) {
        return 'Manual adjustment';
    }

    if (url.includes('/suppliers')) {
        return 'Supplier action';
    }

    if (url.includes('/purchase-orders')) {
        return 'Purchase Order action';
    }

    if (url.includes('/stock-requests')) {
        return 'Stock Request action';
    }

    if (url.includes('/reorder-levels')) {
        return 'Reorder level action';
    }

    return 'Inventory operation';
};

export const inventoryFilterError = (
    errors: Record<string, unknown>,
): string | null => {
    for (const key of [
        'tab',
        'search',
        'location',
        'status',
        'movement_type',
        'batch',
        'page',
    ]) {
        const value = errors[key];

        if (typeof value === 'string') {
            return value;
        }
    }

    return null;
};
