import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('STK-42b: the spaces say "expiring soon", because each item has its own alert days', () => {
    const show = read('resources/js/pages/Spaces/Show.vue');
    const ask = read('app/Domain/DepartmentSpace/SpaceAskService.php');
    const stock = read('app/Domain/DepartmentSpace/StockSpace.php');

    assert.ok(show.includes('<span class="space-muted">expiring soon</span>'));
    assert.ok(!show.includes('>expiring in'));
    assert.ok(ask.includes('$expiring expiring soon, '));
    assert.ok(!ask.includes("expiring in {$watch['windowDays']} days"));
    assert.ok(stock.includes("'expiring' => 'Expiring soon'"));
    assert.ok(!stock.includes('Expiring within 90 days'));
});
