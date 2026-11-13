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
