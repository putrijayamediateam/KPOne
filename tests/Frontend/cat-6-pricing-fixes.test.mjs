import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-6 (B.E.R.N.I.S.S for the owner, 2026-11-06): long price book names wrap instead of being cut off, and
// "Current price" shows the version in effect now (pinned by PricingCurrentPriceTest).

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const pricing = read('resources/js/pages/Pricing/Index.vue');

test('both price book selects wrap long names to two lines and keep the list inside the trigger width', () => {
    const flat = pricing.replace(/\s+/g, ' ');
    assert.equal(
        (
            flat.match(
                /:options="bookOptions\(\)" trigger-class="h-auto min-h-11 py-1\.5 data-\[size=default\]:h-auto text-left whitespace-normal md:min-h-9 \*:data-\[slot=select-value\]:line-clamp-2 \*:data-\[slot=select-value\]:\[overflow-wrap:anywhere\]" :content-avoid-collisions="false"/g,
            ) ?? []
        ).length,
        2,
    );
});

test('"Current price" may have no amount yet; the publish form keeps the newest version of that book', () => {
    // The selection itself is pinned by PricingCurrentPriceTest; here only the page's handling.
    assert.match(pricing, /amountSen: number \| null;/);
    assert.match(
        pricing,
        /charge\.currentPrice && charge\.currentPrice\.amountSen !== null\s*\? `RM \$\{\(charge\.currentPrice\.amountSen \/ 100\)\.toFixed\(2\)\}`\s*: '—'/,
    );
    assert.match(
        pricing,
        /expected_version: charge\.currentPrice\?\.version \?\? 0/,
    );
});

test('a price scheduled for later is a muted second line, and the row cells stay top-aligned', () => {
    assert.match(
        pricing,
        /`From \$\{next\.effectiveLabel\}: RM \$\{\(next\.amountSen \/ 100\)\.toFixed\(2\)\}`/,
    );
    assert.equal(
        (pricing.match(/data-testid="scheduled-price"/g) ?? []).length,
        2,
    );
    assert.match(
        pricing.replace(/\s+/g, ' '),
        /label="Charge definitions" class="\[&_td\]:align-top"/,
    );
    // The date comes from the server in the branch time zone, never the device clock.
    assert.doesNotMatch(pricing, /toLocaleDateString/);
    // Only the muted line may wrap; the amount stays on one line.
    assert.match(
        pricing.replace(/\s+/g, ' '),
        /<td class="text-right tabular-nums"> <span class="whitespace-nowrap">\{\{ priceText\(charge\) \}\}<\/span>/,
    );
});
