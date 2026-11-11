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
    /** Usable options that match the box (same batch number, and the same expiry when both are known); never held. */
    matches: number[];
    /** Warning tone: the box names a batch that is not usable or not in this list. */
    warn: boolean;
    /** The message names the box's batch (so the page can say when the selected batch is a different one). */
    named: boolean;
    /** Options with the box's batch number, whatever their expiry or hold: "the same batch" for that clause. */
    sameBatch: number[];
};

const normalise = (value: string): string => value.trim().toUpperCase();

export function boxBatchHint(
    read: Gs1Read | null,
    options: BatchOption[],
    formatDate: (value: string) => string,
    /** The DSP-17 hint, used when the code carried no batch or expiry (a plain barcode). */
    pickHint: string,
): BoxBatchHint {
    // A batch whose end was not known (no separators) is compared only, never shown as the box's batch on a miss.
    const candidate = read?.batch ?? read?.batch_unconfirmed ?? null;

    if (read === null || (candidate === null && read.expiry === null)) {
        return {
            message: pickHint,
            matches: [],
            warn: false,
            named: false,
            sameBatch: [],
        };
    }

    const expiry = read.expiry === null ? '' : `exp ${formatDate(read.expiry)}`;
    const sameBatch =
        candidate === null
            ? []
            : options.flatMap((option, index) =>
                  normalise(option.batchNumber) === normalise(candidate)
                      ? [index]
                      : [],
              );
    const matches = sameBatch.filter(
        (index) =>
            read.expiry === null ||
            options[index]?.expiryDate.slice(0, 10) === read.expiry,
    );

    // A batch read without separators may have swallowed a following field: use it only when it matches exactly.
    if (candidate === null || (read.batch === null && matches.length === 0)) {
        const parts = [
            expiry ? `Box: ${expiry}.` : '',
            candidate === null
                ? ''
                : 'The batch could not be read from this code.',
            pickHint,
        ];

        return {
            message: parts.filter((part) => part !== '').join(' '),
            matches: [],
            warn: false,
            named: false,
            sameBatch: [],
        };
    }

    const box = `Box: batch ${candidate}${expiry ? `, ${expiry}` : ''}.`;
    const usable = matches.filter((index) => !options[index]?.held);

    if (matches.length === 0) {
        // The batch number is there, but the expiry entered at receiving differs from the box (Hakim).
        return {
            message:
                sameBatch.length > 0
                    ? `${box} Batch ${candidate} is in the list, but its expiry is different. Check the box.`
                    : `${box} No batch in the list matches it. Check the box.`,
            matches: [],
            warn: true,
            named: true,
            sameBatch,
        };
    }

    if (usable.length === 0) {
        return {
            message: `${box} It matches a batch on hold, which cannot be used. Check with a supervisor.`,
            matches: [],
            warn: true,
            named: true,
            sameBatch,
        };
    }

    return {
        message:
            usable.length === 1
                ? `${box} One batch in the list matches it. Pick it yourself.`
                : `${box} ${usable.length} batches in the list match it (different locations). Pick the right one yourself.`,
        matches: usable,
        warn: false,
        named: true,
        sameBatch,
    };
}
