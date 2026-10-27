import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
test('stock readiness is explicit, bounded, responsive and contains no pilot expansion', () => {
    const page = read('resources/js/pages/Inventory/Readiness.vue');

    for (const text of ['25 per page', 'Search includes items beyond this page', 'Independent authorised checker required', 'No import', 'Unsupported', 'min-h-11']) {
        assert.ok(page.replace(/\s+/g, ' ').toLowerCase().includes(text.toLowerCase()), text);
    }

    assert.match(page, /lg:grid-cols-2/);
    assert.match(page, /retryKeys/);
    assert.doesNotMatch(page, /v-html|localStorage|sessionStorage|fetch\(/);
});
test('opening checker uses the one approved permission and exact holder defaults', () => {
    const migration = read('database/migrations/2026_10_31_000200_grant_inventory_opening_verification.php');
    assert.match(migration, /firstOrCreate/);
    assert.match(migration, /\['ca_supervisor', 'finance_officer', 'director'\]/);
    assert.match(migration, /Log::warning/);
    assert.match(migration, /public function down\(\): void \{\}/);
});
test('exact stock quantity boundary contains no float conversion or rounding', () => {
    const quantity = read('app/Domain/Organisation/Inventory/Services/StockUnitQuantity.php');
    assert.doesNotMatch(quantity, /\(float\)|number_format|round\(/);
    assert.match(quantity, /inputUnit !== \$base/);
    assert.match(quantity, /strlen\(\$fraction\) > 3/);
});
