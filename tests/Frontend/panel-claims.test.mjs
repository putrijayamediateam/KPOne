import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const index = read('resources/js/pages/PanelClaims/Index.vue');
const show = read('resources/js/pages/PanelClaims/Show.vue');
const insights = read('resources/js/pages/Insights/Report.vue');
const navigation = read('resources/js/lib/workspace-navigation.ts');
const service = read('app/Domain/Visit/Billing/Services/PanelClaimService.php');

test('Panel Claims is a separate branch-scoped manual-submission workspace', () => {
    assert.match(index, /Prepare claim packs for manual\s+submission/);
    assert.match(
        index,
        /clinical\s+notes,\s+diagnoses,\s+identity\s+documents\s+or\s+attachments/,
    );
    assert.match(navigation, /label: 'Panel Claims'/);
    assert.match(navigation, /href: '\/panel-claims'/);
    assert.match(navigation, /href: '\/panel-responsibility'/);
    assert.match(service, /'panel\.claims\.manage\.branch'/);
    assert.match(
        read('docs/BASELINE.md'),
        /\*\*PC-01: manual Panel Claims \(authorised 2026-10-10\)\*\*/,
    );
});

test('Claim packs show invoice lines and require reason and confirmation for above-invoice changes', () => {
    assert.match(show, /Panel claim adjustment/);
    assert.match(show, /confirm_over_invoice:\s*confirmAboveInvoice\.value/);
    assert.match(show, /Reason for changing the approved amount/);
    assert.match(service, /'amount_adjusted'/);
    assert.match(service, /'confirm_over_invoice'/);
});

test('Panel decisions and remittances stay separate from patient invoice payments', () => {
    assert.match(show, /Record Panel decision/);
    assert.match(show, /Record Panel remittance/);
    assert.match(
        show,
        /do not create patient receipts\s+or change\s+the Invoice/,
    );
    assert.match(
        service,
        /remittance exceeds the accepted amount still outstanding/,
    );
    assert.doesNotMatch(service, /PaymentAllocation::|new Payment\b/);
});

test('Payments Insights labels Panel remittances and accepted claim balances separately', () => {
    assert.match(insights, /Panel remittances received/);
    assert.match(insights, /Panel claims outstanding/);
    assert.match(insights, /remittances are separate from patient receipts/);
    assert.match(insights, /Panel claim outcomes/);
});

test('Panel Claims main page has summary cards, status tabs, filters, column picker and a row menu', () => {
    assert.match(index, /aria-label="Submissions"/);
    assert.match(index, /aria-label="Payouts"/);
    assert.match(index, /\['approved', 'Approved'\]/);
    assert.match(index, /\['outstanding', 'Outstanding'\]/);

    for (const label of [
        'All',
        'Pending',
        'Overdue',
        'Submitted',
        'Approved',
        'Rejected',
        'Received',
    ]) {
        assert.match(index, new RegExp(`\\['[a-z]+', '${label}'\\]`));
    }

    assert.doesNotMatch(index, /\['cancelled', 'Cancelled'\]/);
    assert.match(index, /label: 'Yesterday'/);
    assert.match(index, /label: 'Custom'/);
    assert.match(index, /Customize column/);
    assert.match(index, /Reset filters/);
    assert.match(index, /View claim/);
    assert.match(index, /Submit claim \(manual\)/);
    assert.doesNotMatch(index, /Send claim|Revert claim/);
});

test('Panel Claims filters are sent to the server and never kept in browser storage', () => {
    assert.match(index, /router\.get\('\/panel-claims'/);
    assert.match(index, /claim_no: draft\.claimNo/);
    assert.match(index, /amount_min: draft\.amountMin/);
    assert.doesNotMatch(index, /localStorage|sessionStorage/);
});

test('Pending and Overdue list unclaimed invoices with View invoice and Create claim, with clinical filters only for permitted roles', () => {
    assert.match(index, /View invoice/);
    assert.match(index, /Create claim/);
    assert.match(index, /Invoice number/);
    assert.match(index, /Due date/);
    assert.match(index, /Remark note/);
    assert.match(index, /Visit type/);
    assert.match(index, /\/panel-claims\/allocations\//);
    assert.match(index, /v-if="work\.can\.clinicalFilters"/);
    assert.match(index, /Diagnosis/);
    assert.match(index, /Treatment/);
    assert.doesNotMatch(index, /MC days/);
});
