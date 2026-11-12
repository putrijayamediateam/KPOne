import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Inventory/PurchaseRequests.vue');
const reorder = read('resources/js/pages/Inventory/ReorderSuggestions.vue');
const panel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);

// STK-18: a CA or CA Supervisor asks for stock; another person converts it into a draft purchase order.
test('STK-18: sending a request uses a retry key and a neutral summary line', () => {
    assert.match(page, /idempotency_key: send\.key/);
    assert.match(page, /lines will be sent for review\./);
    // Daniel: neutral, not amber.
    assert.match(
        page,
        /class="rounded-md border bg-muted p-2 text-sm"\s+data-testid="purchase-request-summary"/,
    );
    assert.match(page, /<OperationalTabs/);
});

test('STK-18: the requester never sees Convert or Reject on their own request', () => {
    assert.match(
        page,
        /v-if="request\.requestedByYou"[\s\S]{0,120}data-testid="purchase-request-own"[\s\S]{0,80}You sent this request, so another person must\s+review\s+it\./,
    );
    assert.match(
        page,
        /v-if="request\.requestedByYou && requests\.can\.request"/,
    );
});

test('STK-18: converting shows the asked quantity beside the editable one', () => {
    assert.match(
        page,
        /Asked: \{\{ line\.asked \}\} \{\{ line\.stockUnit \}\}/,
    );
    assert.match(page, /v-model="convert\.quantities\[line\.id\]"/);
    assert.match(page, /lock_version: request\.lockVersion/);
    // The converted request keeps both: Asked and Ordered.
    assert.match(page, />\s*Ordered\s*</);
});

test('STK-18: Reorder suggestions hand ticked rows of one location to a new request', () => {
    assert.match(reorder, /suggestions\.canRequest/);
    assert.match(reorder, /router\.get\('\/inventory\/purchase-requests'/);
    assert.match(reorder, /A request is for one location\./);
    assert.match(reorder, />Request these</);
});

test('STK-18: a draft purchase order names the request it came from', () => {
    assert.match(panel, /From purchase request \{\{ row\.fromRequest \}\}/);
});
