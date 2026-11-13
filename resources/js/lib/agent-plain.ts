/**
 * Version 2 agent pages: staff-readable text. A branch or location code such as `SUNGAI_BESI` reads "Sungai Besi".
 * Only a value that is entirely an upper-case code is changed, so item names and sentences are left as they are.
 */
export const titleCaseCode = (code: string): string =>
    code
        .toLowerCase()
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');

export const plainValue = (value: string): string =>
    /^[A-Z]{3,}(?:_[A-Z]+)*$/.test(value) ? titleCaseCode(value) : value;

const sentence = (text: string): string =>
    text.charAt(0).toUpperCase() + text.slice(1);

const dataSources: Record<string, string> = {
    'clinic.day_summary': 'Clinic day',
    'insights.sales_summary': 'Sales summary',
    'insights.today': 'Insights today',
    'inventory.branch_balances': 'Branch stock balances',
    'inventory.dispensing_activity': 'Dispensing activity',
    'inventory.stock_counts': 'Stock counts',
    'inventory.stock_review': 'Stock review',
    'money.day_summary': 'Money for the day',
    'panel.claims_summary': 'Panel claims',
    'system.health': 'System health',
};

/** A data source code such as `inventory.stock_review` reads "Stock review". An unknown code is made readable, never shown raw. */
export const dataSourceLabel = (code: string): string =>
    dataSources[code] ??
    sentence(code.replaceAll('.', ' ').replaceAll('_', ' '));

/** A task or error code such as `inventory_review` or `data_source.refused` reads as a sentence. */
export const codeSentence = (code: string): string =>
    sentence(code.replaceAll('.', ' ').replaceAll('_', ' '));
