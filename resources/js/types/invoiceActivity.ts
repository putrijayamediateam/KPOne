export type InvoiceActivityLink = { url: string; branchId: number };
export type InvoiceActivityEntry = {
    id: string;
    message: string;
    scope: 'Invoice' | 'Visit';
    amountSen: number | null;
    method: string | null;
    actor: string;
    occurredAt: string;
};
export type InvoiceActivityPage = {
    data: InvoiceActivityEntry[];
    nextCursor: string | null;
};
