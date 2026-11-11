import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Insights shows treatment-room waste beside recorded wastage', () => {
    const report = read('resources/js/pages/Insights/Report.vue');
    assert.match(report, /label: 'Recorded wastage'/);
    assert.match(report, /label: 'Treatment-room waste'/);
    assert.match(report, /value\.summary\.treatmentWasteUnits/);
    assert.match(report, /Reversed entries are left out\./);
    assert.match(
        read('resources/js/types/insights.ts'),
        /treatmentWasteUnits: number;/,
    );
});
