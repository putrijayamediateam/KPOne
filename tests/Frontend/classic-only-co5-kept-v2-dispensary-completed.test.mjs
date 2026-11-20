// Classic only, CO-5: the Classic half of v2-dispensary-completed.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Dispensary/Completed.vue');
const template = (source) => source.slice(source.indexOf('<template>'));

test('Classic Completed page imports nothing from V2', () => {
    assert.ok(!/\/V2\//.test(classic));
});

test('Classic Completed page keeps its links and the returns section', () => {
    for (const marker of [
        'href="/registration"',
        ':href="dispensary.billingUrl"',
        ':href="dispensary.labelUrl"',
        'target="_blank"',
        '<MedicineReturnsSection',
        'v-if="returns && returns.lines.length"',
    ]) {
        assert.ok(template(classic).includes(marker), `Classic ${marker}`);
    }
});

test('Classic returns section keeps its 44 px controls below 1024 (Hakim)', () => {
    const rule =
        'max-lg:[&_[data-slot=button]]:min-h-11 max-lg:[&_[data-slot=select-trigger]]:min-h-11 max-lg:[&_input:not([type=radio]):not([type=checkbox])]:min-h-11 max-lg:[&_select]:min-h-11 max-lg:[&_textarea]:min-h-11';
    assert.ok(classic.includes(rule), 'Classic carries the rule');
});
