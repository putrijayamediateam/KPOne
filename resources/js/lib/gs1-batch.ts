/**
 * DSP-18: what to say under a batch dropdown after a GS1 scan. It only points: it never selects, saves or creates a
 * batch, and it never guesses from a batch it could not read with certainty.
 */
import type { Gs1Read } from '@/lib/scan';

export type BatchOption = {
    batchNumber: string;
    expiryDate: string;
    held?: boolean;
};

export type BoxBatchHint = {
    /** Words shown in the status line under the dropdown. */
    message: string;
    /** Indexes of options that match the box (same batch number, and the same expiry when both are known). */
    matches: number[];
    /** Warning tone: the box names a batch that is not usable or not in this list. */
    warn: boolean;
};

const normalise = (value: string): string => value.trim().toUpperCase();

export function boxBatchHint(
    read: Gs1Read | null,
    options: BatchOption[],
    formatDate: (value: string) => string,
    /** The DSP-17 hint, used when the code carried no batch or expiry (a plain barcode). */
    pickHint: string,
): BoxBatchHint {
    if (read === null || (read.batch === null && read.expiry === null)) {
        return { message: pickHint, matches: [], warn: false };
    }

    const expiry = read.expiry === null ? '' : `exp ${formatDate(read.expiry)}`;
    const matches =
        read.batch === null
            ? []
            : options.flatMap((option, index) =>
                  normalise(option.batchNumber) ===
                      normalise(read.batch ?? '') &&
                  (read.expiry === null ||
                      option.expiryDate.slice(0, 10) === read.expiry)
                      ? [index]
                      : [],
              );

    // A batch read without separators may have swallowed a following field: use it only when it matches exactly.
    if (read.batch === null || (!read.batch_certain && matches.length === 0)) {
        const parts = [
            expiry ? `Box: ${expiry}.` : '',
            read.batch === null
                ? ''
                : 'The batch could not be read from this code.',
            pickHint,
        ];

        return {
            message: parts.filter((part) => part !== '').join(' '),
            matches: [],
            warn: false,
        };
    }

    const box = `Box: batch ${read.batch}${expiry ? `, ${expiry}` : ''}.`;
    const usable = matches.filter((index) => !options[index]?.held);

    if (matches.length === 0) {
        return {
            message: `${box} No batch in the list matches it. Check the box.`,
            matches: [],
            warn: true,
        };
    }

    if (usable.length === 0) {
        return {
            message: `${box} It matches a batch on hold, which cannot be used. Check with a supervisor.`,
            matches,
            warn: true,
        };
    }

    return {
        message:
            usable.length === 1
                ? `${box} One batch in the list matches it. Pick it yourself.`
                : `${box} ${usable.length} batches in the list match it (different locations). Pick the right one yourself.`,
        matches,
        warn: false,
    };
}
