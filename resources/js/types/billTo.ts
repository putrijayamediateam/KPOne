export type BillToRecipientData = {
    party_type: 'person' | 'company' | 'panel';
    display_name: string;
    company_name: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postcode: string | null;
    country_code: string | null;
};
export type BillToContext = {
    url: string;
    branchId: number;
    invoiceNumber: string | null;
    canManage: boolean;
};
export type BillToRevision = {
    id: string;
    kind: 'set' | 'changed' | 'cleared';
    recipient: BillToRecipientData | null;
    actor: string;
    occurredAt: string;
};
export type BillToPage = {
    current: BillToRecipientData | null;
    version: number;
    canManage: boolean;
    data: BillToRevision[];
    nextCursor: string | null;
};
