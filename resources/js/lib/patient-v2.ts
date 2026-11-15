import type { PatientIdentifier } from '@/types';

/**
 * Version 2 patient page helpers. The masks repeat the server's rule (`PatientIdentityService::maskIdentifier`):
 * an NRIC is "******-**-NNNN", any other identifier "••••NNNN". Only used where a page must not show the full value
 * (the Correct dialog); nothing here decides a permission.
 */
export const identifierName = (type: string) =>
    type === 'nric' ? 'NRIC' : type.charAt(0).toUpperCase() + type.slice(1);

export const lastFour = (value: string) => value.slice(-4);

export const maskedIdentifier = (identifier: PatientIdentifier) =>
    identifier.type === 'nric'
        ? `******-**-${lastFour(identifier.displayValue)}`
        : `••••${lastFour(identifier.displayValue)}`;

export const spokenIdentifier = (identifier: PatientIdentifier) =>
    `${identifierName(identifier.type)}, ending in ${lastFour(identifier.displayValue)}`;

export const titleCase = (value: string) =>
    value.charAt(0).toUpperCase() + value.slice(1).replaceAll('_', ' ');

/**
 * Age in whole years on `today`, by the same rule as the guardian check (`knownMinor`): year difference, less one
 * until the birthday has passed. Only for a real, non-future "YYYY-MM-DD"; otherwise null.
 */
export const ageOn = (
    dob: unknown,
    today = new Date().toISOString().slice(0, 10),
): number | null => {
    if (typeof dob !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(dob)) {
        return null;
    }

    const date = new Date(`${dob}T00:00:00Z`);

    if (
        Number.isNaN(date.getTime()) ||
        date.toISOString().slice(0, 10) !== dob ||
        dob > today
    ) {
        return null;
    }

    return (
        Number(today.slice(0, 4)) -
        Number(dob.slice(0, 4)) -
        (today.slice(5) < dob.slice(5) ? 1 : 0)
    );
};
