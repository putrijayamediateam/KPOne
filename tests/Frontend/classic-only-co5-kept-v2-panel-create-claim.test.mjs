// Classic only, CO-5: the Classic half of v2-panel-create-claim.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PanelClaims/Create.vue');
const flat = (text) => text.replace(/[\s,()]/g, '');

test('Classic Create claim imports no V2 code', () => {
    assert.ok(!classic.includes('components/v2'));
    assert.ok(!classic.includes('usePanelClaimCreate'));
    assert.ok(!classic.includes('V2'));
});

test('Classic Create claim keeps its form, request and photo checks', () => {
    for (const text of [
        'expected_branch_id',
        'allocation_lock_version',
        'invoice_lock_version',
        'idempotency_key',
        'crypto.randomUUID()',
        'submitted_date',
        'remarks',
        'photos',
        '/panel-claims/allocations/',
        'forceFormData: true',
        'selected.length > 10',
        'file.size > 5 * 1024 * 1024',
        'Select up to 10 JPG or PNG photos, at most 5 MB each.',
    ]) {
        assert.ok(classic.includes(text), `Classic has ${text}`);
    }
});

test('Classic Create claim keeps its wording', () => {
    for (const text of [
        'Create panel claim',
        'Cancel',
        'Create claim',
        'Invoice details',
        'Visit date and time',
        'Treatment items',
        'Item name',
        'Rate',
        'Qty',
        'Total',
        'Original subtotal',
        'Invoice adjustment',
        'Adjusted total',
        'Panel claim details',
        'Submitted date',
        'Remarks',
        'Upload photo',
        'Drop photos here or choose files.',
        'Invoice amount breakdown',
    ]) {
        const wanted = flat(text);
        assert.ok(flat(classic).includes(wanted), `Classic has: ${text}`);
    }
});

test('one row says Billed to and Patient details (Classic prints the same name twice)', () => {
    assert.ok(
        classic.includes('Billing details') &&
            classic.includes('Patient details'),
    );
});

test('Classic claim page shows the PC-02 recovery error after a partial failure', () => {
    assert.match(
        read('resources/js/pages/PanelClaims/Show.vue'),
        /page\.props\.errors\?\.file/,
    );
});
