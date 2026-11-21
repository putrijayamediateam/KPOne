import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-53 (Billing part): no screen text under 12 px on the Billing pages. The
// print layout (Billing/Print.vue) keeps its own sizes on purpose.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('DD-53: Billing screens use at least text-xs (12 px)', () => {
    for (const path of [
        'resources/js/components/billing/BillingWorkspace.vue',
        'resources/js/components/billing/InvoiceActivityDrawer.vue',
        'resources/js/components/billing/OutstandingBills.vue',
        'resources/js/pages/Billing/Invoice.vue',
    ]) {
        assert.doesNotMatch(read(path), /text-\[(8|9|10|11)px\]/, path);
    }
});
