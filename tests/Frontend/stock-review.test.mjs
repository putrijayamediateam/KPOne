import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
test('stock review exposes three bounded worklists, unresolved expiry and no disposal action', () => {
    const ui = read('resources/js/pages/Inventory/StockReview.vue');

    for (const text of ['Low stock', 'Expiring soon', 'Expired stock still on hand', 'Up to 25 items per page', 'STK-5', 'Review overdue', 'Review history (last 10)', 'review.canManage', 'min-h-11']) {
assert.ok(ui.includes(text), text);
}

    assert.doesNotMatch(ui, /v-html|localStorage|sessionStorage/);
    assert.match(ui, /history.value = null/);
    assert.match(ui, /selected.value = null/);
    assert.match(ui, /form.reset\(\)/);
    assert.match(ui, /branchContext\?\.active\?\.id/);
});
test('stock review transport is scoped, no-store and uses explicit redirects', () => {
    const controller = read('app/Http/Controllers/InventoryStockReviewController.php');
    assert.match(controller, /redirectTo\(route\('inventory.stock-review'\)\)/);
    assert.doesNotMatch(controller, /\bback\(/);
    const routes = read('routes/web.php');
    assert.match(routes, /permission:inventory.stock_reviews.manage.branch/);
    assert.match(routes, /stock-review.*sensitive.no-store/);
});
test('review service reuses eligibility and never mutates stock or uses notification channels', () => {
    const service = read('app/Domain/Organisation/Inventory/Services/InventoryStockReviewService.php');
    assert.match(service, /EXPIRY_WINDOW_DAYS = 90/);
    assert.match(service, /InventoryBatchEligibility::class/);
    assert.match(service, /ROW_NUMBER\(\) OVER/);
    assert.match(service, /'position', '<=', 10/);
    assert.match(service, /lockForBranch\(\$actor, \$branch, self::MANAGE\)/);
    assert.doesNotMatch(service, /->update\(|->delete\(|Mail::|Http::|Notification::|dispatch\(|\(float\)/);
});
