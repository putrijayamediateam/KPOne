/**
 * The Clinic management "Find a page" rule (Classic hub): narrow the links the server already listed. Every typed word
 * must be in the page's title, its description or its card's title (case does not matter), so "stock count", "refund"
 * or "panel" finds what it should. It only filters: it never adds a link, so a page the person's permissions hide
 * cannot appear.
 */
export type HubSearchLink = { title: string; description: string };

/**
 * Hidden extra words for the search, keyed by the page's title exactly as ClinicManagementNavigation.php sends it, so a
 * common term finds the right page ("refund" finds Finance / Billing, "stock count" finds Inventory). They are never
 * shown. They ride on the links the server already filtered by permission: a page the person cannot open is not in the
 * list, so its words are never searched. Owner-approved list, 2026-11-16. A test fails if a key is not a real hub title.
 */
export const HUB_EXTRA_WORDS: Record<string, string[]> = {
    Inventory: [
        'stock count',
        'count',
        'cycle count',
        'write-off',
        'expiry',
        'expired',
        'receive',
        'receiving',
        'delivery',
        'transfer',
        'supplier return',
        'batch',
        'barcode',
        'scan',
        'opening balance',
        'reorder',
        'replenish',
        'controlled drug',
    ],
    'Returns to check': [
        'return',
        'returned medicine',
        'quarantine',
        'dispose',
    ],
    'Finance / Billing': [
        'refund',
        'invoice',
        'receipt',
        'payment',
        'reverse',
        'void',
        'discount',
        'adjustment',
        'surcharge',
        'pay later',
    ],
    'Bills to collect': [
        'owing',
        'owed',
        'unpaid',
        'debt',
        'overdue',
        'outstanding',
        'pay later',
    ],
    'Daily cash closing': [
        'cash count',
        'cash up',
        'close the day',
        'takings',
        'till',
    ],
    'Terminal Reconciliation': ['card terminal', 'settlement', 'card payment'],
    'Payment Methods': ['cash', 'card', 'QR pay', 'e-wallet', 'bank transfer'],
    'Billing Approval Limits': [
        'discount limit',
        'approval limit',
        'pay later limit',
    ],
    Registration: [
        'register',
        'walk-in',
        'front desk',
        'queue number',
        'new patient',
    ],
    Consultation: ['doctor', 'call in', 'hold', 'queue'],
    'QR Intake': ['check-in', 'QR', 'pending patients'],
    'Patient Records': ['patient', 'search patient', 'duplicate', 'merge'],
    'Medicine Catalogue': [
        'drug',
        'medicine',
        'controlled',
        'allergy',
        'barcode',
    ],
    'Clinical Service Catalogue': [
        'procedure',
        'treatment',
        'service',
        'tariff',
    ],
    Pricing: ['price list', 'price book', 'publish price', 'charge'],
    'Medicine details': [
        'dosage',
        'frequency',
        'route',
        'instruction',
        'precaution',
        'unit',
    ],
    Suppliers: ['vendor', 'supplier'],
    'Panel Claims': ['insurance', 'TPA', 'claim', 'submit'],
    'Panel payments': ['remittance', 'payment advice', 'statement'],
    'Correct a Panel payment': ['reverse payment', 'wrong payment'],
    'Panel Responsibility': ['guarantee letter', 'coverage', 'approval'],
    'Transaction Detail': ['receipts', 'transactions', 'payments report'],
    'Daily summary': ['sales', 'takings', 'cash', 'non-cash'],
    Insights: ['dashboard', 'analytics', 'sales', 'patients report'],
    Staff: ['employee', 'account', 'role', 'branch assignment', 'deactivate'],
    'Access Control': ['permission', 'roles'],
    'Audit logs': ['history', 'who changed', 'activity'],
    'KPOne HR attendance': ['clock in', 'clock out', 'timesheet', 'overtime'],
    'KPOne HR leave': ['annual leave', 'sick leave', 'time off'],
    'KPOne HR roster': ['shift', 'schedule', 'rota'],
    'KPOne HR payroll': ['payslip', 'salary', 'EPF', 'SOCSO'],
    'KPOne HR staff claims': ['expenses', 'reimbursement'],
    'Clinic compliance': ['fridge', 'temperature', 'licence'],
    'TV Screen': ['television', 'waiting room', 'display'],
    'Queue Display settings': [
        'television',
        'waiting room',
        'rooms',
        'posters',
    ],
    'Approval inbox': ['AI', 'assistant', 'suggestions'],
    'All agents': ['AI', 'assistant', 'suggestions'],
    'Quick answers': ['AI', 'assistant', 'suggestions'],
};

/** The hidden words for a page, or none. */
export const extraWordsFor = (title: string): string =>
    (HUB_EXTRA_WORDS[title] ?? []).join(' ');

export const searchWords = (query: string): string[] =>
    query.trim().toLowerCase().split(/\s+/).filter(Boolean);

export const hubLinkMatches = (
    words: string[],
    groupTitle: string,
    link: HubSearchLink,
): boolean => {
    const text =
        `${link.title} ${link.description} ${groupTitle} ${extraWordsFor(link.title)}`.toLowerCase();

    return words.every((word) => text.includes(word));
};
