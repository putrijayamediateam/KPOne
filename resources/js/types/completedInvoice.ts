import type { OutstandingBills } from '@/types/billing';
import type {
    VisitHistoryFinancial,
    VisitHistoryPage,
} from '@/types/visitHistory';

// An explicit, non-clinical contract; never pass VisitHistoryPage to Invoice.
export type CompletedInvoicePage = {
    patient: VisitHistoryPage['patient'];
    visit: Pick<
        VisitHistoryPage['visit'],
        'visitNumber' | 'branch' | 'coverage' | 'registeredAt' | 'completedAt'
    >;
    financial: VisitHistoryFinancial | null;
    outstanding: OutstandingBills | null;
    canSeeFinancial: boolean;
    canSeeLines: boolean;
    links: { back: string; consultation: string; billing: string | null };
};
