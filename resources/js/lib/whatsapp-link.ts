/** Deterministic syntax only; the authorised server route resolves the current number. */
export function whatsAppNumber(value: unknown): string | null {
    if (
        typeof value !== 'string' ||
        value.length > 128 ||
        !/^[+0-9 ().\p{Zs}-]+$/u.test(value) ||
        /\.{2,}/.test(value)
    ) {
        return null;
    }

    const compact = value.replace(/[ ().\p{Zs}-]/gu, '');
    const plus = compact.startsWith('+');
    let digits = plus ? compact.slice(1) : compact;

    if (!/^[0-9]+$/.test(digits)) {
        return null;
    }

    if (!plus && digits.startsWith('0')) {
        digits = '60' + digits.slice(1);
    }

    if (digits.startsWith('60')) {
        return /^60(?:11[0-9]{8}|1[0-9]{7,8}|3[0-9]{8}|[4-79][0-9]{7}|8[0-9]{7,8})$/.test(
            digits,
        )
            ? digits
            : null;
    }

    return plus && /^[1-9][0-9]{7,14}$/.test(digits) ? digits : null;
}
