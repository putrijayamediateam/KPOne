import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-2: bulk claim creation on the Pending and Overdue lists.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const index = read('resources/js/pages/PanelClaims/Index.vue');
const service = read('app/Domain/Visit/Billing/Services/PanelClaimService.php');
const directory = read(
    'app/Domain/Visit/Billing/Services/PanelClaimDirectoryService.php',
);
const controller = read('app/Http/Controllers/PanelClaimsController.php');
const routes = read('routes/web.php');

test('Rows can be selected for one Panel only, compared by its fixed code', () => {
    assert.match(
        index,
        /import \{ Checkbox \} from '@\/components\/ui\/checkbox'/,
    );
    assert.match(directory, /'pn\.code as panel_code'/);
    assert.match(directory, /'panelCode' => \(string\) \$row->panel_code/);
    assert.match(
        index,
        /const canSelect = \(row: EligibleRow\) =>\s*selectedPanelCode\.value === null \|\|\s*row\.panelCode === selectedPanelCode\.value/,
    );
    assert.doesNotMatch(index, /row\.panelName === selected/);
    assert.match(index, /:disabled="\s*!canSelect\(row\) \|\|/);
    assert.match(index, /:title="lockedReason\(row\)"/);
    assert.match(
        index,
        /Only invoices of \$\{selectedPanel\.value\} can be added to this batch/,
    );
    assert.match(
        index,
        /Select invoice \$\{row\.invoiceNumber\} for a bulk claim/,
    );
    assert.match(
        index,
        /Filter by one Panel to select all invoices on this page/,
    );
    assert.match(index, /const bulkMax = 20;/);
    assert.match(service, /public const BULK_MAX = 20;/);
    assert.match(index, /the most for one batch/);
});

test('A change of tab, filter or page starts a fresh selection', () => {
    assert.match(
        index,
        /const go = \([^)]*\) => \{\s*selected\.value = \{\};\s*router\.get\('\/panel-claims'/,
    );
});

test('The bulk form posts one idempotency key and both versions per invoice, with the list view', () => {
    assert.match(index, /router\.post\(\s*'\/panel-claims\/bulk'/);
    assert.match(
        index,
        /bulkKeys\.set\(allocationId, crypto\.randomUUID\(\)\)/,
    );
    assert.match(index, /allocation_lock_version: row\.allocationLockVersion/);
    assert.match(index, /invoice_lock_version: row\.invoiceLockVersion/);
    assert.match(index, /idempotency_key: keyFor\(row\.allocationId\)/);
    assert.match(
        index,
        /\.\.\.query\(\{\s*allocations_page: props\.work\.eligible\.currentPage,?\s*\}\)/,
    );
    assert.match(controller, /->valid\(\)/);
    assert.match(index, /:min="minSubmittedDate \|\| undefined"/);
    assert.match(index, /:max="work\.branch\.today"/);
    assert.doesNotMatch(index, /localStorage|sessionStorage/);
    assert.match(
        routes,
        /Route::post\('panel-claims\/bulk', \[PanelClaimsController::class, 'bulkStore'\]\)->middleware\('throttle:10,1'\)->name\('panel-claims\.bulk-store'\)/,
    );
});

test('The bulk form explains manual submission and shows every refusal', () => {
    assert.match(index, /KPOne sends\s+nothing/);
    assert.match(
        index,
        /If\s+any\s+invoice\s+cannot\s+be\s+claimed,\s+no\s+claim\s+is\s+created/,
    );
    assert.match(index, /Add\s+photos\s+on\s+each\s+claim\s+page\s+afterwards/);
    assert.match(
        index,
        /\['claims', 'submitted_date', 'remarks', 'expected_branch_id'\]/,
    );
    assert.match(index, /key\.startsWith\('claims\.'\)/);
    assert.match(index, /v-for="message in bulkErrorMessages"/);
    assert.doesNotMatch(index, /Send claim/);
});
