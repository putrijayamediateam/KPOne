// Classic only, CO-5: the Classic half of v2-panel-claims-list.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PanelClaims/Index.vue');
const navigation = read('resources/js/components/panel/PanelWorkspaceNav.vue');
const flat = (text) => text.replace(/[\s,()]/g, '');

test('Classic Panel Claims list imports no V2 code', () => {
    assert.ok(!classic.includes('panel-v2'));
    assert.ok(!classic.includes('components/v2'));
    assert.ok(!classic.includes('usePanelClaimsList'));
    assert.ok(!classic.includes('panel-claims-v2'));
});

test('Classic Panel Claims list keeps its routes, query keys and payloads', () => {
    for (const route of [
        "router.get('/panel-claims'",
        "'/panel-claims/bulk'",
        "'/panel-claims/pack'",
        '/panel-claims/allocations/',
    ]) {
        assert.ok(classic.includes(route), `Classic has ${route}`);
    }

    for (const key of [
        'claim_no',
        'amount_min',
        'amount_max',
        'invoice_no',
        'visit_type',
        'visit_from',
        'due_to',
        'diagnosis',
        'treatment',
        'expected_branch_id',
        'allocation_lock_version',
        'invoice_lock_version',
        'idempotency_key',
    ]) {
        assert.ok(classic.includes(key), `Classic has ${key}`);
    }

    for (const link of ['/panel-claims/follow-up', '/panel-remittances']) {
        assert.ok(
            navigation.includes(link),
            `Classic navigation links ${link}`,
        );
    }
});

test('Classic Panel Claims list keeps its wording', () => {
    assert.match(classic, /<PanelWorkspaceNav\s+active="claims"/);
    const strings = [
        'Panel Claims',
        'Create claims for manual submission and track Panel decisions and remittances.',
        'Follow-up list',
        'Record Panel payment',
        'Claim status',
        'Visit type',
        'Invoice number',
        'Doctor',
        'Diagnosis',
        'Treatment',
        'Claim number',
        'Amount (RM)',
        'Submitted date',
        'Remarks (optional)',
        'The same remark goes on every claim',
        'View invoice',
        'Create claim',
        'View claim',
        'Submit claim (manual)',
        'Clear selection',
        'Print pack',
        'No overdue claims',
        'No pending invoices',
        'No claims match',
        'Select all ${selectAllPanel} invoices on this page',
        'Filter by one Panel to select all invoices on this page',
        'Select all ${packAllPanel} claims on this page',
        'Filter by one Panel to select all claims on this page',
        'Select invoice ${row.invoiceNumber} for a bulk claim',
        'Select claim ${row.claimNo} for a claim pack',
        'Actions for claim ${row.claimNo}',
        'Only invoices of ${selectedPanel.value} can be added to this batch',
        'Only claims of ${packPanel.value} can be added to this pack',
        'the most for one batch. Create these claims first, then select more.',
        'the most for one pack. Print these first, then select more.',
        'Create claim opens',
        'Prints a cover page and one A4 page per claim',
        'pack-panel-rule',
        'bulk-claim-bar',
        'claim-pack-bar',
        'claim-pack-error',
    ];

    for (const text of strings) {
        assert.ok(
            flat(
                ['Follow-up list', 'Record Panel payment'].includes(text)
                    ? navigation
                    : classic,
            ).includes(flat(text.split('${')[0])),
            `Classic has: ${text}`,
        );
    }

    for (const label of ['Submissions', 'Payouts']) {
        assert.ok(classic.includes(`aria-label="${label}"`));
    }
});

test('claim status chips and the row menus', () => {
    assert.ok(
        classic.includes("status === 'Prepared' || status === 'Panel query'"),
    );
});
