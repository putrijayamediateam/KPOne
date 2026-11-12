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
/** A blank or whitespace-only batch from the server counts as no batch, so no broken "batch ," is ever shown. */
const present = (value: string | null | undefined): string | null =>
    value && value.trim() !== '' ? value.trim() : null;

export function boxLotHint(
    read: Gs1Read | null,
    lots: LotEntry[],
    formatDate: (value: string) => string,
): BoxLotHint | null {
    // A plain barcode carries no batch or expiry: nothing to add to the STK-9 "Scanned" mark.
    const candidate =
        present(read?.batch) ?? present(read?.batch_unconfirmed) ?? null;

    if (read === null || (candidate === null && read.expiry === null)) {
        return null;
    }

    const certain = present(read.batch) !== null;
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
    const typedExpiry = (index: number): string =>
        (lots[index]?.expiry_date ?? '').slice(0, 10);
    // DateField stays '' until a full real date is typed, so an empty expiry is "not typed yet", never "different".
    const matches = sameBatch.filter(
        (index) => read.expiry === null || typedExpiry(index) === read.expiry,
    );
    const conflicts =
        read.expiry === null
            ? []
            : sameBatch.filter(
                  (index) =>
                      typedExpiry(index) !== '' &&
                      typedExpiry(index) !== read.expiry,
              );
    const pending =
        read.expiry === null
            ? []
            : sameBatch.filter((index) => typedExpiry(index) === '');
    const lotWord = (indexes: number[]): string =>
        indexes.length === 1
            ? `lot ${indexes[0] + 1}`
            : `lots ${indexes.map((index) => index + 1).join(', ')}`;
    const capital = (text: string): string =>
        text.charAt(0).toUpperCase() + text.slice(1);
    const has = (indexes: number[]): string =>
        indexes.length === 1 ? 'has' : 'have';

    // A batch read without separators may have swallowed a following field: use it only when it matches exactly.
    if (candidate === null || (!certain && matches.length === 0)) {
        return {
            message: [
                expiry ? `Carton: ${expiry}.` : '',
                candidate === null
                    ? ''
                    : 'The batch could not be read from this code. Type it from the carton.',
            ]
                .filter((part) => part !== '')
                .join(' '),
            warn: false,
        };
    }

    const box = `Carton: batch ${candidate}${expiry ? `, ${expiry}` : ''}.`;
    const conflict =
        conflicts.length > 0
            ? `${capital(lotWord(conflicts))} ${has(conflicts)} batch ${candidate}, but a different expiry. Check the carton.`
            : '';

    if (matches.length > 0) {
        return {
            message: [box, `Matches ${lotWord(matches)}.`, conflict]
                .filter((part) => part !== '')
                .join(' '),
            warn: conflicts.length > 0,
        };
    }

    if (conflicts.length > 0) {
        return { message: `${box} ${conflict}`, warn: true };
    }

    if (pending.length > 0) {
        return {
            message: `${box} ${capital(lotWord(pending))} ${has(pending)} this batch; type its expiry.`,
            warn: false,
        };
    }

    return {
        message: `${box} No lot has this batch yet. Type it in yourself.`,
        warn: false,
    };
}
