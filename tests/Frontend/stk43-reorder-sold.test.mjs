import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL(
        '../../resources/js/pages/Inventory/ReorderSuggestions.vue',
        import.meta.url,
    ),
    'utf8',
);
const flat = page.replace(/\s+/g, ' ');

test('STK-43: each reorder card shows what was sold (dispensed) over 7, 14 and 30 days, with a dash for none', () => {
    assert.match(
        flat,
        /sold7: string \| null; sold14: string \| null; sold30: string \| null;/,
    );
    assert.match(flat, /data-testid="reorder-sold"/);
    assert.match(
        flat,
        /<dt class="text-muted-foreground">Sold \(dispensed\)<\/dt>/,
    );
    assert.match(flat, /const soldWindows = \[7, 14, 30\] as const;/);
    assert.match(flat, /return value === null \? '—' : trimQuantity\(value\);/);
    // Nothing dispensed in 30 days reads in words, not three dashes.
    assert.match(
        flat,
        /<dd v-if="row\.sold30 === null" class="text-muted-foreground" > None in the last 30 days <\/dd>/,
    );
});

test('STK-43: the note says what sold counts and that the suggestion is unchanged', () => {
    assert.match(flat, /data-testid="reorder-sold-note"/);
    assert.match(
        flat,
        /Sold counts what was dispensed to patients from this location, OTC included\. Treatment-room use is not a sale and is not counted\. Medicine returned after dispensing is not taken off\./,
    );
    assert.match(flat, /The suggested quantity still uses the/);
});

test("STK-43: the sold figures spell out the days and stack on a phone (Daniel's review)", () => {
    assert.match(flat, /\{\{ days \}\} days: \{\{ soldText\(row, days\) \}\}/);
    assert.match(
        flat,
        /<ul class="sm:hidden" data-testid="reorder-sold-lines"/,
    );
    assert.match(
        flat,
        /<span class="w-16 text-muted-foreground" >\{\{ days \}\} days<\/span >/,
    );
    assert.match(flat, /<span class="hidden sm:inline"/);
});
