import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (value) => value.replace(/\s+/g, ' ');
const history = squash(read('resources/js/pages/Visits/History.vue'));
const panel = squash(read('resources/js/pages/Clinical/Partials/ClinicalHistoryPanel.vue'));
const board = squash(read('resources/js/components/patient-board/PatientBoard.vue'));

test('the Invoices page shows what was ordered beside what was dispensed', () => {
    assert.match(history, /data-testid="ordered-vs-dispensed"/);
    assert.match(history, /data-testid="ordered-side"/);
    assert.match(history, /data-testid="dispensed-side"/);
    assert.match(history, /Not ordered by a doctor/);
    assert.match(history, /Removed by CA/);
    assert.match(history, /Added by CA/);
    assert.match(history, /Edited by CA/);
    assert.match(history, /Invoice · Visit/);
});

test('services show the CA confirmed quantity and the change label', () => {
    assert.match(history, /data-testid="service-change-label"/);
    assert.match(history, /Performed/);
});

test('the registration board names the page Invoices', () => {
    assert.match(board, />Invoices<\/Link/);
    assert.doesNotMatch(board, />Visit History</);
});

test("the doctor's previous consultation opens the Invoices page as its full page", () => {
    assert.match(panel, /selectedInvoiceUrl/);
    assert.match(panel, /:href="selectedInvoiceUrl \?\? selectedUrl"/);
    assert.match(panel, />Open full page</);
});
