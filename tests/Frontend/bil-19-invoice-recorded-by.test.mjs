import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-19: the completed-visit Invoice page shows when each receipt was received and who recorded it.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );
const invoice = read('resources/js/pages/Billing/Invoice.vue');
const types = read('resources/js/types/visitHistory.ts');

test('each receipt row shows the received time and a muted Recorded by line', () => {
    const row = invoice
        .split('data-testid="invoice-payments"')[1]
        .split('</ul>')[0];
    const order = [
        '{{ payment.receiptNumber }}',
        '{{ payment.receivedAt }}',
        'payment.status',
        'payment-refunded',
        'payment-recorded-by',
    ];
    let last = -1;

    for (const marker of order) {
        const at = row.indexOf(marker);
        assert.ok(at > last, `${marker} is in order`);
        last = at;
    }

    assert.ok(row.includes('Recorded by {{ payment.recordedBy }}'));
    const line = row
        .split('data-testid="payment-recorded-by"')[0]
        .split('<p')
        .at(-1);

    for (const cls of ['text-[11px]', 'break-words', 'text-muted-foreground']) {
        assert.ok(line.includes(cls), cls);
    }

    assert.ok(!line.includes('v-if'), 'the line is always shown');
});

test('the payment type carries the two new screen-only fields', () => {
    assert.ok(types.includes('receivedAt: string; recordedBy: string;'));
});
