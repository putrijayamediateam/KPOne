import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const sheet = () =>
    read('resources/js/components/inventory/ReportDifferenceSheet.vue');
const menu = () =>
    read('resources/js/components/inventory/DifferenceRowMenu.vue');
const mine = () =>
    read('resources/js/components/inventory/MyDifferenceReports.vue');

test('STK-45: the sheet asks what was seen with four radio choices that start empty', () => {
    const ui = sheet();

    for (const label of [
        'Fewer than KPOne shows',
        'More than KPOne shows',
        'Some are damaged or expired',
        'Not sure; please count it',
    ]) {
        assert.ok(ui.includes(label), label);
    }

    assert.match(ui, /type="radio"/);
    assert.match(ui, /observed: '',/);
    assert.ok(ui.includes('Pick what you saw to continue.'));
    assert.match(
        ui,
        /:disabled="\s*form\.observed === '' \|\| form\.processing\s*"/,
    );
});

test('STK-45: one filled button, visible hints, the counter, and a 44 px close', () => {
    const ui = sheet();
    assert.match(ui, /variant="outline"[\s\S]*?Cancel/);
    assert.match(ui, /type="submit"[\s\S]*?Send for counting/);
    assert.ok(
        ui.includes(
            "Your rough count, if you have one. The counter won't\n                            see it.",
        ) || /The counter won't\s+see it\./.test(ui),
    );
    assert.ok(ui.includes("Don't write patient names or details."));
    assert.match(ui, /\{\{ form\.note\.length \}\} \/ \{\{ NOTE_LIMIT \}\}/);
    assert.match(ui, /\[&>button:last-child\]:size-11/);
    assert.match(ui, /max-sm:sticky max-sm:bottom-0/);
    assert.match(ui, /env\(safe-area-inset-bottom\)/);
    assert.ok(ui.includes("'/inventory/difference-reports'"));
});

test('STK-45: a blocked reporter sees a refusal with only Close, never a hidden item', () => {
    const ui = sheet();
    assert.ok(
        /You counted this batch, so another person has to report\s+it\./.test(
            ui,
        ),
    );
    const refusal = ui.slice(
        ui.indexOf('report-difference-refusal'),
        ui.indexOf('<form'),
    );
    assert.ok(refusal.includes('>Close</Button'));
    assert.ok(!refusal.includes('Send for counting'));
});

test('STK-45: the row menu says Add to the open count when a live count is open, with a 44 px trigger', () => {
    const ui = menu();
    assert.ok(ui.includes("'Add to the open count'"));
    assert.ok(ui.includes("'Report a difference'"));
    assert.match(ui, /size-11[^"]*lg:size-9/);
    assert.match(ui, /max-lg:min-h-11/);
    assert.match(ui, /:aria-label="`More actions for \$\{label\}`"/);
});

test('STK-45: My reports is a closed disclosure with its count, an empty line and the stale state', () => {
    const ui = mine();
    assert.match(ui, /<details/);
    assert.ok(!/<details[^>]*\sopen/.test(ui), 'starts closed');
    assert.match(ui, /My reports \(\{\{ reports\.total \}\}\)/);
    assert.ok(ui.includes('You have not reported anything at this branch.'));
    assert.ok(ui.includes("stale: 'Needs a new report'"));
    assert.ok(
        /Stock changed before the count\. Report again if it\s+still looks wrong\./.test(
            ui,
        ),
    );
    assert.match(ui, /tone="neutral"\s+dot/);
});

test('STK-45: Inventory shows the menu, the Count open chip and My reports only for P10 holders', () => {
    const ui = read('resources/js/pages/Inventory/Index.vue');
    assert.match(ui, /<MyDifferenceReports\s+v-if="\s*canReport/);
    const rows = read('resources/js/components/inventory/StockOnHandList.vue');
    assert.match(ui, /:can-report="canReport"/);
    assert.match(ui, /@report="openReport"/);
    assert.match(rows, /<div v-if="canReport"/);
    assert.match(rows, /<DifferenceRowMenu\s+v-if="row\.difference"/);
    assert.match(rows, /label="Count open"/);
    assert.match(ui, /return-to="inventory"/);
    assert.match(ui, /reportTrigger\?\.focus\(\)/);
    // The Inventory page still makes no direct writes (i1b): the sheet posts on its own.
    assert.doesNotMatch(ui, /router\.(post|put|patch|delete)/);
});

test('STK-45: Stock review offers the menu on batch rows and at-risk cards, never on Low stock rows', () => {
    const ui = read('resources/js/pages/Inventory/StockReview.vue');
    assert.match(ui, /k !== 'low_stock' &&\s+row\.difference/);
    assert.match(ui, /differences\?\.canReport && row\.difference/);
    assert.match(ui, /return-to="stock-review"/);
});

test('STK-45: Stock count shows who reported, and only the approver gets the rough count and note', () => {
    const ui = read('resources/js/pages/Inventory/GovernedStock.vue');
    assert.match(ui, /Reported by \{\{ report\.reporter \}\}/);
    assert.match(ui, /v-if="report\.roughQuantity"/);
    assert.match(ui, /v-if="report\.note"/);
});
