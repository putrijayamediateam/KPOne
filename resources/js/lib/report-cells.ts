import { formatDate } from '@/lib/presentation';

/**
 * DD-23: how the shared branch report page shows its cells. The server sends every cell as
 * text (the CSV uses the same rows), so the page decides on screen only:
 * - a column whose filled cells are all numbers (money "12.50", counts "3", quantities
 *   "1.250", negatives "-4.00") is right-aligned, so the digits line up;
 * - a date "2026-11-05" reads "5 Nov 2026", and a branch-local date and time
 *   "2026-11-05 14:30:00" reads "5 Nov 2026, 14:30". The cell keeps the original value in
 *   its datetime attribute; downloads are unchanged.
 */
const numberPattern = /^-?\d[\d,]*(\.\d+)?$/;
const datePattern = /^(\d{4}-\d{2}-\d{2})(?: (\d{2}):(\d{2})(?::\d{2})?)?$/;

export const isNumberCell = (value: string): boolean =>
    numberPattern.test(value.trim());

// Identifier columns (a SKU or barcode can be all digits) stay left-aligned whatever they hold,
// so a column never changes alignment from one page of results to the next.
// "Panel", "SKU" and "Item" only as the whole heading: "Panel approved (MYR)" is money.
const identifierHeading =
    /^(SKU|Item|Panel)$|\bnumbers?\b|\bnumber\(s\)|\bcode\b/i;

export const numericColumns = (
    rows: string[][],
    headings: string[],
): boolean[] =>
    headings.map((heading, column) => {
        if (identifierHeading.test(heading)) {
            return false;
        }

        const filled = rows
            .map((row) => (row[column] ?? '').trim())
            .filter((cell) => cell !== '');

        return filled.length > 0 && filled.every(isNumberCell);
    });

export type ReportCell = { text: string; datetime: string | null };

export const reportCell = (value: string): ReportCell => {
    const match = datePattern.exec(value.trim());

    if (!match) {
        return { text: value, datetime: null };
    }

    const day = formatDate(match[1]);

    if (day === '—') {
        return { text: value, datetime: null };
    }

    return {
        text: match[2] ? `${day}, ${match[2]}:${match[3]}` : day,
        datetime: match[2] ? `${match[1]}T${match[2]}:${match[3]}` : match[1],
    };
};
