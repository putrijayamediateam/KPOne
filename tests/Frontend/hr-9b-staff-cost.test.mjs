import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
// Text may wrap across lines in the template, so compare with whitespace collapsed.
const page = read('resources/js/pages/HR/Dashboard.vue').replace(/\s+/g, ' ');
const cost = read('app/Domain/HR/Dashboard/StaffCostService.php');
const dashboard = read('app/Domain/HR/Dashboard/HrDashboardService.php');

test('HR-9b: the cost card shows only when the server sends a figure, never per person or branch', () => {
    assert.match(page, /v-if="dashboard\.staffCost"/);
    assert.match(page, /data-test="staff-cost"/);
    assert.match(page, /Not split by person or branch/);
    assert.match(page, /Includes corrections approved later/);
    assert.match(page, /People paid through KPOne/);
    assert.match(page, /data-test="staff-cost-estimate"/);
    assert.match(page, /data-test="staff-cost-unreadable"/);
    assert.match(page, /so this figure is incomplete/);
    assert.match(page, /from approved pay runs only/);
    assert.match(
        page,
        /An estimate until the clinic's accountant has checked the pay rules/,
    );
    // The card is read from the one dashboard-level figure; a branch card never carries a cost.
    assert.doesNotMatch(page, /branch\.staffCost|branch\.cost/);
});

test('HR-9b: only the approve-payroll holder gets a figure, and only approved or locked runs count', () => {
    assert.match(cost, /PayrollPermissions::APPROVE/);
    assert.match(cost, /\['approved', 'locked'\]/);
    assert.match(cost, /return null;/);
    assert.match(dashboard, /\$this->cost->forMonth\(\$actor, \$month\)/);
    assert.match(dashboard, /'staff_cost'/);
});

test('HR-9b: the service only reads, and keeps money in whole sen (no float)', () => {
    assert.doesNotMatch(
        cost,
        /->insert\(|->update\(|->delete\(|->upsert\(|DB::statement/,
    );
    assert.doesNotMatch(cost, /\(float\)|floatval|round\(/);
});
