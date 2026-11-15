import type { CountryCode } from 'libphonenumber-js';
import {
    getCountries,
    parsePhoneNumberFromString,
} from 'libphonenumber-js/max';

export function phoneError(
    value: unknown,
    country: string = 'MY',
    required = true,
): string {
    if (
        value === null ||
        value === undefined ||
        value === '' ||
        (typeof value === 'string' && value.replace(/ /g, '') === '')
    ) {
        return required ? 'Phone number is required.' : '';
    }

    const invalid = 'Enter a valid phone number for the selected country.';

    if (
        typeof value !== 'string' ||
        value.length > 64 ||
        /[^ +()0-9-]/.test(value)
    ) {
        return invalid;
    }

    if (!getCountries().includes(country as CountryCode)) {
        return 'Check the country code and phone number.';
    }

    const compact = value.replace(/[ ()-]/g, '');

    if (!/^\+?[0-9]+$/.test(compact)) {
        return invalid;
    }

    const parsed = parsePhoneNumberFromString(compact, country as CountryCode);

    if (!parsed?.isValid()) {
        return invalid;
    }

    if (compact.startsWith('+') && parsed.number !== compact) {
        return 'Check the country code and phone number.';
    }

    return '';
}

export function identityError(
    type: string,
    value: string,
    issuer: string,
): string {
    if (type === 'nric') {
        const normalized = value.replace(/[ -]/g, '');

        return normalized.length === 12 && /^[0-9]+$/.test(normalized)
            ? ''
            : 'IC number must have 12 digits.';
    }

    if (type !== 'passport') {
        return 'Choose IC number or Passport.';
    }

    if (!/^[A-Z]{2}$/.test(normalizePassportIssuer(issuer))) {
        return 'Passport issuing country is required.';
    }

    const normalized = trimPhpWhitespace(value.normalize('NFKC'))
        .replace(/[\p{Z}\u0009-\u000d\u0085]/gu, '')
        .toUpperCase();

    if (normalized.length < 3) {
        return 'Passport number is incomplete.';
    }

    return /^[A-Z0-9][A-Z0-9-]{2,31}$/.test(normalized)
        ? ''
        : 'Check the Passport number format.';
}

// Match the released PHP NFKC + PCRE Unicode whitespace normalization. JS \s
// differs: it includes BOM (U+FEFF), but omits NEXT LINE (U+0085).
function trimPhpWhitespace(value: string): string {
    const characters = ' \n\r\t\v\0';
    let start = 0;
    let end = value.length;

    while (start < end && characters.includes(value[start])) {
        start++;
    }

    while (end > start && characters.includes(value[end - 1])) {
        end--;
    }

    return value.slice(start, end);
}

function normalizePassportIssuer(value: string): string {
    return trimPhpWhitespace(
        value.normalize('NFKC').replace(/[\p{Z}\u0009-\u000d\u0085]+/gu, ' '),
    ).toUpperCase();
}

export function identifierIssuer(type: string, issuer: string): string {
    return type === 'nric' ? 'MY' : issuer;
}

export function phoneInputError(
    value: string,
    country: string,
    required: boolean,
    unchangedValue?: string,
): string {
    return unchangedValue !== undefined && value === unchangedValue
        ? ''
        : phoneError(value, country, required || unchangedValue !== undefined);
}

export function focusInvalidField(): void {
    requestAnimationFrame(() =>
        document.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus(),
    );
}
