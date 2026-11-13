/**
 * V2 money input: the typed text of an amount in ringgit, tidied without any floating-point maths (the text is only
 * re-written, never added up). Used by V2MoneyInput; the server stays the authority on what an amount may be.
 */
export type MoneyText = { value: string; valid: boolean };

export const MONEY_ERROR = 'Enter an amount such as 12.50.';

/**
 * What the box shows after the person leaves it. A leading "RM" (with or without a space after it) is dropped; "12"
 * becomes "12.00", "12.5" becomes "12.50", ".5" becomes "0.50". An empty box stays empty (never "0.00"), and a typed "0"
 * or "0.00" stays an amount.
 *
 * Paste rules, no guessing: commas are accepted only as thousands groups of three before a single decimal point
 * ("1,250.50" becomes "1250.50"). Everything else with a comma is refused ("1,25", "1.250,50", ",5", "1,2345"), and so are
 * inner spaces ("1 250"), a minus or plus sign, letters, two dots and more than two decimals ("1.234"). A refused text is
 * returned unchanged and not valid, so the person sees what they typed next to the message.
 */
export const tidyMoney = (typed: string): MoneyText => {
    const cleaned = typed.trim().replace(/^rm\s*/i, '');

    if (cleaned === '') {
        return { value: '', valid: true };
    }

    const grouped = /^\d{1,3}(?:,\d{3})+(?:\.\d{0,2})?$/.test(cleaned);
    const plain = cleaned.replace(/,/g, '');
    const match =
        grouped || !cleaned.includes(',')
            ? /^(\d*)(?:\.(\d{0,2}))?$/.exec(plain)
            : null;

    if (!match || (match[1] === '' && (match[2] ?? '') === '')) {
        return { value: typed, valid: false };
    }

    const whole = (match[1] || '0').replace(/^0+(?=\d)/, '');
    const cents = (match[2] ?? '').padEnd(2, '0');

    return { value: `${whole}.${cents}`, valid: true };
};
