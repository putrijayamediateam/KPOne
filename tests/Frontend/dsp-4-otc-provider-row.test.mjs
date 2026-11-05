import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const card = read('resources/js/components/insights/RankingCard.vue');
const today = read('resources/js/pages/Insights/Today.vue');
const report = read('resources/js/pages/Insights/Report.vue');
const types = read('resources/js/types/insights.ts');

test('the fixed OTC row is never sorted or cut on the Today card and stays last', () => {
    assert.ok(types.includes('fixed?: boolean;'));
    assert.ok(card.includes('.filter((item) => !item.fixed)'));
    assert.ok(card.includes('...props.items.filter((item) => item.fixed),'));
    const sorted = card.indexOf('.filter((item) => !item.fixed)');
    const slice = card.indexOf('.slice(0, 10)', sorted);
    const fixed = card.indexOf('...props.items.filter((item) => item.fixed)');
    assert.ok(
        sorted < slice && slice < fixed,
        'the top-10 cut applies to doctors only',
    );
});

test('the View all dialog keeps the fixed row last, and Sales rows are keyed by position', () => {
    assert.ok(today.includes('.filter((row) => !row.fixed)'));
    assert.ok(today.includes('...matching.filter((row) => row.fixed),'));
    assert.ok(report.includes(':key="`${group.title}-${rowIndex}`"'));
});
