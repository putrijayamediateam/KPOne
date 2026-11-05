import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-6: reverse a wrongly recorded Panel payment (owner decision 2026-11-05).

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/PanelPaymentCorrections/Index.vue');
const remittances = read('resources/js/pages/PanelRemittances/Index.vue');
const invoices = read('resources/js/pages/PanelInvoices/Index.vue');
const claim = read('resources/js/pages/PanelClaims/Show.vue');
const routes = read('routes/web.php');
const service = read(
    'app/Domain/Visit/Billing/Services/PanelPaymentCorrectionService.php',
);
const migration = read(
    'database/migrations/2026_11_06_300100_create_panel_claim_payment_reversals.php',
);

test('Reversing needs a reason, a note and the not-a-refund confirmation', () => {
    assert.match(page, /router\.post\(\s*'\/panel-payment-corrections'/);

    for (const field of [
        'expected_branch_id',
        'idempotency_key',
        'kind: payment.kind',
        'target: payment.publicId',
        'reason_code: form.reason',
        'note: form.note',
        'recording_error_only: form.confirmed',
    ]) {
        assert.ok(page.includes(field), field);
    }

    assert.match(page, /maxlength="200"/);
    assert.match(page, /Not a refund/);
    assert.match(
        page,
        /You recorded this payment, so someone else must reverse it/,
    );
    assert.doesNotMatch(page, /localStorage|sessionStorage/);
});

test('Only the Finance Officer and the Director reach the page', () => {
    assert.match(
        routes,
        /permission:panel\.claim_payments\.reverse\.branch', 'sensitive\.no-store'/,
    );
    assert.match(routes, /'panel-payment-corrections'.*throttle:10,1/);
    assert.match(
        service,
        /PERMISSION = 'panel\.claim_payments\.reverse\.branch'/,
    );
    assert.match(invoices, /v-if="can\.reversePayments"/);
    assert.match(remittances, /v-if="canReverse"/);
});

test('Reversed payments stay listed, struck through', () => {
    assert.match(claim, /payment\.reversed/);
    assert.match(claim, /not counted as received/);
    assert.match(remittances, /remittance\.reversed/);
    assert.match(page, /line-through/);
});

test('PostgreSQL keeps reversals append-only, whole and by someone else', () => {
    assert.match(
        migration,
        /BEFORE UPDATE OR DELETE ON panel_claim_payment_reversals/,
    );
    assert.match(migration, /BEFORE TRUNCATE ON panel_claim_payment_reversals/);
    assert.match(
        migration,
        /p\.recorded_by_user_id <> NEW\.reversed_by_user_id/,
    );
    assert.match(migration, /reversed with all of its splits/);
    assert.match(migration, /DEFERRABLE INITIALLY DEFERRED/);
});
