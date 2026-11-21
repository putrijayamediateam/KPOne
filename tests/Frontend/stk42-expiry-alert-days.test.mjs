import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('STK-42: the SKU edit screen offers alert days only when the SKU tracks expiry', () => {
    const ui = read('resources/js/pages/Inventory/Skus.vue');
    assert.ok(ui.includes('Alert before expiry (days)'));
    assert.ok(ui.includes('Leave empty for 90'));
    assert.match(ui, /v-if="tracksExpiry\(sku\)"/);
    assert.match(ui, /errorFor\('expiry_alert_days'\)/);
    assert.match(ui, /delete sent\.expiry_alert_days/);
    assert.match(ui, /data\.expiry_alert_days\.trim\(\) === ''/);
});

test('STK-42: Stock review rewords the expiring sentence and adds a read-only at-risk section', () => {
    const ui = read('resources/js/pages/Inventory/StockReview.vue');
    assert.match(ui, /within each\s+item's\s+alert days/);
    assert.ok(ui.includes('At risk in 6 months'));
    assert.ok(ui.includes('Sorted by value, highest first.'));
    assert.ok(ui.includes('Sorted by expiry date, soonest first.'));
    assert.ok(ui.includes('an average cost, not what was paid for this batch'));

    for (const label of [
        '(estimate)',
        '(part)',
        'No cost recorded',
        'Value (RM)',
    ]) {
        assert.ok(ui.includes(label), label);
    }

    assert.match(ui, /v-if="atRisk\.banner"/);
    assert.match(ui, /v-if="atRisk\.sortedBy === 'value'"/);
    assert.match(ui, /search\('at_risk', atRisk\.page \+ 1\)/);
    assert.match(ui, /parameters\.at_risk_page/);
    // The section has no acknowledge button.
    const section = ui.slice(
        ui.indexOf('data-testid="at-risk"'),
        ui.indexOf('<Dialog v-model:open="dialog"'),
    );
    assert.doesNotMatch(section, /Acknowledge|show\(/);
    assert.doesNotMatch(ui, /v-html|localStorage|sessionStorage/);
});

test('STK-42: value comes only from the controller, never from page()', () => {
    const controller = read(
        'app/Http/Controllers/InventoryStockReviewController.php',
    );
    assert.match(controller, /'atRisk' => \$service->atRisk\(/);
    const service = read(
        'app/Domain/Organisation/Inventory/Services/InventoryStockReviewService.php',
    );
    const page = service.slice(
        service.indexOf('public function page('),
        service.indexOf('public function atRisk('),
    );
    assert.doesNotMatch(page, /WeightedAverageCostService|atRisk|valueSen/);
    assert.match(service, /COALESCE\(s\.expiry_alert_days, \?\)/);
    assert.match(service, /SupplierTermsService::COST_PERMISSION/);
    const tool = read('app/Domain/Agents/Tools/InventoryStockReviewTool.php');
    assert.match(tool, /LEAST\(COALESCE\(s\.expiry_alert_days, \?\), \?\)/);
});
