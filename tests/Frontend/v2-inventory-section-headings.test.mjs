import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('Inventory V2 pages 3 and 4 style their main section heading the same way', () => {
    for (const page of ['GovernedStock', 'TreatmentUsage']) {
        const source = read(
            `resources/js/pages/Inventory/V2/${page}.vue`,
        ).replace(/\s+/g, ' ');
        assert.match(
            source,
            /<h2 id="[^"]+" class="v2-section-title text-lg font-semibold"/,
            page,
        );
    }
});
