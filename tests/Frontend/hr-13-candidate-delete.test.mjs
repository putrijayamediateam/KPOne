import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Owner decision 2026-11-11: the candidate deletes their application and its details through the private link.
const page = readFileSync(
    new URL('../../resources/js/pages/Careers/Status.vue', import.meta.url),
    'utf8',
);

test('the status page offers Delete my application behind a confirm, and says when it is done', () => {
    assert.match(page, /'\/careers\/status\/delete'/);
    assert.match(page, /v-if="application\.canDelete"/);
    assert.match(page, /Delete my application \/ Padam permohonan saya/);
    assert.match(page, /data-test="status-delete-confirm"/);
    assert.match(page, /@keydown\.esc="keepDetails"/);
    assert.match(page, /Your application and its details have been deleted\./);
    assert.match(page, /errors\.erase/);
    // What each keeps, so Withdraw and Delete are not confused (Daniel).
    assert.match(page, /We keep your\s+application for now\./);
    assert.match(page, /Remove your application and the details you sent us,/);
});
