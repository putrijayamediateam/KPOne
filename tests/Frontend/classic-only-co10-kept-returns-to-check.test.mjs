// Classic only, CO-10: the Classic half of ui-restyle-d1-returns-to-check, kept when the V2 look was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL(
        '../../resources/js/pages/Dispensary/ReturnsToCheck.vue',
        import.meta.url,
    ),
    'utf8',
);
const flat = page.replace(/\s+/g, ' ');

test('Returns to check uses theme tokens only and no version switch', () => {
    assert.equal(
        /\b(bg|text|border)-(slate|gray|zinc|amber|pink|red|emerald|blue)-\d/.test(
            page,
        ),
        false,
    );
    assert.equal(page.includes('dark:'), false);
    assert.doesNotMatch(page, /useUiVersion|isV2|v2:/);
});

test('the table shows from 768 px and the cards below it', () => {
    assert.ok(flat.includes('<div class="hidden md:block">'));
    assert.ok(
        flat.includes(
            '<ul class="space-y-3 md:hidden" aria-label="Returns to check">',
        ),
    );
});

test('the cards keep their original lines', () => {
    const cards = flat.slice(flat.indexOf('<ul class="space-y-3 md:hidden"'));

    assert.ok(
        cards.includes('space-y-2 rounded-xl border bg-card p-4 text-sm'),
    );
    assert.ok(
        cards.includes('Qty returned {{ row.quantity }} {{ row.unit }} ·'),
    );
    assert.ok(
        cards.includes(
            "{{ row.recordedAt }} · by {{ row.recordedByMe ? 'you' : row.recordedBy }} ·",
        ),
    );
    assert.equal(cards.includes('<dl'), false);
});
