/**
 * DSP-11 scan to check at Dispensary (owner decision 2026-11-06): a scanned box is compared with the saved
 * medicine lines' mapped SKUs. It only warns: nothing is saved, recorded or blocked.
 */
export type ScanCheckLine = {
    publicId: string;
    name: string;
    strength: string | null;
    skuPublicId: string | null;
};

/** Lines whose saved SKU is the scanned SKU; a line with no SKU never matches. */
export function matchingLines(
    skuPublicId: string,
    lines: ScanCheckLine[],
): ScanCheckLine[] {
    return lines.filter(
        (line) => line.skuPublicId !== null && line.skuPublicId === skuPublicId,
    );
}

/** Names the scanned product by item and SKU code, so another pack or strength of the same medicine is clear. */
export function notOnCaseMessage(itemName: string, skuCode: string): string {
    return `Not on this case: ${itemName} (${skuCode}). Check you picked the right medicine or strength.`;
}

function lineName(line: ScanCheckLine): string {
    return [line.name, line.strength].filter(Boolean).join(' ');
}

/** The message after a match; `checkedAfter` counts the lines checked once this scan is applied. */
export function matchMessage(
    matched: ScanCheckLine[],
    alreadyChecked: boolean,
    checkedAfter: number,
    scannable: number,
): string {
    const count = `${checkedAfter} of ${scannable} ${scannable === 1 ? 'medicine' : 'medicines'} checked.`;

    if (alreadyChecked) {
        return `Already checked: ${lineName(matched[0])}. ${count}`;
    }

    if (matched.length === 1) {
        return `Matches: ${lineName(matched[0])}. Marked as checked. ${count}`;
    }

    return `Matches ${matched.length} lines: ${lineName(matched[0])}. ${matched.length === 2 ? 'Both' : 'All'} marked as checked. ${count}`;
}
