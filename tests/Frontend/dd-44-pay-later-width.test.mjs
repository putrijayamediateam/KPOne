import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Full-demo composition: a bounded board includes contextual help; the compact
// table keeps its primary action visible beside that help at desktop widths.
const page = readFileSync(
    new URL(
        '../../resources/js/pages/Billing/PayLaterCollections.vue',
        import.meta.url,
    ),
    'utf8',
);

const frame = readFileSync(
    new URL(
        '../../resources/js/components/billing/BillingBoardFrame.vue',
        import.meta.url,
    ),
    'utf8',
);

test('DD-44: collection composition uses the bounded demo frame and compact table', () => {
    assert.match(page, /<BillingBoardFrame\s+active="collect"/);
    assert.match(frame, /<PageFrame width="wide">/);
    assert.match(frame, /xl:grid-cols-\[minmax\(0,1fr\)_260px\]/);
    assert.doesNotMatch(page, /max-w-\[1600px\]/);
    assert.match(page, /min-width="680px"/);
    assert.match(page, /:columns="6"/);
});
