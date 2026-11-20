// Classic only, CO-6: the Classic half of v2-inventory-lists-10b.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const pages = {
    terms: 'SupplierTerms',
    skus: 'Skus',
    requests: 'PurchaseRequests',
};
const classic = Object.fromEntries(
    Object.entries(pages).map(([k, f]) => [
        k,
        read(`resources/js/pages/Inventory/${f}.vue`),
    ]),
);

test('page 10b: Classic list pages import no V2 code', () => {
    for (const source of Object.values(classic)) {
        assert.ok(!source.includes('components/v2'));
    }
});
