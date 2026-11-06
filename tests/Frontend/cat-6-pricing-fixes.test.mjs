import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-6 (B.E.R.N.I.S.S for the owner, 2026-11-06): long price book names wrap instead of being cut off, and
// "Current price" shows the version in effect now (pinned by PricingCurrentPriceTest).

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const pricing = read('resources/js/pages/Pricing/Index.vue');
const controller = read('app/Http/Controllers/PricingController.php');

test('both price book selects wrap long names to two lines and keep the list inside the trigger width', () => {
    const flat = pricing.replace(/\s+/g, ' ');
    assert.equal(
        (
            flat.match(
                /:options="bookOptions\(\)" trigger-class="h-auto min-h-9 py-1\.5 text-left whitespace-normal \*:data-\[slot=select-value\]:line-clamp-2 \*:data-\[slot=select-value\]:\[overflow-wrap:anywhere\]" :content-avoid-collisions="false"/g,
            ) ?? []
        ).length,
        2,
    );
});

test('"Current price" uses the version in effect; the publish form keeps the newest version of that book', () => {
    assert.match(
        controller,
        /->lessThanOrEqualTo\(\$now\)\)\s*\?\? \$forCharge->first\(\);/,
    );
    assert.match(
        controller,
        /\$newestInBook = \(int\) \$forCharge->where\('price_book_id', \$inEffect->price_book_id\)->max\('version'\);/,
    );
    assert.match(
        pricing,
        /expected_version: charge\.currentPrice\?\.version \?\? 0/,
    );
});
