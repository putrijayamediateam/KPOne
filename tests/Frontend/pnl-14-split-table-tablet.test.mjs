import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-14 (design debt DD-42): on tablets the Panel payments split list uses
// the cards too, so the "This payment (RM)" box is never cut off at 768 px.

const page = readFileSync(
    new URL(
        '../../resources/js/pages/PanelRemittances/Index.vue',
        import.meta.url,
    ),
    'utf8',
);

test('The split list switches from cards to the table at lg, not md', () => {
    assert.match(
        page,
        /<ul\s+class="divide-y rounded-xl border lg:hidden"\s+aria-label="Accepted claims waiting for payment"/,
    );
    assert.match(
        page,
        /class="relative hidden max-w-full overflow-x-auto rounded-xl border lg:block"\s+role="region"\s+aria-label="Accepted claims waiting for payment"/,
    );
    assert.doesNotMatch(page, /border md:hidden|border md:block/);
});

test('The payment box on a card is 44 px tall on phones and tablets', () => {
    const cards = page.slice(
        page.indexOf('class="divide-y rounded-xl border lg:hidden"'),
        page.indexOf('lg:block'),
    );
    assert.match(cards, /<input[^>]*class="h-9 w-32[^"]*max-lg:h-11"/s);
});

test('Each card shows a visible "This payment (RM)" label around its box', () => {
    const cards = page.slice(
        page.indexOf('class="divide-y rounded-xl border lg:hidden"'),
        page.indexOf('lg:block'),
    );
    assert.match(
        cards,
        /<label[^>]*data-testid="split-card-payment-label"\s*>\s*This payment \(RM\)\s*<input/,
    );
    assert.match(
        cards,
        /:aria-label="`Amount of this payment for claim \$\{claim\.claimNo\}`"/,
    );
});
