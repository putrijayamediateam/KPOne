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
