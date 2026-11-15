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
    assert.match(index, /Create claims for manual\s+submission/);
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
        /do\s+not\s+create\s+patient\s+receipts\s+or\s+change\s+the\s+Invoice/,
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
    assert.match(index, /router\.visit\(\s*`\/panel-claims\/allocations\/.*\/create`,?\s*\)/);
    assert.match(index, /v-if="work\.can\.clinicalFilters"/);
    assert.match(index, /Diagnosis/);
    assert.match(index, /Treatment/);
    assert.doesNotMatch(index, /MC days/);
});

test('Claim page follows the Yezza layout: summary, items, documents, payments, remarks and logs with Add note', () => {
    for (const text of [
        'Copy link',
        'Download PDF',
        'Edit panel claim',
        'Billed to',
        'Patient details',
        'Person in charge',
        'Panel claim submission due date',
        'Verification link',
        'View invoice',
        'Claimed amount',
        'Amount due',
        'Documents',
        'Payments',
        'Remarks',
        'Logs',
        'Add note',
    ]) {
        assert.ok(show.includes(text), `Show.vue is missing "${text}"`);
    }

    assert.doesNotMatch(show, /Send claim/);
    assert.match(show, /accept="image\/jpeg,image\/png"/);
    assert.match(show, /rel="noopener noreferrer"/);
});

test('Create claim page uses selected invoice lines, ten-row pagination and submitted details', () => {
    const create = read('resources/js/pages/PanelClaims/Create.vue');

    for (const text of ['Create panel claim', 'Cancel', 'Create claim', 'Invoice details', 'Billing details', 'Patient details', 'Staff ID: N/A', 'Chit no: N/A', 'Treatment items', 'Item name', 'Rate', 'Qty', 'Total', 'Panel claim details', 'Submitted date', 'Remarks', 'Upload photo']) {
        assert.ok(create.includes(text), `Create.vue is missing "${text}"`);
    }

    assert.match(create, /lines\.slice\([\s\S]*?\(page\.value - 1\) \* 10,[\s\S]*?page\.value \* 10/);
    assert.match(create, /submitted_date: props\.branch\.today/);
    assert.match(create, /idempotency_key: crypto\.randomUUID\(\)/);
    assert.match(create, /allocation_lock_version: props\.allocation\.lockVersion/);
    assert.match(create, /invoice_lock_version: props\.allocation\.invoiceLockVersion/);
    assert.match(create, /:min="allocation\.visitDate"/);
    assert.match(create, /:max="branch\.today"/);
    assert.match(create, /maxlength="500"/);
    assert.match(create, /@drop\.prevent/);
    assert.match(create, /accept="image\/jpeg,image\/png"/);
    assert.match(create, /selected\.length > 10/);
    assert.match(create, /file\.size > 5 \* 1024 \* 1024/);
    assert.match(create, /forceFormData: true/);
    assert.match(create, /form\.errors\.submitted_date/);
    assert.doesNotMatch(create, /localStorage|sessionStorage|scan QR|Send claim/);
});

test('Creation preserves legacy routes, uses shared snapshots and exposes photo recovery errors', () => {
    const routes = read('routes/web.php');
    const controller = read('app/Http/Controllers/PanelClaimsController.php');

    assert.match(routes, /Route::get\('panel-claims\/allocations\/\{allocation\}\/create'/);
    assert.match(routes, /name\('panel-claims\.prepare'\)/);
    assert.match(routes, /name\('panel-claims\.submit'\)/);
    assert.match(service, /private function buildLockedSnapshot/);
    assert.equal((service.match(/\$this->buildLockedSnapshot\(/g) ?? []).length, 2);
    assert.match(controller, /if \(\$claim->wasRecentlyCreated\)/);
    assert.match(controller, /claim was created, but a photo could not be uploaded/);
    assert.match(show, /error \|\| uploadRecoveryError/);
    assert.match(service, /\$claim->status === 'submitted' \? 'submitted' : 'prepared'/);
});
