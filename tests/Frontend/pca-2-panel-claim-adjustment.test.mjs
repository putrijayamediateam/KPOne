import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const flat = (src) => src.replace(/\s+/g, ' ');
const panel = read('resources/js/components/billing/PanelAdjustmentPanel.vue');
const versions = read('resources/js/components/billing/PanelClaimVersions.vue');
const classicBilling = read(
    'resources/js/components/billing/BillingWorkspace.vue',
);
const v2Billing = read('resources/js/pages/Billing/V2/Show.vue');
const classicClaim = read('resources/js/pages/PanelClaims/Show.vue');
const v2Claim = read('resources/js/pages/PanelClaims/V2/Show.vue');

test('PCA-2: the panel posts the request with its idempotency key, invoice version and page', () => {
    for (const key of [
        'expected_branch_id',
        'invoice_lock_version',
        'direction',
        'amount_sen',
        'reason_code',
        'note',
        'idempotency_key',
        'from',
    ]) {
        assert.ok(panel.includes(`${key}:`), key);
    }

    // Confirm and withdraw send the adjustment's own version and a fresh key per decision.
    assert.match(panel, /lock_version: waiting\.lockVersion/);
    assert.match(panel, /idempotency_key: confirmKey\.value/);
    // The server decides every amount: the panel only converts ringgit to sen.
    assert.match(panel, /toSen\(amount\.value\)/);
    assert.doesNotMatch(panel, /fetch\(|axios/);
});

test('PCA-2: the wording says which button changes the invoice and that the patient owes nothing more', () => {
    const text = flat(panel);

    for (const wanted of [
        // Daniel's board v10, sections 1 to 3.
        'Adjust Panel amount: changes the invoice',
        'Change claim amount only: the invoice stays the same',
        'What the patient paid and owes does not change. No cash moves.',
        'Invoice payable becomes',
        'Panel share now',
        'is kept in Logs.',
        'Open Panel claim',
        'Amount changed after submission: tell the Panel.',
        'Panel share on the invoice',
        'Panel adjustment (confirmed)',
        'This claim is decided, so it is not changed.',
        'Open supplementary claim',
        'Confirm adjustment',
        'Withdraw request',
        'Request adjustment',
        'requested and confirmed by the same person:',
        'Enter an amount and choose a reason to request the adjustment.',
    ]) {
        assert.ok(text.includes(wanted), wanted);
    }

    // Syafiq's V2 label for the claim-only edit stays as it is.
    assert.ok(
        v2Claim.includes(
            'Change claim amount only: the invoice stays the same',
        ),
    );
});

test('PCA-2: Classic and Version 2 Billing and claim pages show the panel (a new rule, so both layouts)', () => {
    for (const [name, src] of [
        ['Classic Billing', classicBilling],
        ['V2 Billing', v2Billing],
    ]) {
        assert.match(src, /<PanelAdjustmentPanel/, name);
        assert.match(src, /billing\.panelAdjustment/, name);
    }

    for (const [name, src] of [
        ['Classic claim', classicClaim],
        ['V2 claim', v2Claim],
    ]) {
        assert.match(src, /<PanelAdjustmentPanel/, name);
        assert.match(src, /<PanelClaimVersions/, name);
    }

    // Version 2 components never leak into Classic.
    assert.doesNotMatch(classicClaim, /components\/v2/);
    assert.doesNotMatch(classicBilling, /useBillingWorkspaceV2/);
});

test('PCA-2: a supplementary claim names its original and earlier versions stay listed', () => {
    const text = flat(versions);
    assert.ok(text.includes('Supplementary to'));
    assert.ok(text.includes('Claim pack Version'));
    assert.ok(
        text.includes(
            'Earlier versions are kept and can be viewed. They are never rewritten.',
        ),
    );
    assert.match(versions, /\['version', String\(version\)\]/);
});

test('PCA-2: a cancelled supplementary claim is explained on its page and under the Panel summary', () => {
    const report = read('resources/js/pages/Reports/BranchReport.vue');
    assert.ok(
        flat(report).includes(
            'v-if="endpoint === \'/reports/panel-summary\'" class="text-sm text-muted-foreground" > Cancelled supplementary claims are not counted.',
        ),
    );
    assert.match(classicClaim, /claim\.adjustmentClaim\?\.cancelledNote/);
    assert.match(v2Claim, /adjustmentClaim\?\.cancelledNote/);
});
