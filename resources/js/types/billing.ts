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
export type BillingPage = {
    patient: { name: string; patientNumber: string };
    visit: {
        visitNumber: string;
        status: string;
        lockVersion: number;
        coverage: string;
        completedAt: string | null;
    };
    clinic: string;
    branch: string;
    oldOutstanding: Array<{
        invoiceNumber: string;
        amountSen: number;
        dueDate: string;
        url: string;
    }>;
    invoice: null | {
        publicId: string;
        number: string | null;
        status: string;
        lockVersion: number;
        currency: string;
        finalizedAt: string | null;
        correctionHold: boolean;
        state: BillingState;
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
    deferment: Responsibility | null;
    payments: Receipt[];
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
