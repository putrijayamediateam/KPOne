import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/pages/Dispensary/Index.vue', import.meta.url),
    'utf8',
).replace(/\s+/g, ' ');

test('DSP-27: three status tabs with counts, and no Ready to hand over tab (C1)', () => {
    assert.match(
        page,
        /label: 'To prepare', count: props\.list\.counts\.prepare/,
    );
    assert.match(page, /label: "Waiting for doctor's check",/);
    assert.match(page, /label: 'Done today', count: props\.list\.counts\.done/);
    assert.ok(!page.includes('Ready to hand over'));
});

test('DSP-27: the list is read only; rows open the case, and Done today reprints or views labels in a new tab', () => {
    assert.ok(!/router\.(post|put|patch|delete)\(/.test(page));
    assert.ok(!page.includes('useForm'));
    assert.match(page, /Open case/);
    assert.match(page, /'Reprint labels' : 'View labels'/);
    assert.match(page, /target="_blank" rel="noopener noreferrer"/);
});

test('DSP-27: row actions are outline buttons, and the doctor-check tab says who checks', () => {
    assert.ok(!page.includes('variant="secondary"'));
    assert.match(page, /The doctor checks these on the consultation page\./);
});

test('DSP-27: the list refreshes itself only while the browser tab is visible', () => {
    assert.match(page, /document\.visibilityState === 'visible'/);
    assert.match(page, /router\.reload\(\{ only: \['list'\] \}\)/);
});
