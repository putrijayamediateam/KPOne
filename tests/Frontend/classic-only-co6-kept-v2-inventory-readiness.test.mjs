// Classic only, CO-6: the Classic half of v2-inventory-readiness.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const readinessClassic = read('resources/js/pages/Inventory/Readiness.vue');
const importClassic = read('resources/js/pages/Inventory/OpeningImport.vue');

test('page 9: Classic Pilot readiness imports no V2 code', () => {
    for (const classic of [readinessClassic, importClassic]) {
        assert.ok(!classic.includes('components/v2'));
    }
});
