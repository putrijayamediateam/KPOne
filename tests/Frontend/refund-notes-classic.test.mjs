import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Classic Refunds: one explainer at a time. The reversed-receipt note is for an
// open visit only; it must not also show on a completed visit when the Correct a
// receipt panel is absent (it was tied with v-else to that panel's pointer).

const card = readFileSync(
    new URL(
        '../../resources/js/components/billing/ReceiptRefunds.vue',
        import.meta.url,
    ),
    'utf8',
);
const flat = card.replace(/\s+/g, ' ');

test('the reversed-receipt note shows only while the visit is open', () => {
    assert.match(
        flat,
        /<p v-if="!refunds\.visitCompleted" class="text-xs text-muted-foreground" data-testid="refund-reversed-note" > Record money handed back to the patient for a reversed receipt\./,
    );
    assert.doesNotMatch(
        flat,
        /<p v-else class="text-xs text-muted-foreground"> Record money handed back/,
    );
});

test('the completed-visit note and the corrections pointer keep their own conditions', () => {
    assert.match(
        flat,
        /<p v-if="refunds\.visitCompleted" class="text-xs text-muted-foreground" data-testid="refund-after-completion-note" >/,
    );
    assert.match(
        flat,
        /<p v-if="refunds\.visitCompleted && correctionsBelow" class="text-xs text-muted-foreground" data-testid="refund-corrections-pointer" >/,
    );
});
