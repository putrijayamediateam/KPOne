import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const workspacePath = 'resources/js/components/billing/BillingWorkspace.vue';
const raw = read(workspacePath);
const billing = raw.replace(/\s+/g, ' ');

test('the billing workspace compiles with the recorded-by lines', () => {
    const descriptor = parse(raw, { filename: workspacePath }).descriptor;
    const result = compileTemplate({
        id: 'test-recorded-by',
        filename: workspacePath,
        source: descriptor.template?.content ?? '',
    });

    assert.deepEqual(result.errors, []);
});

test('every receipt layout shows the name only when the server sends it', () => {
    const guarded = billing.match(/v-if="receipt\.recordedBy"/g) ?? [];
    const lines =
        billing.match(/Recorded by\s*\{\{ receipt\.recordedBy \}\}/g) ?? [];

    assert.equal(guarded.length, 4);
    assert.equal(lines.length, 4);
});

test('the printed invoice and receipt never render a staff name', () => {
    const print = read('resources/js/pages/Billing/Print.vue');

    assert.ok(!print.includes('recordedBy'));
    assert.ok(!print.includes('Recorded by'));
});
