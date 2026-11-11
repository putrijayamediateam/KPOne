import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const template = (source) => source.slice(source.indexOf('<template>'));

// DD-12 and DD-19 (design guide sections 4 and 15): no links above the page title, one style, and no second
// "Return to workspace" beside the header's own button.
test('the Inventory page opens with its header, then one row of task links', () => {
    const page = template(read('resources/js/pages/Inventory/Index.vue'));
    assert.ok(
        page.indexOf('<PageHeader') < page.indexOf('href="/inventory/counts"'),
    );
    assert.doesNotMatch(page, /Return to [Ww]orkspace/);
    assert.match(
        page,
        /<nav aria-label="Inventory tasks" class="kp-control-group">/,
    );
    const links = [
        ...page.matchAll(
            /<ActionLink\s+href="(\/inventory\/[a-z-]+)"\s+variant="outline"/g,
        ),
    ].map((match) => match[1]);
    assert.deepEqual(links, [
        '/inventory/counts',
        '/inventory/writeoffs',
        '/inventory/supplier-returns',
        '/inventory/treatment-usage',
        '/inventory/stock-review',
        '/inventory/batch-holds',
        '/inventory/readiness',
    ]);
    assert.doesNotMatch(page, /<a\s/);
});

test('every governed Inventory page uses the page header with one small back link', () => {
    for (const name of [
        'GovernedStock',
        'SupplierReturns',
        'TreatmentUsage',
        'StockReview',
        'BatchHolds',
        'Readiness',
    ]) {
        const page = template(read(`resources/js/pages/Inventory/${name}.vue`));
        assert.match(page, /<PageHeader/, name);
        assert.doesNotMatch(page, /<h1/, name);
        assert.ok(
            page.indexOf('<PageHeader') < page.indexOf('href="/inventory"'),
            name,
        );
        assert.match(
            page,
            /<ActionLink\s+href="\/inventory"\s+variant="ghost"\s+class="min-h-11"\s*><ArrowLeft class="size-4" aria-hidden="true" \/>\s*Inventory<\/ActionLink/,
            name,
        );
        assert.doesNotMatch(page, /<nav class="flex flex-wrap gap-4">/, name);
    }
});
