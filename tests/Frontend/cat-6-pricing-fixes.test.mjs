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
                /:options="bookOptions\(\)" trigger-class="h-auto min-h-11 py-1\.5 data-\[size=default\]:h-auto text-left whitespace-normal md:min-h-9 \*:data-\[slot=select-value\]:line-clamp-2 \*:data-\[slot=select-value\]:\[overflow-wrap:anywhere\]" :content-avoid-collisions="false"/g,
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

test('a price scheduled for later is a muted second line, and the row cells stay top-aligned', () => {
    assert.match(
        pricing,
        /`From \$\{date\}: RM \$\{\(next\.amountSen \/ 100\)\.toFixed\(2\)\}`/,
    );
    assert.equal(
        (pricing.match(/data-testid="scheduled-price"/g) ?? []).length,
        2,
    );
    assert.match(
        pricing.replace(/\s+/g, ' '),
        /label="Charge definitions" class="\[&_td\]:align-top"/,
    );
    assert.match(controller, /'scheduledPrice' => \$latest\?->scheduled,/);
});
