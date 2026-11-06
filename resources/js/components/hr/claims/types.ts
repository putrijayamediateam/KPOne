// HR-6 staff claims (HR-PLAN 13.6). Shared shapes for the page and its components.
export type Option = { value: string; label: string };

export type CategoryOption = Option & {
    needsReceipt: boolean;
    perClaimLimitSen: number | null;
    monthlyLimitSen: number | null;
};

export type Receipt = {
    id: string;
    kind: 'pdf' | 'jpeg' | 'png';
    url: string;
    label: string;
};

export type ClaimStatus =
    'waiting' | 'approved' | 'rejected' | 'withdrawn' | 'paid';

export type ClaimRow = {
    id: string;
    number: string;
    person: string;
    branch: string;
    category: string;
    spentOn: string;
    amountSen: number;
    amount: string;
    purpose: string | null;
    status: ClaimStatus;
    statusWords: string;
    rejectReason: string | null;
    rejectCode: string | null;
    rejectNote: string | null;
    decidedBy: string | null;
    paid: { paidOn: string; method: string; last4: string | null } | null;
    reversed: { reason: string; on: string } | null;
    waitingFor: string | null;
    canWithdraw: boolean;
    canResend: boolean;
    lockVersion: number;
    receipts: Receipt[];
};

export type ApprovalRow = ClaimRow & {
    warnings: string[];
    monthTotalSen: number;
    perClaimLimitSen: number | null;
    monthlyLimitSen: number | null;
    refusal: string | null;
};

export type PayRow = ClaimRow & { refusal: string | null };

/** Approved and Paid are both success; the words tell them apart (Daniel). None is red. */
export const statusTone = (status: ClaimStatus): 'neutral' | 'success' =>
    status === 'approved' || status === 'paid' ? 'success' : 'neutral';

/** "Paid 6 Nov 2026 · Bank transfer · Ref •••• 4321" */
export const paidLine = (row: ClaimRow): string =>
    row.paid
        ? [
              `Paid ${row.paid.paidOn}`,
              row.paid.method,
              row.paid.last4 ? `Ref •••• ${row.paid.last4}` : '',
          ]
              .filter((p) => p)
              .join(' · ')
        : '';
