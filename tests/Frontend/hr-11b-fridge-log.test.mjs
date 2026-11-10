import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-11b vaccine fridge log (owner 2026-11-10: "i want to start all that"; order approved: licences, fridge log, audits).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    tab: 'resources/js/components/compliance/FridgeTab.vue',
    settings: 'resources/js/components/compliance/FridgeSettings.vue',
    page: 'resources/js/pages/ClinicCompliance/Index.vue',
};
const src = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);

test('the fridge components compile', () => {
    for (const [key, path] of Object.entries(files)) {
        const tpl = parse(src[key]).descriptor.template.content;
        assert.deepEqual(
            compileTemplate({ id: key, source: tpl, filename: path }).errors,
            [],
            path,
        );
    }
});

test('an out-of-range reading is words and an icon with the danger tone, never colour alone', () => {
    assert.match(src.tab, /TriangleAlert/);
    assert.match(src.tab, /Out of range/);
    assert.match(src.tab, /'danger'/);
    assert.match(src.tab, /border-destructive/);
});

test('every field has a visible label and every server error is shown through InputError', () => {
    for (const id of [
        'reading-temp',
        'reading-low',
        'reading-high',
        'review-note',
    ]) {
        assert.match(src.tab, new RegExp(`<Label for="${id}"`), id);
    }

    for (const id of [
        'fridge-name',
        'fridge-min',
        'fridge-max',
        'fridge-per-day',
        'fridge-expect-by',
    ]) {
        assert.match(src.settings, new RegExp(`<Label for="${id}"`), id);
    }

    for (const field of ['temp', 'low', 'high', 'reason', 'action', 'note']) {
        assert.match(src.tab, new RegExp(`errors\\.${field}`), field);
    }

    for (const field of [
        'branch_id',
        'name',
        'min',
        'max',
        'readings_per_day',
        'expect_by',
    ]) {
        assert.match(src.settings, new RegExp(`errors\\.${field}`), field);
    }
});

test('the reading time is never sent, saves carry an idempotency key, and targets are 44 px below 1024 px', () => {
    assert.doesNotMatch(src.tab, /taken_at/);
    assert.match(src.tab, /crypto\.randomUUID\(\)/);
    assert.match(src.settings, /expected_lock_version/);
    assert.match(src.tab, /min-h-11 lg:min-h-9/);
    assert.match(src.tab, /max-lg:h-11/);
});

test('the Settings tab shows fridges to those who manage them and the page offers the Fridge log tab', () => {
    assert.match(src.page, /FridgeSettings/);
    assert.match(src.page, /compliance\.settings\.fridges/);
    assert.match(src.page, /fridge: 'Fridge log'/);
    assert.match(src.settings, /confirm the range with your pharmacist/i);
});
