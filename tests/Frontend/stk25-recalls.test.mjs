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
    assert.match(
        page,
        /This blocks \{\{ batchWord\(start\.batches\.length\) \}\} at\s+every branch\./,
    );
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
    assert.match(page, /v-if="recalls\.can\.lift"/);
});
