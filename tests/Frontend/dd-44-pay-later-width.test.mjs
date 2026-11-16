import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-44: Bills to collect uses the standard page width (max-w-7xl, as
// PageFrame's default), not a 1600 px container; its 1,120 px table still fits.
const page = readFileSync(
    new URL(
        '../../resources/js/pages/Billing/PayLaterCollections.vue',
        import.meta.url,
    ),
    'utf8',
);

test('DD-44: Bills to collect runs at the standard page width', () => {
    assert.match(
        page,
        /<main class="mx-auto w-full max-w-7xl space-y-4 px-4 py-6 md:px-6">/,
    );
    assert.doesNotMatch(page, /max-w-\[1600px\]/);
    assert.match(page, /min-width="1120px"/);
});
