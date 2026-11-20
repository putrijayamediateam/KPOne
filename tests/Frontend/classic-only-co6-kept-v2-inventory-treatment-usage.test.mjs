// Classic only, CO-6: the Classic half of v2-inventory-treatment-usage.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Inventory/TreatmentUsage.vue');

test('page 4: Classic Treatment room usage imports no V2 code', () => {
    assert.ok(!classic.includes('components/v2/'));
});
