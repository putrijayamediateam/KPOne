// STK-48: words and whole-number money helpers for requests for quotation. The server decides every rule and
// ranking; these only format what it sends, plus the live "= RM 0.4000 per tablet" hint on the quote form.

export type RoundState =
    'waiting' | 'quotes_in' | 'chosen' | 'converted' | 'cancelled';
export type RfqStatus =
    'draft' | 'sent' | 'quoted' | 'chosen' | 'not_chosen' | 'cancelled';
type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger';

export const roundStateLabel: Record<RoundState, string> = {
    waiting: 'Waiting for quotes',
    quotes_in: 'Quotes in',
    chosen: 'Quote chosen',
    converted: 'Order made',
    cancelled: 'Cancelled',
};
// Daniel: only Chosen is in the success tone.
export const roundStateTone: Record<RoundState, Tone> = {
    waiting: 'neutral',
    quotes_in: 'neutral',
    chosen: 'success',
    converted: 'neutral',
    cancelled: 'neutral',
};
export const rfqStatusLabel: Record<RfqStatus, string> = {
    draft: 'Not sent',
    sent: 'Sent',
    quoted: 'Quote in',
    chosen: 'Chosen',
    not_chosen: 'Not chosen',
    cancelled: 'Cancelled',
};
export const rfqStatusTone: Record<RfqStatus, Tone> = {
    draft: 'neutral',
    sent: 'neutral',
    quoted: 'neutral',
    chosen: 'success',
    not_chosen: 'neutral',
    cancelled: 'neutral',
};
export const chooseReasons: Record<string, string> = {
    faster_delivery: 'Faster delivery',
    preferred_supplier: 'Preferred supplier',
    stock_availability: 'Stock availability',
    other: 'Other',
};
export const cancelReasons: Record<string, string> = {
    not_needed: 'No longer needed',
    supplier_no_reply: 'Supplier did not reply',
    entered_wrongly: 'Entered wrongly',
    other: 'Other',
};
export const tieBreakWords: Record<string, string> = {
    faster_delivery: 'faster delivery',
    lower_number: 'lower RFQ number',
};

/** Whole sen to "RM 1,234.50", with no float maths. */
export const rm = (sen: number): string => {
    const ringgit = String(Math.trunc(Math.abs(sen) / 100)).replace(
        /\B(?=(\d{3})+(?!\d))/g,
        ',',
    );

    return `${sen < 0 ? '−' : ''}RM ${ringgit}.${String(Math.abs(sen) % 100).padStart(2, '0')}`;
};

/** Hundredths of a sen to "RM 0.4000". */
export const rmFour = (hundredthSen: number): string =>
    `RM ${Math.trunc(hundredthSen / 10000)}.${String(hundredthSen % 10000).padStart(4, '0')}`;

/** "12.50" to 1250 sen, or null when it is not a price with at most two decimals. */
export const priceSen = (text: string): number | null => {
    const match = /^(\d{1,6})(?:\.(\d{1,2}))?$/.exec(text.trim());

    return match
        ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
        : null;
};

/** "30" or "2.5" to thousandths of a stock unit, or null. */
export const packThousandths = (text: string): number | null => {
    const match = /^(\d{1,6})(?:\.(\d{1,3}))?$/.exec(text.trim());

    if (!match) {
        return null;
    }

    const value =
        Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));

    return value > 0 ? value : null;
};

/** The live hint under a quote line: the pack price over the pack size, rounded half up to hundredths of a sen. */
export const unitHint = (
    price: string,
    pack: string,
    stockUnit: string,
): string | null => {
    const sen = priceSen(price);
    const thousandths = packThousandths(pack);

    if (sen === null || thousandths === null) {
        return null;
    }

    const hundredth = Math.floor(
        (2 * sen * 100000 + thousandths) / (2 * thousandths),
    );

    return `= ${rmFour(hundredth)} per ${stockUnit}`;
};
