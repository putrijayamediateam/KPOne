/**
 * DSP-11 scan to check at Dispensary (owner decision 2026-11-06): a scanned box is compared with the saved
 * medicine lines' mapped SKUs. It only warns: nothing is saved, recorded or blocked, and a mark lives on this
 * screen only. It does not check the quantity. DSP-20 compares the batch on a GS1 box with the line's saved
 * batches (`boxBatchCheck`).
 */
import type { Gs1Read } from '@/lib/scan';

export type ScanCheckLine = {
    publicId: string;
    name: string;
    strength: string | null;
    skuPublicId: string | null;
};

export type ScannedProduct = {
    public_id: string;
    sku_code: string;
    item_name: string;
};

/** Line public id -> the SKU public id that was scanned for it. */
export type ScanMarks = Record<string, string>;

export type ScanOutcome =
    | { kind: 'match' | 'already'; message: string; marks: ScanMarks }
    | { kind: 'removed' | 'not_on_case'; message: string };

export function lineName(line: ScanCheckLine): string {
    return [line.name, line.strength].filter(Boolean).join(' ');
}

/** Lines whose saved SKU is the scanned SKU; a line with no SKU never matches. */
export function matchingLines(
    skuPublicId: string,
    lines: ScanCheckLine[],
): ScanCheckLine[] {
    return lines.filter(
        (line) => line.skuPublicId !== null && line.skuPublicId === skuPublicId,
    );
}

/** A line counts as checked only while its saved SKU is the one that was scanned for it. */
export function isChecked(line: ScanCheckLine, marks: ScanMarks): boolean {
    return (
        line.skuPublicId !== null && marks[line.publicId] === line.skuPublicId
    );
}

export function checkedCount(lines: ScanCheckLine[], marks: ScanMarks): number {
    return lines.filter((line) => isChecked(line, marks)).length;
}

export function scannableCount(lines: ScanCheckLine[]): number {
    return lines.filter((line) => line.skuPublicId !== null).length;
}

/** Keep marks only for lines still shown with the same SKU (a removed or changed line loses its mark). */
export function keepCurrentMarks(
    lines: ScanCheckLine[],
    marks: ScanMarks,
): ScanMarks {
    return Object.fromEntries(
        lines
            .filter((line) => isChecked(line, marks))
            .map((line) => [line.publicId, line.skuPublicId as string]),
    );
}

/** Names the scanned product by item and SKU code, so another pack or strength of the same medicine is clear. */
export function notOnCaseMessage(itemName: string, skuCode: string): string {
    return `Not on this case: ${itemName} (${skuCode}). Check you picked the right medicine or strength.`;
}

/** A scanned SKU that is only on removed lines is not a match (Daniel, DSP-11). */
export function removedLineMessage(line: ScanCheckLine): string {
    return `On this case but removed: ${lineName(line)}. Check you picked the right medicine.`;
}

function countText(checked: number, total: number): string {
    return `${checked} of ${total} ${total === 1 ? 'line' : 'lines'} checked by scan.`;
}

/** What one scan means for this case; `marks` is the new set to keep when it matched. */
export function checkScan(
    sku: ScannedProduct,
    visible: ScanCheckLine[],
    removed: ScanCheckLine[],
    marks: ScanMarks,
): ScanOutcome {
    const matched = matchingLines(sku.public_id, visible);

    if (matched.length === 0) {
        const gone = matchingLines(sku.public_id, removed);

        return gone.length
            ? { kind: 'removed', message: removedLineMessage(gone[0]) }
            : {
                  kind: 'not_on_case',
                  message: notOnCaseMessage(sku.item_name, sku.sku_code),
              };
    }

    const next = { ...keepCurrentMarks(visible, marks) };
    const fresh = matched.filter((line) => !isChecked(line, next));

    for (const line of matched) {
        next[line.publicId] = sku.public_id;
    }

    const count = countText(
        checkedCount(visible, next),
        scannableCount(visible),
    );

    if (fresh.length === 0) {
        return {
            kind: 'already',
            message: `Already checked: ${lineName(matched[0])}. ${count} Shown on this screen only.`,
            marks: next,
        };
    }

    const what =
        matched.length === 1
            ? `Matches: ${lineName(matched[0])}.`
            : `Matches ${matched.length} lines: ${lineName(matched[0])}.`;

    return {
        kind: 'match',
        message: `${what} ${count} Shown on this screen only.`,
        marks: next,
    };
}

/** DSP-12: what a scan to add means, given the medicines mapped to the scanned SKU (shaped like search results). */
export type ScanAddMedicine = {
    name: string;
    strength: string | null;
    availability: unknown[];
    allergyMatches?: unknown[];
};

export type ScanAddOutcome<T extends ScanAddMedicine> =
    | { kind: 'choose'; medicine: T; message: string }
    | { kind: 'list'; medicines: T[]; message: string; allergy: boolean }
    | { kind: 'none'; message: string };

export function scanAddOutcome<T extends ScanAddMedicine>(
    medicines: T[],
    sku: ScannedProduct,
): ScanAddOutcome<T> {
    if (medicines.length === 0) {
        return {
            kind: 'none',
            message: `${sku.sku_code} · ${sku.item_name} is not linked to a catalogue medicine, so it cannot be added by scan. Search by name, or ask a CA Supervisor to link it.`,
        };
    }

    const name = (m: T) => [m.name, m.strength].filter(Boolean).join(' ');

    if (medicines.length > 1) {
        return {
            kind: 'list',
            medicines,
            message: `Found ${medicines.length} medicines for this product. Choose one.`,
            allergy: medicines.some((m) => (m.allergyMatches ?? []).length > 0),
        };
    }

    const [medicine] = medicines;

    if ((medicine.allergyMatches ?? []).length > 0) {
        return {
            kind: 'list',
            medicines,
            message: `Found ${name(medicine)}. Possible allergy match: check the allergy list before you choose it.`,
            allergy: true,
        };
    }

    if (medicine.availability.length === 0) {
        return {
            kind: 'list',
            medicines,
            message: `Found ${name(medicine)}, but there is no stock to dispense at this branch.`,
            allergy: false,
        };
    }

    return {
        kind: 'choose',
        medicine,
        message: `Found ${name(medicine)}. Set the quantity and batch, then press Add.`,
    };
}

/** DSP-20: a batch saved on a medicine line (its allocations). */
export type SavedBatch = {
    batchNumber?: string | null;
    expiryDate?: string | null;
};

export type BoxBatchCheck = {
    kind: 'matches' | 'expiry_differs' | 'differs';
    warn: boolean;
    message: string;
};

const sameText = (a: string, b: string): boolean =>
    a.trim().toUpperCase() === b.trim().toUpperCase();

/**
 * DSP-20 (owner: "go with DSP-20"): what the batch on a scanned GS1 box says about a line that scan to check marked.
 * Warn only; nothing is saved or blocked. Returns null when there is nothing to compare: a plain barcode, a code with
 * no batch, or a line with no saved batch yet. A batch read without separators may have swallowed a following field,
 * so it is used only when it matches a saved batch exactly (as in DSP-18).
 */
export function boxBatchCheck(
    read: Gs1Read | null,
    saved: SavedBatch[],
): BoxBatchCheck | null {
    const savedNumbers = saved.filter(
        (batch): batch is SavedBatch & { batchNumber: string } =>
            typeof batch.batchNumber === 'string' &&
            batch.batchNumber.trim() !== '',
    );
    const candidate =
        (read?.batch ?? read?.batch_unconfirmed ?? '').trim() || null;

    if (read === null || candidate === null || savedNumbers.length === 0) {
        return null;
    }

    const same = savedNumbers.filter((batch) =>
        sameText(batch.batchNumber, candidate),
    );

    if (same.length === 0) {
        return read.batch === null
            ? null
            : {
                  kind: 'differs',
                  warn: true,
                  message: `Box batch ${candidate} is not the batch saved on this line. Check the box.`,
              };
    }

    if (
        read.expiry !== null &&
        !same.some(
            (batch) => (batch.expiryDate ?? '').slice(0, 10) === read.expiry,
        )
    ) {
        return {
            kind: 'expiry_differs',
            warn: true,
            message: `Box batch ${candidate} is saved on this line, but its expiry is different. Check the box.`,
        };
    }

    return {
        kind: 'matches',
        warn: false,
        message: `Batch ${candidate} matches the box.`,
    };
}
