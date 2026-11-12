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
    // DD-75: the same 16 links, grouped, once for lg and once for the phone disclosures, in the same order.
    const grouped = [
        '/inventory/counts',
        '/inventory/writeoffs',
        '/inventory/treatment-usage',
        '/inventory/batch-holds',
        '/inventory/recalls',
        '/inventory/controlled-register',
        '/inventory/purchase-requests',
        '/inventory/reorder-suggestions',
        '/inventory/branch-replenishment',
        '/inventory/supplier-invoices',
        '/inventory/supplier-returns',
        '/inventory/supplier-terms',
        '/inventory/skus',
        '/inventory/stock-review',
        '/inventory/stock-health',
        '/inventory/readiness',
    ];
    assert.deepEqual(links, [...grouped, ...grouped]);
    assert.doesNotMatch(page, /<a\s/);
});

test('DD-75: both link layouts show the controlled register only to its viewers, and the phone count follows', () => {
    const page = template(read('resources/js/pages/Inventory/Index.vue'));
    const guarded = [
        ...page.matchAll(
            /href="\/inventory\/controlled-register"\s+variant="outline"\s+v-if="canViewControlledRegister"/g,
        ),
    ];
    assert.equal(guarded.length, 2);
    assert.equal(
        [...page.matchAll(/href="\/inventory\/controlled-register"/g)].length,
        2,
    );
    assert.match(
        page,
        /Count and adjust ·\s+\{\{ canViewControlledRegister \? 6 : 5 \}\}/,
    );
    assert.match(page, /Orders and suppliers · 7/);
    assert.match(page, /Reports and checks · 3/);
    assert.match(page, /class="hidden w-full gap-6 lg:grid lg:grid-cols-3"/);
    assert.match(page, /class="w-full space-y-2 lg:hidden"/);
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
        // Design review (Daniel): the back link sits alone above the title; any sibling link goes in a row below.
        assert.ok(
            page.indexOf('href="/inventory"') < page.indexOf('<PageHeader'),
            name,
        );
        assert.doesNotMatch(page, /<template #actions>/, name);
        assert.match(
            page,
            /<ActionLink\s+href="\/inventory"\s+variant="ghost"\s+class="-ml-3 min-h-11 self-start"\s*><ArrowLeft class="size-4" aria-hidden="true" \/>\s*Inventory<\/ActionLink/,
            name,
        );
        assert.doesNotMatch(page, /<nav class="flex flex-wrap gap-4">/, name);
    }
});

test('Stock count and Write-off link to each other in a row below the header', () => {
    const page = template(
        read('resources/js/pages/Inventory/GovernedStock.vue'),
    );
    const header = page.indexOf('<PageHeader');
    const row = page.indexOf(
        '<nav aria-label="Other stock control" class="kp-control-group">',
    );
    assert.ok(header > -1 && row > header);
    assert.match(
        page,
        /stock\.mode === 'count'\s+\? '\/inventory\/writeoffs'\s+: '\/inventory\/counts'/,
    );
});

test('the Inventory summary tiles sit in one row at every width, in sentence case', () => {
    const page = template(read('resources/js/pages/Inventory/Index.vue'));
    assert.match(
        page,
        /class="grid grid-cols-3 gap-2 sm:gap-3"\s+aria-label="Inventory summary"/,
    );

    for (const label of [
        'SKUs with stock',
        'Total batches',
        'Expired batches',
    ]) {
        assert.ok(page.includes(`>${label}</p>`), label);
    }
});
