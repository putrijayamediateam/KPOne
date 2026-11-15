import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/PanelInvoices/Index.vue');
const service = read(
    'app/Domain/Visit/Billing/Services/PanelInvoiceReportService.php',
);
const navigation = read('resources/js/lib/workspace-navigation.ts');

test('Panel invoices shows nothing until a Panel is chosen and offers a CSV download', () => {
    assert.match(page, /Apply a date range and a Panel to display data/);
    assert.match(page, /Download CSV/);
    assert.match(page, /Reset filters/);
    assert.match(page, /v-if="can\.clinicalFilters"/);
    assert.match(navigation, /href: '\/panel-invoices'/);
});

test('The report is permissioned, branch scoped, capped, audited without row data and formula-safe', () => {
    assert.match(service, /'panel\.invoices\.export\.branch'/);
    assert.match(service, /MAX_ROWS = 5000/);
    assert.match(service, /panel_invoices\.exported/);
    assert.match(service, /safeCell/);
    assert.match(service, /'c\.branch_id', \$branch->id/);

    for (const clinical of ['diagnosis_text', 'medicine_name_snapshot']) {
        assert.doesNotMatch(service, new RegExp(clinical));
    }
});
