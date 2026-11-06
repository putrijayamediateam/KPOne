import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-7: the Panel statement upload helper (owner decisions 2026-11-05).

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const helper = read('resources/js/components/panel/PanelStatementHelper.vue');
const page = read('resources/js/pages/PanelRemittances/Index.vue');
const routes = read('routes/web.php');
const consoleRoutes = read('routes/console.php');

test('The helper uploads a CSV and says the file is deleted after 30 days', () => {
    assert.match(helper, /\.post\('\/panel-remittances\/statements'/);
    assert.match(helper, /forceFormData: true/);
    assert.match(helper, /accept="\.csv,text\/csv,text\/plain"/);
    assert.match(helper, /stored privately, encrypted, and deleted after/);
    assert.match(helper, /nothing is recorded until you press Record payment/);
    assert.match(
        consoleRoutes,
        /Schedule::command\(PurgePanelStatements::class\)\s*->daily\(\)/,
    );
    assert.match(
        routes,
        /panel-remittances\/statements', \[PanelRemittancesController::class, 'storeStatement'\]\)->middleware\('throttle:10,1'\)/,
    );
});

test('Only matched rows can be ticked and nothing is recorded from the statement', () => {
    assert.match(helper, /:disabled="!canTick\(row\)"/);
    assert.match(helper, /emit\(\s*'use'/);
    assert.doesNotMatch(helper, /panel-remittances',\s*\{[^}]*splits/);
    assert.match(page, /@use="useStatement"/);
    assert.match(page, /title="Replace the current split\?"/);
    assert.doesNotMatch(helper, /patientName/);
    assert.doesNotMatch(helper, /localStorage|sessionStorage/);
});

test('Every result has a plain label', () => {
    for (const label of [
        'Matched',
        'Not found',
        'More than one claim',
        'Already paid',
        'Over unpaid amount',
        'Amount not readable',
    ]) {
        assert.ok(helper.includes(`label: '${label}'`), label);
    }
});
