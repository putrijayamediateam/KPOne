// Classic only, CO-6: the Classic half of v2-inventory-holds-recalls.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const holdsClassic = read('resources/js/pages/Inventory/BatchHolds.vue');
const recallsClassic = read('resources/js/pages/Inventory/Recalls.vue');

test('page 7: Classic batch holds and recalls import no V2 code', () => {
    for (const classic of [holdsClassic, recallsClassic]) {
        assert.ok(!classic.includes('components/v2'));
    }
});
