import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Patient/MergeRequests.vue');
const page = read('resources/js/pages/Patient/V2/MergeRequests.vue');
const config = read('config/v2-pages.php');
const loose = (s) => s.replace(/\s+/g, '');

test('V2 keeps the Classic prop, flags and request', () => {
    for (const piece of [
        'requests: { open: MergeRequestRow[]; recent: MergeRequestRow[] };',
        'canDecide: boolean;',
        'canWithdraw: boolean;',
        'lockVersion: number;',
        '`/patient-merges/${request.publicId}/${action}`',
        'expected_branch_id: branchId',
        'lock_version: request.lockVersion',
        'preserveScroll: true',
        "proposed: 'Waiting'",
        "approved: 'Approved'",
        "rejected: 'Rejected'",
        "withdrawn: 'Withdrawn'",
    ]) {
        assert.ok(
            loose(classic).includes(loose(piece)),
            `Classic lacks ${piece}`,
        );
    }

    for (const piece of [
        'requests: { open: MergeRequestRow[]; recent: MergeRequestRow[] };',
        'canDecide: boolean;',
        'canWithdraw: boolean;',
        'lockVersion: number;',
        '`/patient-merges/${current.request.publicId}/${current.action}`',
        'expected_branch_id: branchId',
        'lock_version: current.request.lockVersion',
        'preserveScroll: true',
        "proposed: 'Waiting'",
        "approved: 'Approved'",
        "rejected: 'Rejected'",
        "withdrawn: 'Withdrawn'",
    ]) {
        assert.ok(loose(page).includes(loose(piece)), `V2 lacks ${piece}`);
    }
});

test('V2 is registered and replaces confirm() with dialogs', () => {
    assert.match(config, /'Patient\/MergeRequests'/);
    assert.ok(
        !/[^'`]\bconfirm\(\s*[`'"a-z]/.test(
            page.replace(/\/\*[\s\S]*?\*\//g, ''),
        ),
    );
    assert.ok(!/<Button\b/.test(page));
    assert.match(page, /<V2Dialog/);
    assert.ok(page.includes("tone: 'danger' as const"));
});

test('V2 shows the Classic wording for who can act', () => {
    assert.ok(
        loose(page).includes(
            loose('Waiting for a different CA Supervisor or Director.'),
        ),
    );
    assert.ok(page.includes('No requests are waiting.'));
    assert.ok(page.includes('No decisions yet.'));
    assert.ok(page.includes('v-if="request.canDecide"'));
    assert.ok(page.includes('v-if="request.canWithdraw"'));
});

test('V2 keeps the filled button in the dialog and says what an approval does', () => {
    assert.ok(!page.includes('filledButton'));
    assert.ok(page.includes(':class="approveButton"'));
    assert.ok(
        page.includes(
            'The two records are linked. Nothing is moved or deleted.',
        ),
    );
    assert.ok(page.includes('The link between the two records is removed.'));
    assert.ok(page.includes("'Approve merge'"));
    assert.ok(page.includes("'Approve unmerge'"));
    // No more private data than Classic: no phone, identifier or date of birth.
    assert.ok(!/phone|identifier|dateOfBirth|date_of_birth/i.test(page));
});
