import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const pagePath = 'resources/js/pages/Billing/CashClosings.vue';
const raw = read(pagePath);
const page = raw.replace(/\s+/g, ' ');
const terminal = read(
    'resources/js/pages/PaymentReconciliations/Index.vue',
).replace(/\s+/g, ' ');

test('the daily cash closing page compiles', () => {
    const descriptor = parse(raw, { filename: pagePath }).descriptor;
    const result = compileTemplate({
        id: 'test-cash-closing',
        filename: pagePath,
        source: descriptor.template?.content ?? '',
    });

    assert.deepEqual(result.errors, []);
});

test('staff count takings only, against expected cash, with a reason for any difference', () => {
    assert.ok(page.includes('title="Daily cash closing"'));
    assert.ok(page.includes('Take out the opening float first'));
    assert.ok(page.includes('data-testid="cash-expected"'));
    assert.ok(page.includes('Reversed receipts are left out'));
    assert.ok(page.includes('v-if="difference !== null && difference !== 0"'));
    assert.ok(page.includes('Reason for the difference'));
    assert.ok(page.includes(".post('/cash-closings'"));
    assert.ok(page.includes('idempotency_key: crypto.randomUUID()'));
});

test('approve and return are offered only where the server allows the decision', () => {
    assert.ok(page.includes('v-if="closing.canDecide"'));
    assert.ok(
        page.includes('`/cash-closings/${closing.publicId}/${decision}`'),
    );
    assert.ok(page.includes('Approve closing'));
    assert.ok(page.includes('Return for recount'));
    assert.ok(
        page.includes('You counted this cash. Someone else must approve'),
    );
});

test('Terminal Reconciliation links to the daily cash closing', () => {
    assert.ok(terminal.includes('href="/cash-closings"'));
    assert.ok(terminal.includes('Daily cash closing'));
});
