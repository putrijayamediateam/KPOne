import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/pages/Billing/Family.vue', import.meta.url),
    'utf8',
);

test('BIL-39: Remove from family bill asks first, so one mis-tap removes no one', () => {
    assert.match(page, /OperationalConfirmDialog/);
    assert.match(page, /title="Remove from family bill\?"/);
    // The row button only opens the confirmation; the request is sent from the dialog's confirm.
    assert.match(page, /@click="\s*askRemove\(/);
    assert.equal((page.match(/\/remove`/g) ?? []).length, 1);
    assert.match(page, /const confirmRemove = \(\) => \{[\s\S]*?\/remove`/);
});

test('BIL-39: the family payment sends every member version and the total shown, with one retry key per attempt', () => {
    assert.match(page, /expected_total_sen: props\.family\.totalDueNowSen/);
    assert.match(page, /lock_version: member\.invoice\.lockVersion/);
    assert.match(page, /idempotency_key: attemptKey\.value/);
});
