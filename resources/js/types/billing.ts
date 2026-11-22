import type { BillToContext } from '@/types/billTo';
import type { InvoiceActivityLink } from '@/types/invoiceActivity';
import type { InvoiceNotesContext } from './invoiceNotes';
export type OutstandingGroup = { count: number; totalSen: number; url: string };
export type OutstandingBills = {
    branchId: number;
    patient: OutstandingGroup | null;
    panel: OutstandingGroup | null;
};
export type OutstandingBillPage = {
    count: number;
    totalSen: number;
    currentPage: number;
    lastPage: number;
    data: Array<{
        invoiceNumber: string;
        visitDate: string;
        amountSen: number;
        reviewRequired: boolean;
        url: string;
    }>;
};

export type BillingState = {
    total: number;
    self_pay: number;
    panel: number;
    deferred: number;
    due_now: number;
};
export type Receipt = {
    publicId: string;
    number: string;
    amountSen: number;
    /** BIL-10: approved refunds after completion of this receipt. */
    refundedSen?: number;
    method: string;
    status: string;
    lockVersion: number;
    receivedAt: string;
    /** Screens only (BIL-2); never sent to prints. */
    recordedBy?: string;
};
export type Responsibility = {
    reason: string;
    panelName: string | null;
    memberReference: string | null;
    publicId: string;
    status: string;
    amountSen: number;
    lockVersion: number;
    remainingSen: number | null;
    dueDate: string | null;
};
/** Display only: the patient's amount owed is unchanged (BIL-1b). */
export type PanelShortfall = {
    shortfallSen: number;
    panelShareSen: number;
    acceptedSen: number;
    decision: 'rejected' | 'partially_accepted' | 'claim_reduced';
};
export type InvoiceAdjustmentSummary = {
    subtotalSen: number;
    adjustmentSen: number;
    activeId: string | null;
};
export type RefundEntry = {
    publicId: string;
    amountSen: number;
    method: string;
    reason: string;
    paidOutAt: string;
    recordedBy: string;
    state: 'waiting' | 'approved' | 'rejected';
    decidedBy: string | null;
    rejectReason: string | null;
    canDecide: boolean;
    recordedByYou: boolean;
};
export type RefundsContext = {
    can: { record: boolean; approve: boolean };
    reasons: Record<string, string>;
    afterCompletionReasons: Record<string, string>;
    rejectReasons: Record<string, string>;
    methods: Array<{ id: number; name: string }>;
    visitCompleted: boolean;
    receipts: Array<{
        publicId: string;
        number: string;
        method: string;
        amountSen: number;
        kind: 'reversal' | 'after_completion';
        refundableSen: number;
        canRecord: boolean;
        refunds: RefundEntry[];
    }>;
};
/** BIL-16: correcting a receipt recorded in error on a completed visit. */
export type CorrectionsContext = {
    invoiceLockVersion: number;
    methods: Array<{ code: string; name: string; requiresReference: boolean }>;
    receipts: Array<{
        publicId: string;
        number: string;
        method: string;
        amountSen: number;
        payLaterSen: number;
        lockVersion: number;
        refused: string | null;
    }>;
    corrections: Array<{
        publicId: string;
        original: string;
        replacement: string | null;
        receivedSen: number;
        reason: string;
        by: string;
        at: string;
    }>;
};
export type BillingPage = {
    billTo?: BillToContext | null;
    notes?: InvoiceNotesContext | null;
    activity: InvoiceActivityLink;
    /** BIL-35: the Panel claim button, for Panel Claims users only. */
    panelClaim?: PanelClaimLink | null;
    backLink: { label: string; href: string } | null;
    patient: { name: string; patientNumber: string; profileUrl: string | null };
    visit: {
        visitNumber: string;
        status: string;
        lockVersion: number;
        coverage: string;
        completedAt: string | null;
    };
    clinic: string;
    branch: string;
    outstanding: OutstandingBills | null;
    /** BIL-3: the branch pay-later collection list, for outstanding viewers only. */
    payLaterCollectionsUrl?: string | null;
    invoice: null | {
        publicId: string;
        number: string | null;
        status: string;
        lockVersion: number;
        currency: string;
        finalizedAt: string | null;
        correctionHold: boolean;
        state: BillingState;
        adjustment: InvoiceAdjustmentSummary;
        refundCreditSen: number;
        adjustmentControls: null | {
            url: string;
            add: boolean;
            reverse: boolean;
            override: boolean;
            threshold: {
                configured: boolean;
                limitSen: number | null;
                lockVersion: number;
            };
        };
        lines: Array<{
            type: string;
            name: string;
            unit: string;
            quantity: string;
            unitPriceSen: number;
            totalSen: number;
        }>;
    };
    panel: Responsibility | null;
    panelShortfall: PanelShortfall | null;
    /** PNL-26b: the Panel's limit per visit; a warning only. */
    panelVisitLimit?: {
        limitSen: number;
        totalSen: number;
        over: boolean;
    } | null;
    panelAdjustment?: PanelAdjustment | null;
    deferment: Responsibility | null;
    payments: Receipt[];
    /** BIL-6: reversed receipts of this visit and their refunds, for refund recorders and approvers. */
    refunds?: RefundsContext | null;
    corrections?: CorrectionsContext | null;
    /** BIL-17: voided invoices of this visit, kept visible after a void. */
    voidedInvoices?: Array<{
        number: string;
        totalSen: number;
        voidedAt: string | null;
        voidedBy: string;
        receipts: Array<{
            number: string;
            amountSen: number;
            method: string;
            status: string;
        }> | null;
    }>;
    issuedCopies?: IssuedCopy[] | null;
    methods: Array<{ code: string; name: string; requiresReference: boolean }>;
    panels: Array<{ id: number; name: string }>;
    can: Record<
        | 'build'
        | 'finalize'
        | 'pay'
        | 'panelPropose'
        | 'panelApprove'
        | 'deferPropose'
        | 'deferApprove'
        | 'reverse'
        | 'void'
        | 'complete'
        | 'print'
        | 'printInvoice',
        boolean
    >;
};

/** A sen amount without "RM ", with thousands separators and a true minus sign: "1,234.50", "−12.00". */
export const amount = (sen: number): string => {
    const abs = Math.abs(sen);
    const text = `${Math.trunc(abs / 100).toLocaleString('en-MY')}.${String(abs % 100).padStart(2, '0')}`;

    return sen < 0 ? `\u2212${text}` : text;
};
/** "RM 1,234.50"; negative amounts read "−RM 12.00" (BIL-9 fixed "RM -1.-50"). */
export const myr = (sen: number): string =>
    sen < 0 ? `\u2212RM ${amount(-sen)}` : `RM ${amount(sen)}`;
/** Money that never breaks across lines (a no-break space between RM and the number). */
export const myrNoBreak = (sen: number): string =>
    myr(sen).replace(/ /g, '\u00a0');
export const outstandingSen = (state: BillingState): number =>
    state.deferred + state.due_now;
export const toSen = (text: string): string | null => {
    const match = /^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/.exec(text.trim());

    return match
        ? String(
              BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0')),
          )
        : null;
};

/** BIL-5: one issued (frozen) version of an invoice or receipt. */
export type IssuedCopy = {
    publicId: string;
    type: 'invoice' | 'receipt';
    number: string;
    version: number;
    firstPrintedAs: 'original' | 'revised';
    issuedAt: string;
    issuedBy: string;
    prints: number;
    latest: boolean;
    /** PCA-1: false for a Panel patient's invoice copy (view only). */
    reprintable?: boolean;
    viewUrl?: string | null;
};

/** PCA-2: the Panel share as original + confirmed Panel claim adjustments, with the waiting request and history. */
export type PanelAdjustmentClaimRow = {
    number: string;
    url: string | null;
    statusKey: string;
    amountSen: number;
    acceptedSen: number | null;
    decided: boolean;
    reason: string | null;
};

export type PanelAdjustment = {
    invoiceNumber: string | null;
    invoicePayableSen: number;
    reasonsApplied: string[];
    claim:
        null | (PanelAdjustmentClaimRow & { changedAfterSubmission: boolean });
    supplementaryClaims: PanelAdjustmentClaimRow[];
    originalShareSen: number;
    adjustmentSen: number;
    shareSen: number;
    claimedSen: number;
    claimUrl: string | null;
    invoiceLockVersion: number;
    canRequest: boolean;
    requestUrl: string;
    from: 'billing' | 'claim';
    reasons: { value: string; label: string }[];
    waiting: null | {
        publicId: string;
        direction: 'add' | 'deduct';
        amountSen: number;
        reason: string;
        note: string | null;
        requestedBy: string;
        requestedAt: string | null;
        newShareSen: number;
        requestedByYou: boolean;
        lockVersion: number;
        canConfirm: boolean;
        canWithdraw: boolean;
        confirmUrl: string;
        withdrawUrl: string;
    };
    confirmed: {
        direction: 'add' | 'deduct';
        amountSen: number;
        reason: string;
        confirmedAt: string | null;
        requestedBy: string;
        confirmedBy: string;
        confirmedByRole: string | null;
        samePerson: boolean;
    }[];
};

/** PCA-2: a claim's kind, its links and its recorded pack versions. */
export type PanelAdjustmentClaim = {
    kind: 'original' | 'supplementary';
    number: string;
    amountSen: number;
    cancelled: boolean;
    decided: boolean;
    cancelledNote: string | null;
    reason: string | null;
    parent: null | {
        claimNo: string;
        number: string;
        url: string;
        amountSen: number;
        acceptedSen: number | null;
        statusKey: string;
    };
    supplementary: {
        claimNo: string;
        number: string;
        url: string;
        amountSen: number;
        statusKey: string;
        reason: string | null;
    }[];
    versions: {
        version: number;
        invoiceTotalSen: number;
        panelAdjustmentSen: number;
        panelShareSen: number;
        claimAmountSen: number;
        recordedAt: string;
    }[];
};

/** BIL-35: straight to the invoice's Panel claim, or Create claim (read-only navigation). */
export type PanelClaimLink = {
    kind: 'open' | 'create';
    label: string;
    href: string;
    claimNumber: string | null;
};
