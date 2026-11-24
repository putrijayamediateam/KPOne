import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/Agents/Office.vue');

test('Classic keeps the original office: no version switch, its sheet and its desktop test', () => {
    assert.doesNotMatch(page, /useUiVersion|isV2/);
    // Classic keeps its sheet and its desktop test.
    assert.match(page, /<Sheet/);
    assert.match(page, /isDesktop/);
});
