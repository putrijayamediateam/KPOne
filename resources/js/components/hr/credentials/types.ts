// HR-5a credential register types (HR-PLAN 12.2 and 12.6).
import { dateLabel } from '@/components/hr/records/types';

export type StatusCode =
    | 'valid'
    | 'expiring'
    | 'expired'
    | 'no_expiry'
    | 'not_recorded'
    | 'not_required'
    | 'done'
    | 'not_done';

export type CredentialStatus = {
    code: StatusCode;
    words: string;
    short: string;
};

export type CredentialTypeHead = {
    typeId: string;
    code: string;
    name: string;
    expires: boolean;
    healthItem: boolean;
    warnFromDays: number;
    roles: string[];
    version: number;
};

export type RecordView = {
    id: string;
    version: number;
    issuedOn: string | null;
    expiresOn: string | null;
    issuingBody: string | null;
    referenceLast4: string | null;
    healthDone: boolean | null;
    documentId: string | null;
    lockVersion: number;
};

export type Card = CredentialTypeHead & {
    required: boolean;
    status: CredentialStatus;
    inForce: RecordView | null;
    waiting: (RecordView & { enteredBy: string; enteredAt: string }) | null;
    rejected: { reason: string; at: string | null } | null;
};

export type Option = { value: string; label: string };

/** "Expires in 21 days · 27 Nov 2026" (Daniel): the time in the words, the date beside them. */
export const statusLine = (
    status: CredentialStatus,
    record: RecordView | null,
): string => {
    if (status.code === 'done' && record?.issuedOn) {
        return `Done · ${dateLabel(record.issuedOn)}`;
    }

    if (
        ['valid', 'expiring', 'expired'].includes(status.code) &&
        record?.expiresOn
    ) {
        return `${status.words} · ${dateLabel(record.expiresOn)}`;
    }

    return status.words;
};

/** Warning tone for Expiring and Expired, never red; everything else neutral, Valid and Done success. */
export const statusTone = (
    code: StatusCode,
): 'neutral' | 'success' | 'warning' =>
    code === 'expiring' || code === 'expired'
        ? 'warning'
        : code === 'valid' || code === 'done' || code === 'no_expiry'
          ? 'success'
          : 'neutral';
