import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
test('governed stock offers independent count and approval states with bounded lists and no raw HTML', () => {
    const ui = read('resources/js/pages/Inventory/GovernedStock.vue').replace(/\s+/g, ' ');

    for (const text of ['A different counter must recount', 'Independent CA Supervisor, Finance Officer or Director approval required', 'Closed: zero variance', '25 per page', 'stock.legacyLimit', 'Take snapshot', 'Propose write-off', 'Disposal document or log reference', 'No variance threshold', 'row.canApprove', 'row.canCount', 'min-h-11', 'show-close-button="false"']) {
assert.ok(ui.includes(text), text);
}

    assert.doesNotMatch(ui, /v-html|localStorage|sessionStorage|Current balance version/);
    assert.match(ui, /form.balance_version = row.current_balance_version/);
    assert.match(ui, /branchContext\?\.active\?\.id/);
    assert.match(ui, /form.reset\(\)/);
    assert.match(ui, /history.value = null/);
});
test('governed transport is separate from old demo routes and redirects explicitly', () => {
    const controller = read('app/Http/Controllers/GovernedStockController.php');
    assert.doesNotMatch(controller, /\bback\(/);
    assert.match(controller, /redirectTo\(route\(\$destination\)\)/);
    const routes = read('routes/web.php');

    for (const path of ['inventory/counts', 'inventory/writeoffs', 'inventory/governed/{kind}', 'inventory/governed/{governedCase}/{action}', 'inventory/stocktakes', 'inventory/adjustments']) {
assert.ok(routes.includes(path), path);
}

    const service = read('app/Domain/Organisation/Inventory/Services/GovernedStockService.php');
    assert.match(service, /StockUnitQuantity::scaled/);
    assert.match(service, /recordGovernedChange/);
    assert.doesNotMatch(service, /\(float\)|number_format\(|Http::|Mail::|dispatch\(/);
});
test('movement report preserves unknown-type refusal and explains new adjusted movements', () => {
    const report = read('app/Domain/Shared/Services/StockMovementReportService.php');
    assert.match(report, /invalid_types/);
    assert.match(report, /throw ValidationException/);
    const page = read('resources/js/pages/Reports/BranchReport.vue');
    assert.match(page, /Adjusted includes opening-balance entries recorded during the/);
    assert.match(page, /stock write-offs and reversals are also included in Adjusted/);
});

test('approval control follows the backend participant exclusion rather than a role name', () => {
    const ui = read('resources/js/pages/Inventory/GovernedStock.vue');
    assert.match(ui, /v-if="row.canApprove"[\s\S]*?showCase\(row, 'approved'\)/);
    assert.doesNotMatch(ui, /v-if="[^"\n]*(?:ca_supervisor|finance_officer|director)/);
});
