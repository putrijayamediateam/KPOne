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
    rejectReasons: Record<string, string>;
    methods: Array<{ id: number; name: string }>;
    visitCompleted: boolean;
    receipts: Array<{
        publicId: string;
        number: string;
        method: string;
        amountSen: number;
        canRecord: boolean;
        refunds: RefundEntry[];
    }>;
};
export type BillingPage = {
    billTo?: BillToContext | null;
    notes?: InvoiceNotesContext | null;
    activity: InvoiceActivityLink;
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
    deferment: Responsibility | null;
    payments: Receipt[];
    /** BIL-6: reversed receipts of this visit and their refunds, for refund recorders and approvers. */
    refunds?: RefundsContext | null;
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
        | 'print',
        boolean
    >;
};

export const myr = (sen: number): string =>
    `RM ${Math.trunc(sen / 100).toLocaleString('en-MY')}.${String(sen % 100).padStart(2, '0')}`;
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
