import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/pages/Inventory/Recalls.vue', import.meta.url),
    'utf8',
);

// STK-25: a recall blocks named batches at every branch; only a person other than the starter lifts it.
test('STK-25: starting a recall sends a retry key and says how many batches it blocks', () => {
    assert.match(page, /idempotency_key: start\.key/);
    assert.match(page, /batch_public_ids: start\.batches/);
    // Daniel: "N batches at M branches will be blocked" above Start recall.
    assert.match(page, /will be blocked\./);
    assert.match(page, /\{\{ startSummary \}\}/);
    // A search with at most 10 batches shown, "Showing N of M", and ticked batches kept as chips.
    assert.match(page, /const BATCH_CAP = 10;/);
    assert.match(page, /Showing \{\{ visibleOptions\.length \}\} of/);
    assert.match(page, /aria-label="Ticked batches"/);
    // The shared tabs, and the paired "Write off stock" link.
    assert.match(page, /<OperationalTabs/);
    assert.match(page, />Write off stock</);
    // The removal buttons need their own permissions; view-only staff see a plain read-only line.
    assert.match(
        page,
        /v-if="recalls\.can\.writeOff"\s+href="\/inventory\/writeoffs"/,
    );
    assert.match(
        page,
        /v-if="recalls\.can\.returnToSupplier"\s+href="\/inventory\/supplier-returns"/,
    );
    assert.match(page, /data-testid="recall-read-only"/);
    assert.match(page, /v-if="recalls\.can\.start"/);
    // An already recalled batch cannot be ticked again.
    assert.match(page, /:disabled="option\.recalled"/);
});

test('STK-25: notes carry the no-patient hint and the starter is told someone else must lift it', () => {
    assert.equal(
        (page.match(/No patient names or IC numbers\./g) ?? []).length,
        2,
    );
    assert.match(
        page,
        /You started this recall, so another person must\s+lift it\./,
    );
    assert.match(page, /lock_version: recall\.lockVersion/);
    // A lift sent twice is the same lift; a slow batch list for an earlier SKU is ignored.
    assert.match(page, /retry_key: lift\.key/);
    assert.match(page, /if \(request === batchRequest\)/);
    // The reference carries the same hint and says who sees it.
    assert.match(page, /id="recall-reference-hint"/);
    assert.match(page, /v-if="recalls\.can\.lift"/);
});
