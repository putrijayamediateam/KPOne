/** Normalise only a phone already displayed in full. No lookup or country guessing.
 * WA-01 uses the owner's explicit 9–10 digit local Malaysian-mobile budget.
 */
export function whatsAppNumber(displayedPhone: unknown): string | null {
    if (typeof displayedPhone !== 'string' || displayedPhone.length > 128) {
        return null;
    }

    // Reject masks, controls, URLs and multiple-number delimiters before stripping
    // approved separators. Repeated dots are treated as an omission/mask.
    if (
        !/^[+0-9 ().\p{Zs}-]+$/u.test(displayedPhone) ||
        /\.{2,}/.test(displayedPhone)
    ) {
        return null;
    }

    const compact = displayedPhone.replace(/[ ().\p{Zs}-]/gu, '');
    const hasPlus = compact.startsWith('+');
    const digits = hasPlus ? compact.slice(1) : compact;

    if (!/^[0-9]+$/.test(digits)) {
        return null;
    }

    if (!hasPlus && /^01[0-9]{7,8}$/.test(digits)) {
        return '6' + digits;
    }

    if (digits.startsWith('60')) {
        return /^601[0-9]{7,8}$/.test(digits) ? digits : null;
    }

    return hasPlus && /^[1-9][0-9]{7,14}$/.test(digits) ? digits : null;
}
