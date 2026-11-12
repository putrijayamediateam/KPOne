/**
 * STK-26: what to say on a receiving order line after a GS1 box is scanned. Hint only (owner decision, option (a)): it
 * never fills, adds or saves a lot. STK-3's rule stays exactly as it is: a person types every batch and expiry.
 */
import type { Gs1Read } from '@/lib/scan';

export type LotEntry = { batch_number: string; expiry_date: string };

export type BoxLotHint = {
    /** Words shown in the line's status area. */
    message: string;
    /** Warning tone: the box disagrees with a lot already typed. */
    warn: boolean;
};

const normalise = (value: string): string => value.trim().toUpperCase();

export function boxLotHint(
    read: Gs1Read | null,
    lots: LotEntry[],
    formatDate: (value: string) => string,
): BoxLotHint | null {
    // A plain barcode carries no batch or expiry: nothing to add to the STK-9 "Scanned" mark.
    const candidate = read?.batch ?? read?.batch_unconfirmed ?? null;

    if (read === null || (candidate === null && read.expiry === null)) {
        return null;
    }

    const expiry = read.expiry === null ? '' : `exp ${formatDate(read.expiry)}`;
    const sameBatch =
        candidate === null
            ? []
            : lots.flatMap((lot, index) =>
                  lot.batch_number.trim() !== '' &&
                  normalise(lot.batch_number) === normalise(candidate)
                      ? [index]
                      : [],
              );
    const matches = sameBatch.filter(
        (index) =>
            read.expiry === null ||
            lots[index]?.expiry_date.slice(0, 10) === read.expiry,
    );
    const lotWord = (indexes: number[]): string =>
        indexes.length === 1
            ? `lot ${indexes[0] + 1}`
            : `lots ${indexes.map((index) => index + 1).join(', ')}`;

    // A batch read without separators may have swallowed a following field: use it only when it matches exactly.
    if (candidate === null || (read.batch === null && matches.length === 0)) {
        return {
            message: [
                expiry ? `Box: ${expiry}.` : '',
                candidate === null
                    ? ''
                    : 'The batch could not be read from this code. Type it from the box.',
            ]
                .filter((part) => part !== '')
                .join(' '),
            warn: false,
        };
    }

    const box = `Box: batch ${candidate}${expiry ? `, ${expiry}` : ''}.`;

    if (matches.length > 0) {
        return {
            message: `${box} Matches ${lotWord(matches)}.`,
            warn: false,
        };
    }

    if (sameBatch.length > 0) {
        return {
            message: `${box} ${lotWord(sameBatch).replace(/^l/, 'L')} ${sameBatch.length === 1 ? 'has' : 'have'} batch ${candidate}, but a different expiry. Check the box.`,
            warn: true,
        };
    }

    return {
        message: `${box} No lot has this batch yet. Type it in yourself.`,
        warn: false,
    };
}
