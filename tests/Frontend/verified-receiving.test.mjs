import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
test('verified receiving exposes bounded split lots and the required delivery and expiry inputs', () => {
    const ui=read('resources/js/components/inventory/VerifiedReceivingForm.vue');

    for(const text of ['Delivery note or supplier invoice reference','Real expiry date','Physical quantity counted','Controlled reason','Request exception approval','maximum 20','min-h-11']){
assert.ok(ui.includes(text),text);
}

    assert.match(ui,/props.branchId, props.row.lockVersion/);assert.match(ui,/form.reset\(\)/);assert.doesNotMatch(ui,/v-html|localStorage|sessionStorage/);
});
test('review controls are permission filtered, branch scoped and paged with visible limits',()=>{
    const ui=read('resources/js/components/inventory/ReceivingReviews.vue');

    for(const text of ['row.canApprove','row.canPost','25 per page','Request reversing correction','receiving_receipts_page','min-h-11']){
assert.ok(ui.includes(text),text);
}

    assert.match(ui,/receiving\?\.branchId === props.branchId/);assert.match(ui,/loadedForBranch.value = props.branchId/);assert.match(ui,/loadedForBranch.value = null/);assert.doesNotMatch(ui,/v-html|localStorage|sessionStorage/);
});
test('new receiving transport preserves existing secure receipt nonce and explicit Inventory redirects',()=>{
    const controller=read('app/Http/Controllers/InventoryOperationsController.php');
    assert.match(controller,/delivery_reference/);assert.match(controller,/currentReceiptSessionNonce/);assert.match(controller,/review_lock_version/);assert.doesNotMatch(controller,/\bback\(/);
    const routes=read('routes/web.php');assert.match(routes,/permission:inventory.receiving.exceptions.approve.branch/);
});
test('procurement quantity arithmetic remains exact and quarantine reuses the existing hold service',()=>{
    const service=read('app/Domain/Organisation/Inventory/Services/ProcurementService.php');
    assert.doesNotMatch(service,/\(float\)|number_format\(/);assert.match(service,/StockUnitQuantity::scaled/);assert.match(service,/InventoryBatchHoldService::class/);assert.match(service,/approvedForPosting/);
    assert.doesNotMatch(service,/\$expiryDate\s*=.*9999-12-31/);
});
