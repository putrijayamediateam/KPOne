// Classic only, CO-5: the Classic half of bv2-0-billing-workspace-v2-copy.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/components/billing/BillingWorkspace.vue');
const classicLogic = classic.slice(
    classic.indexOf('const responsibilityTotals'),
    classic.indexOf('</script>'),
);

test('Classic billing workspace imports no V2 composable', () => {
    assert.ok(!classic.includes('useBillingWorkspaceV2'));
    assert.ok(!classic.includes('composables/billing'));
});

// From bv2-0 'both copies keep every money route, key, lock version and guard': the Classic half, same 26 fragments.
test('Classic billing workspace keeps every money route, key, lock version and guard', () => {
    const fragments = [
        'expected_branch_id: page.props.branchContext?.active?.id ?? 0,',
        'lock_version: props.billing.invoice?.lockVersion ?? null,',
        "return_to: props.manage ? 'payments' : 'billing',",
        'if (busy.value || contextCleared.value) {',
        '`${invoiceBase.value}/payments`',
        'amount_sen: sen,',
        'method: form.method,',
        'reference: form.reference || null,',
        'idempotency_key: paymentKey,',
        'let paymentKey = crypto.randomUUID();',
        'paymentKey = crypto.randomUUID();',
        '`${invoiceBase.value}/responsibility/${kind}`',
        'due_date: form.due_date || null,',
        'panel_id: form.panel_id || null,',
        'member_reference: form.member_reference || null,',
        '`${invoiceBase.value}/responsibility/${kind}/${proposal.publicId}/approve`',
        'proposal_lock_version: proposal.lockVersion',
        '`${invoiceBase.value}/payments/${receipt.publicId}/reverse`',
        'payment_lock_version: receipt.lockVersion,',
        'recording_error_only: form.recording_error_only,',
        "event.detail.visit.url.pathname === '/branch-context'",
        "['Billing/Show', 'Billing/Payments'].includes(",
        '!props.billing.can.pay',
        '!props.billing.can.panelPropose',
        '!props.billing.can.deferPropose',
        "receipt.status !== 'posted'",
    ];

    for (const fragment of fragments) {
        assert.ok(
            classicLogic.includes(fragment),
            `Classic keeps: ${fragment}`,
        );
    }
});
