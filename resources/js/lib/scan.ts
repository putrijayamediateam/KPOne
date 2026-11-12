/** STK-9 scanning helpers. The server stays the authority: a scan only finds a SKU and never saves anything. */
export type ScannedSku = {
    public_id: string;
    sku_code: string;
    item_name: string;
    stock_unit: string;
    active: boolean;
};

/** DSP-18: what a GS1 code says about the box; present only when the scanned code was a GS1 element string. */
export type Gs1Read = {
    gtin: string;
    /** Set only when the batch's end was known. */
    batch: string | null;
    /** A batch that ran to the end of a code without separators, so it may have swallowed another field: compare only. */
    batch_unconfirmed: string | null;
    expiry: string | null;
    /** Every field was understood and ended cleanly. Not used by the Dispensary; for receiving (STK-26). */
    complete: boolean;
};

export type ScanLookup =
    | { status: 'found'; code: string; sku: ScannedSku; gs1?: Gs1Read }
    | { status: 'not_found'; code: string; gs1?: Gs1Read }
    | { status: 'shared'; code: string; skus: string[]; gs1?: Gs1Read };

/** DSP-18: the GS separator inside a GS1 code is invisible; show it as a space in messages. */
export function shownCode(code: string): string {
    return code.replace(/\u001D/g, ' ').trim();
}

export type CameraSupport = 'supported' | 'insecure' | 'unsupported';

/**
 * Scanners may add Tab, Enter or other control characters; trim them but never change the code itself. DSP-18: the GS
 * character (U+001D) is kept, because in a GS1 code it marks where a batch number ends.
 */
export function cleanScannedCode(raw: string): string {
    return raw
        .replace(/(?!\u001D)[\p{Cc}\p{Cf}]/gu, '')
        .trim()
        .slice(0, 100);
}

export function scanMessage(
    result: ScanLookup,
    context: 'search' | 'receiving' | 'dispensary' | 'dispensary-add',
): string {
    if (context === 'dispensary') {
        return dispensaryScanMessage(result);
    }

    if (context === 'dispensary-add') {
        return dispensaryAddScanMessage(result);
    }

    if (result.status === 'not_found') {
        return `No SKU has the code ${shownCode(result.code)}. Check the code or search by name.`;
    }

    if (result.status === 'shared') {
        return 'This code is on more than one SKU. Fix it on the Pilot readiness page before scanning it. Search by name for now.';
    }

    const label = `${result.sku.sku_code} · ${result.sku.item_name}`;

    if (!result.sku.active) {
        return `SKU ${label} is inactive, so it has no stock to show here.`;
    }

    return context === 'receiving'
        ? `Found SKU ${label}. This order line is highlighted. Pick the batch by hand.`
        : `Found SKU ${label}, showing its stock.`;
}

export const cameraMessages = {
    unsupported:
        "Camera scanning isn't available on this device. Use a scanner or type the code.",
    insecure:
        'Camera scanning needs a secure (https) connection. Use a scanner or type the code.',
    denied: 'Camera access was blocked. Allow the camera in your browser settings, or use a scanner or type the code.',
    error: 'The camera could not start. Close other apps using it and try again, or type the code.',
} as const;

/** Uses the browser's built-in BarcodeDetector only (owner decision: no barcode library). */
export function cameraSupport(scope: {
    isSecureContext?: boolean;
    BarcodeDetector?: unknown;
    navigator?: { mediaDevices?: { getUserMedia?: unknown } };
}): CameraSupport {
    if (!scope.isSecureContext) {
        return 'insecure';
    }

    return typeof scope.BarcodeDetector === 'function' &&
        typeof scope.navigator?.mediaDevices?.getUserMedia === 'function'
        ? 'supported'
        : 'unsupported';
}

export const BARCODE_FORMATS = [
    'ean_13',
    'ean_8',
    'upc_a',
    'upc_e',
    'code_128',
    'code_39',
    'itf',
    'qr_code',
    'data_matrix',
];

/** Ignore the same code read again within this window (camera frames, double wedge scans). */
export const REPEAT_WINDOW_MS = 1000;

export function isRepeat(
    previous: { code: string; at: number } | null,
    code: string,
    now: number,
): boolean {
    return (
        previous !== null &&
        previous.code === code &&
        now - previous.at < REPEAT_WINDOW_MS
    );
}

/** DSP-11 wording on the Dispensary page, where a scan only checks the box against the case. */
function dispensaryScanMessage(result: ScanLookup): string {
    if (result.status === 'not_found') {
        return `No medicine has the code ${shownCode(result.code)}. Check the code, or check the box by eye.`;
    }

    if (result.status === 'shared') {
        return 'This code is on more than one SKU, so it cannot be checked by scan. Check the box by eye.';
    }

    const label = `${result.sku.sku_code} · ${result.sku.item_name}`;

    return result.sku.active
        ? `Found SKU ${label}.`
        : `SKU ${label} is inactive. Check the box by eye.`;
}

/** DSP-12 wording when scanning to find a medicine to add: it ends by pointing back to the name search. */
function dispensaryAddScanMessage(result: ScanLookup): string {
    if (result.status === 'not_found') {
        return `No medicine has the code ${shownCode(result.code)}. Search by name instead.`;
    }

    if (result.status === 'shared') {
        return 'This code is on more than one SKU, so it cannot be used to add a medicine. Search by name instead.';
    }

    const label = `${result.sku.sku_code} · ${result.sku.item_name}`;

    return result.sku.active
        ? `Found SKU ${label}.`
        : `SKU ${label} is inactive. Search by name instead.`;
}
