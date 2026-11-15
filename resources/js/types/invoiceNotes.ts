export type InvoiceNotesContext = {
    url: string;
    branchId: number;
    invoiceNumber: string;
    canAdd: boolean;
};
export type InvoiceNote = {
    id: string;
    kind: 'added' | 'corrected' | 'retracted';
    body: string | null;
    reasonCode: string | null;
    actor: string;
    occurredAt: string;
    resolved: boolean;
    resolution: string | null;
    canRetract: boolean;
    canCorrect: boolean;
};
export type InvoiceNotesPage = {
    data: InvoiceNote[];
    version: number;
    canAdd: boolean;
    nextCursor: string | null;
};
