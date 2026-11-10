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
    assert.match(
        row,
        /class="mt-1 text-\[11px\] break-words text-muted-foreground" data-testid="payment-recorded-by"/,
    );
});

test('the payment type carries the two new screen-only fields', () => {
    assert.ok(types.includes('receivedAt: string; recordedBy: string;'));
});
