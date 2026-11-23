// Classic only, CO-5: the Classic half of v2-dispensary-case.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Dispensary/Show.vue');

test('Classic Dispensary case imports nothing from V2', () => {
    assert.ok(!/components\/v2\/|from '\.\/V2\//.test(classic));
});

test('Classic Complete posts through caseAction', () => {
    assert.match(
        classic,
        /const completeCase = \(\) => \{\s+caseAction\('complete'\);/,
    );
});

test('Classic Return to Doctor posts through caseAction', () => {
    assert.match(classic, /@click="caseAction\('return-to-doctor'\)"/);
});

test('Classic scan to add (DSP-12): controls, live region and refusals', () => {
    assert.match(
        read('resources/js/components/inventory/ScanInput.vue'),
        /result\.status !== 'found' \|\| !result\.sku\.active\) \{\s+show\(scanMessage\(result, props\.context\), true\);/,
    );
});

test('the same refusal pressed twice scrolls and focuses again: the error watcher runs sync (Hakim)', async () => {
    const { ref, watch, nextTick } = await import('vue');
    const error = ref('Tick the allergy check first.');
    let sync = 0;
    let pre = 0;
    watch(error, (message) => message && sync++, { flush: 'sync' });
    watch(error, (message) => message && pre++);
    error.value = null;
    error.value = 'Tick the allergy check first.';
    await nextTick();
    assert.equal(sync, 1, 'sync runs for the second press');
    assert.equal(pre, 0, 'the default pre flush would miss it');
});
