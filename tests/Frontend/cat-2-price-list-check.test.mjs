import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// CAT-2 (owner decision 2026-11-05): a read-only price list check page, with
// Daniel's design review points pinned.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Pricing/PriceCheck.vue');
const template = page.slice(page.indexOf('<template>'));
const pricing = read('resources/js/pages/Pricing/Index.vue');
const service = read(
    'app/Domain/Visit/Billing/Services/PriceListCheckService.php',
);
const resolver = read(
    'app/Domain/Visit/Billing/Services/PriceResolutionService.php',
);

test('the page is read-only: it only reads its props and navigates with GET', () => {
    assert.doesNotMatch(page, /router\.(post|patch|put|delete)\(/);
    assert.doesNotMatch(page, /useForm\(/);
    assert.match(page, /router\.get\(\s*'\/pricing\/price-check'/);
});

test('billing and the page share one price choice', () => {
    assert.match(
        resolver,
        /public function select\(Collection \$books, Collection \$entries, \?ChargeDefinition \$charge, bool \$panelCoverage, \?int \$panelId, int \$branchId\): \?PriceEntry/,
    );
    assert.match(
        resolver,
        /\$price = \$this->select\(\$books, \$entries, \$charge/,
    );
    assert.match(
        service,
        /\$resolver->select\(\$books, \$entries, \$charge, \$panel, \$panelId, \$branchId\)/,
    );
    assert.doesNotMatch(
        service,
        /->create\(|->save\(|->update\(|->delete\(|lockForUpdate/,
    );
});

test('the header has one h1 and the back link in its actions, never above the title', () => {
    assert.equal((template.match(/<PageHeader/g) ?? []).length, 1);
    assert.doesNotMatch(template, /<h1/);
    const header = template.slice(
        template.indexOf('<PageHeader'),
        template.indexOf('</PageHeader>'),
    );
    assert.match(header, /<template #actions>[\s\S]*Back to Pricing/);
    assert.ok(
        template.indexOf('Back to Pricing') > template.indexOf('<PageHeader'),
    );
    const pricingHeader = pricing.slice(
        pricing.indexOf('<PageHeader'),
        pricing.indexOf('</PageHeader>'),
    );
    assert.match(
        pricingHeader,
        /<template #actions>[\s\S]*variant="outline"[\s\S]*href="\/pricing\/price-check"[\s\S]*Check price list/,
    );
});

test('every filter has a visible connected label', () => {
    for (const id of [
        'price-check-search',
        'price-check-type',
        'price-check-attention',
    ]) {
        assert.ok(template.includes(`id="${id}"`), `no field ${id}`);
        assert.ok(template.includes(`for="${id}"`), `no label for ${id}`);
    }

    assert.match(template, /labelledby="price-check-type-label"/);
});

test('rows show at most two badges, then "+N more" with the full list', () => {
    assert.match(
        page,
        /const shownChecks = \(row: Row\) => row\.checks\.slice\(0, 2\);/,
    );
    assert.match(
        page,
        /const hiddenChecks = \(row: Row\) => row\.checks\.slice\(2\);/,
    );
    assert.equal(
        (template.match(/v-for="check in shownChecks\(row\)"/g) ?? []).length,
        2,
        'cards and table both cap the badges',
    );
    assert.equal(
        (template.match(/All checks: \$\{allChecksText\(row\)\}/g) ?? [])
            .length,
        2,
    );
    assert.match(template, /\+\{\{ hiddenChecks\(row\)\.length \}\}\s*more/);
});

test('"Unit differs" shows both units as text on a card and a focusable disclosure on desktop', () => {
    const cards = template.slice(
        template.indexOf('data-testid="price-check-cards"'),
        template.indexOf('<div class="hidden min-w-0 lg:block">'),
    );
    assert.match(
        cards,
        /<p\s+v-if="row\.checks\.includes\('unit_differs'\)"[\s\S]*data-testid="unit-text"[\s\S]*\{\{ unitText\(row\) \}\}/,
    );
    const table = template.slice(template.indexOf('<OperationalTable'));
    assert.match(table, /<DisclosureText :text="unitText\(row\)" \/>/);
    assert.match(
        page,
        /`Price unit: \$\{row\.chargeUnit \?\? '—'\} · Order unit: \$\{row\.orderUnit \?\? '—'\}`/,
    );
});

test('"Branch price" is muted text, not a badge, and money is tabular RM 0.00', () => {
    assert.equal(
        (
            template.match(
                /class="block text-xs[^"]*text-muted-foreground"\s*>Branch price<\/span/g,
            ) ?? []
        ).length,
        2,
    );
    assert.doesNotMatch(template, /status="branch/);
    assert.match(
        page,
        /`RM \$\{Math\.floor\(sen \/ 100\)\}\.\$\{String\(sen % 100\)\.padStart\(2, '0'\)\}`/,
    );
    assert.ok((template.match(/tabular-nums/g) ?? []).length >= 8);
});

test('"Need attention" is a button that turns the filter on', () => {
    assert.match(
        template,
        /<button\s+type="button"[\s\S]*:aria-pressed="filters\.attention"[\s\S]*data-action="show-attention"[\s\S]*@click="showAttention"/,
    );
    assert.match(
        page,
        /const showAttention = \(\) => \{\s*attention\.value = true;\s*visit\(\{ page: 1 \}\);/,
    );
});

test('empty, filtered-empty, error and no-branch states use the shared components', () => {
    assert.match(page, /'Nothing needs attention\.'/);
    assert.match(page, /'All active items have the prices billing needs\.'/);
    assert.match(template, /<ErrorState/);
    assert.match(template, /Reload/);
    assert.match(template, /title="Select a branch first\."/);
    assert.match(template, /<OperationalTable[\s\S]*label="Price list"/);
});

test('cards below 1024 px shrink instead of widening the page', () => {
    assert.match(template, /class="grid grid-cols-1 gap-3 lg:hidden"/);
    assert.match(
        template,
        /class="grid min-w-0 grid-cols-1 gap-3 rounded-xl border bg-card p-4"/,
    );
    assert.match(template, /<div class="hidden min-w-0 lg:block">/);
});

test('every check has a worded status with a tone', () => {
    const presentation = read('resources/js/lib/presentation.ts');
    const js = ts.transpileModule(presentation, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const { formatStatusLabel, statusTone } = runInNewContext(
        `${js.replaceAll('export ', '')}\n({ formatStatusLabel, statusTone })`,
        {},
    );
    const expected = {
        price_no_charge: ['No charge', 'danger'],
        price_charge_inactive: ['Charge inactive', 'danger'],
        price_no_self_pay: ['No Self-pay price', 'danger'],
        price_unit_differs: ['Unit differs', 'danger'],
        price_no_panel_default: ['No default Panel price', 'warning'],
        price_zero: ['Free (RM 0.00)', 'warning'],
        price_ok: ['OK', 'success'],
    };

    for (const [key, [label, tone]] of Object.entries(expected)) {
        assert.equal(formatStatusLabel(key), label);
        assert.equal(statusTone(key), tone);
    }

    assert.match(
        page,
        /check === 'zero_price' \? 'price_zero' : `price_\$\{check\}`/,
    );
});
