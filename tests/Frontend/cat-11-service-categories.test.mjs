import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-11 (B.E.R.N.I.S.S for the owner, 2026-11-06): the Clinical Service category is a fixed select from the
// server's list, optional, and an older saved value stays as its own option.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/ClinicalService/Index.vue');
const flat = page.replace(/\s+/g, ' ');

test('the category is a labelled fixed select, not a free-text picker', () => {
    assert.doesNotMatch(page, /type="service_category"/);
    assert.match(page, /id="service-category-label"/);
    assert.match(flat, /labelledby="service-category-label"/);
    assert.match(
        flat,
        /describedby="service-category-help service-category-error"/,
    );
    assert.match(flat, /:options="categoryOptions"/);
    assert.match(flat, /Category \(optional\)/);
    assert.match(flat, /<InputError id="service-category-error"/);
});

test('the options come from the server list, start with No category and keep an older value', () => {
    assert.match(page, /serviceCategories: string\[\];/);
    assert.match(flat, /\{ value: '', label: 'No category' \}/);
    assert.match(flat, /props\.setup\.serviceCategories\.map/);
    assert.match(flat, /\(saved before the fixed list\)/);
});
