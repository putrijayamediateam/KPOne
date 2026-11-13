/**
 * V2 money input: the typed text of an amount in ringgit, tidied without any floating-point maths (the text is only
 * re-written, never added up). Used by V2MoneyInput; the server stays the authority on what an amount may be.
 */
export type MoneyText = { value: string; valid: boolean };

export const MONEY_ERROR = 'Enter an amount such as 12.50.';

/**
 * What the box shows after the person leaves it. Spaces, thousands commas and a leading "RM" are dropped; "12" becomes
 * "12.00", "12.5" becomes "12.50", ".5" becomes "0.50". An empty box stays empty (never "0.00"), and a typed "0" or
 * "0.00" stays an amount. Anything that is not an amount (letters, a minus sign, two dots, more than two decimals) is
 * returned unchanged and not valid, so the person sees what they typed next to the message.
 */
export const tidyMoney = (typed: string): MoneyText => {
    const cleaned = typed
        .trim()
        .replace(/^rm\s*/i, '')
        .replace(/[\s,]/g, '');

    if (cleaned === '') {
        return { value: '', valid: true };
    }

    const match = /^(\d*)(?:\.(\d{0,2}))?$/.exec(cleaned);

    if (!match || (match[1] === '' && (match[2] ?? '') === '')) {
        return { value: typed, valid: false };
    }

    const whole = (match[1] || '0').replace(/^0+(?=\d)/, '');
    const cents = (match[2] ?? '').padEnd(2, '0');

    return { value: `${whole}.${cents}`, valid: true };
};
