import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
test('branch hold dialogs are English, independent, bounded and clear on branch switch', () => {
    const page = read('resources/js/pages/Inventory/BatchHolds.vue');

    for (const text of ['Place safety hold', 'Release hold', 'Controlled reason', '25 per page', 'Multi-branch recalls', 'min-h-11', 'DialogTitle']) {
assert.ok(page.includes(text));
}

    assert.match(page, /branchContext/);
    assert.match(page, /selected.value = null/);
    assert.doesNotMatch(page, /v-html|localStorage|sessionStorage/);
});
test('save and Complete call the shared predicate and batches visibly retain Held choices', () => {
    const service = read('app/Domain/Clinical/Dispensary/Services/DispensaryService.php');
    assert.equal((service.match(/assertEligible\(/g) || []).length, 2);

    for (const path of ['InventoryPickerService', 'InventoryAvailabilityService']) {
assert.match(read(`app/Domain/Organisation/Inventory/Services/${path}.php`), /InventoryBatchEligibility::class/);
}

    const ui = read('resources/js/pages/Dispensary/Show.vue');
    assert.match(ui, /:disabled="stock.held"/);
    assert.match(ui, /fefo_exception_reason/);
    assert.match(ui, /FEFO exception reason/);
    assert.match(ui, /Held:/);
    assert.doesNotMatch(read('app/Http/Controllers/InventoryBatchHoldController.php'), /\bback\(/);
});
