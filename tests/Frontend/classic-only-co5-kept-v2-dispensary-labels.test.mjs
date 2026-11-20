// Classic only, CO-5: the Classic half of v2-dispensary-labels.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Dispensary/Labels.vue');

test('Classic Labels page imports nothing from V2', () => {
    assert.ok(!/\/V2\//.test(classic));
});
