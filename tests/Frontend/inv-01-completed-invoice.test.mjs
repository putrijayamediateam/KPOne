import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const invoice = read('resources/js/pages/Billing/Invoice.vue');
test('Invoice uses billed lines and existing documents, with no clinical content or unfinished money controls', () => {
    assert.match(invoice, /invoice\.financial\?\.lines/);
    assert.match(invoice, /OperationalTabs/);
    assert.match(invoice, /View consultation/);
    assert.match(invoice, /Print\s+document/);
    assert.match(invoice, /Visitation completed/);
    assert.doesNotMatch(invoice, /clinicalNote|clinical_note|vitals|diagnoses|quantityOrdered|dispensed|visit\.reason/);
    assert.doesNotMatch(invoice, /Add tax|Add Adjustment|Reservations|Pre-orders|Packages|e-Invoice|useForm|router\.(post|put|patch|delete)/);
});
test('PR 94 retains its full clinical-history destination and safe return link', () => {
    const directory = read('app/Domain/Clinical/Services/ClinicalEncounterDirectoryService.php');
    const history = read('resources/js/pages/Visits/History.vue');
    assert.match(directory, /route\('visits\.history', \['visit' => \$encounter->visit, 'from_visit' => \$current->visit->visit_number\]\)/);
    assert.match(history, /data-testid="ordered-vs-dispensed"/);
    assert.match(history, /data-testid="history-consultation"/);
    assert.match(history, /history\.links\.backLabel/);
});
