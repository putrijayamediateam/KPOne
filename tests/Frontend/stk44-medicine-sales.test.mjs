import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = () => read('resources/js/pages/Inventory/MedicineSales.vue');

test('STK-44: the page has a header, a GET filter with Apply and Reset, and the visible void line', () => {
    const ui = page();
    assert.ok(ui.includes('title="Medicine sales"'));
    assert.match(ui, /router\.get\('\/inventory\/medicine-sales'/);
    assert.ok(ui.includes('<option value="sales">Sales</option>'));
    assert.ok(ui.includes('<option value="profit">Gross profit</option>'));
    assert.ok(!ui.includes('value="units"'), 'no ranking by units');
    assert.match(ui, /variant="outline"[\s\S]*?Apply/);
    assert.ok(ui.includes('>Reset</ActionLink'));
    assert.ok(ui.includes('Figures can change if an invoice is voided later.'));
});

test('STK-44: the accountant banner sits above the figures', () => {
    const ui = page();
    const banner = ui.indexOf('data-testid="sales-banner"');
    const cards = ui.indexOf('data-testid="sales-money-cards"');
    assert.ok(banner > 0 && cards > banner);
    assert.ok(ui.includes('{{ report.banner }}'));
});

test('STK-44: money and count cards are two groups, two per row on a phone', () => {
    const ui = page();
    assert.match(
        ui,
        /class="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4"\s+aria-label="Money totals"/,
    );
    assert.ok(ui.includes('aria-label="Line counts"'));

    for (const label of [
        'Medicine sales',
        'Cost of medicines sold',
        'Gross profit',
        'Margin',
        'Lines below cost',
        'Free lines',
    ]) {
        assert.ok(ui.includes(`label: '${label}'`), label);
    }

    assert.ok(ui.includes('left out of profit and margin.'));
    // On the cost card "(estimate)" is its own line, so a narrow card never grows wider than the screen.
    assert.match(ui, /note: s\.value\.estimate \? '\(estimate\)' : null/);
    assert.ok(ui.includes('data-testid="sales-card-note"'));
    assert.match(ui, /size-2 shrink-0 rounded-full bg-warning/);
});

test('STK-44: negative figures get a minus sign and "(estimate)" is joined with a no-break space', () => {
    const ui = page();
    assert.match(ui, /\$\{sen < 0 \? '-' : ''\}RM\$\{NBSP\}/);
    assert.match(ui, /\$\{text\}\$\{NBSP\}\(estimate\)/);
    assert.ok(
        !/text-destructive[^"]*">\s*\{\{ money\(row\.profitSen\)/.test(ui),
    );
});

test('STK-44: "Cost not complete" has an accessible name and a visible footnote', () => {
    const ui = page();
    assert.ok(ui.includes("const NOT_COMPLETE = 'Cost not complete';"));
    assert.ok(
        ui.includes(
            "'Cost not complete: some stock sold has no cost recorded.'",
        ),
    );
    assert.match(ui, /:aria-label="NOT_COMPLETE_REASON"/);
    assert.ok(ui.includes('data-testid="sales-not-complete-note"'));
});

test('STK-44: table from 1024 px, cards below, with the agreed column names', () => {
    const ui = page();
    assert.ok(ui.includes('class="hidden lg:block" data-testid="sales-table"'));
    assert.ok(ui.includes('data-testid="sales-cards"'));
    assert.match(ui, /class="space-y-2 lg:hidden"/);

    for (const heading of [
        'Rank',
        'Medicine',
        'Units',
        'Sales',
        'Cost',
        'Gross profit',
        'Margin',
        'Lines below cost',
        'Free lines',
    ]) {
        assert.match(ui, new RegExp(`>\\s*${heading}\\s*</th>`), heading);
    }

    assert.ok(ui.includes('mixed units'));
    assert.match(ui, /v-if="row\.belowLines > 0"/);
    assert.match(ui, /v-if="row\.freeLines > 0"/);
});

test('STK-44: the sold-below-cost list has an empty state and cards with pagination', () => {
    const ui = page();
    assert.ok(ui.includes('No medicine was sold below cost this month.'));
    assert.ok(ui.includes('data-testid="sales-below-cards"'));
    assert.match(ui, /<CompactPagination[\s\S]*?@change="belowPage"/);
});

test('STK-44: the disclosure explains the rules and stays closed', () => {
    const ui = page();
    assert.match(
        ui,
        /<details\s+class="group rounded-lg border bg-card"\s+data-testid="sales-how"/,
    );
    assert.ok(!/<details[^>]*\sopen[\s>]/.test(ui));
    assert.ok(ui.includes('How this page counts'));
    assert.ok(ui.includes('A line charged RM 0.00 is a free line'));
    assert.ok(ui.includes('"(estimate)" means'));
});

test('STK-44: the page shows no patient or doctor field and offers no download', () => {
    const ui = page();

    for (const word of ['patient', 'doctor', 'download', '.csv']) {
        assert.ok(!ui.toLowerCase().includes(word), word);
    }
});

test('STK-44: the route is permission-gated, no-store and throttled', () => {
    const routes = read('routes/web.php');
    assert.match(
        routes,
        /Route::get\('inventory\/medicine-sales', MedicineSalesController::class\)->middleware\(\['permission:inventory\.medicine_sales\.view\.organisation', 'sensitive\.no-store', 'throttle:60,1'\]\)/,
    );
});
