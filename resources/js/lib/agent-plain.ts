/**
 * Version 2 agent pages: staff-readable text. A branch or location code such as `SUNGAI_BESI` reads "Sungai Besi".
 * Only a value that is entirely an upper-case code is changed, so item names and sentences are left as they are.
 */
export const titleCaseCode = (code: string): string =>
    code
        .toLowerCase()
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');

export const plainValue = (value: string): string =>
    /^[A-Z]{3,}(?:_[A-Z]+)*$/.test(value) ? titleCaseCode(value) : value;
