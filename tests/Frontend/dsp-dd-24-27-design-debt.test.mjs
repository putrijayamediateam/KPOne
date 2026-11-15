import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const show = readFileSync(
    new URL('../../resources/js/pages/Dispensary/Show.vue', import.meta.url),
    'utf8',
);
const flat = show.replace(/\s+/g, ' ');

test('DD-25: the page title is "Dispensary" and only the patient card names the patient', () => {
    assert.ok(show.includes('<PageHeader'));
    assert.ok(show.includes('title="Dispensary"'));
    assert.ok(
        show.includes('description="Check each line, save it, then complete."'),
    );
    assert.equal(
        (show.match(/\{\{ dispensary\.patient\.name \}\}/g) ?? []).length,
        1,
    );
    assert.match(flat, /<h2[^>]*> \{\{ dispensary\.patient\.name \}\} <\/h2>/);
});

test('DD-26: batch options lead with the quantity and expiry, and a Selected line repeats the choice', () => {
    assert.ok(show.includes('on hand · exp ${formatDate(stock.expiryDate)}'));
    assert.ok(show.includes('`Selected: ${batchOptionText(stock, unit)}`'));
    assert.ok(
        show.includes(':aria-describedby="`batch-selected-${item.publicId}`"'),
    );
    assert.ok(show.includes('aria-describedby="batch-selected-add"'));
    assert.equal((show.match(/data-testid="batch-selected"/g) ?? []).length, 2);
    // Held batches still say why first, and stay disabled.
    assert.ok(
        show.includes('Held: ${formatInventoryReason(stock.holdReason)} · '),
    );
    assert.ok(show.includes(':disabled="stock.held"'));
    // A held batch is never shown as the selected choice (Hakim).
    assert.ok(show.includes("'No usable batch: every batch here is on hold.'"));
});

test('DD-27: the CA change chips use StatusBadge tokens, never a hand-styled pink chip', () => {
    assert.equal(show.includes('bg-pink-100/70'), false);
    assert.equal(
        (show.match(/status="ca_change"\s+tone="accent"/g) ?? []).length,
        2,
    );
});
