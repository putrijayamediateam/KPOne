import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('Output plan detail names have their own column and keep opaque keys secondary', () => {
    const source = readFileSync(
        new URL('../../resources/js/pages/Website/OutputPlans.vue', import.meta.url),
        'utf8',
    );
    assert.match(source, /<th[^>]*scope="col"[^>]*>Name<\/th>/);
    assert.match(source, /page\.detail\.aliasRows/);
    assert.match(source, /<span>\{\{ row\.name \}\}<\/span>/);
    assert.match(source, /<small\b[^>]*>\s*\{\{\s*row\.key\s*\}\}\s*<\/small\s*>/);
    assert.match(source, /\{\{ row\.kind \}\}/);
    assert.match(source, /\{\{ row\.alias \}\}/);
    assert.match(source, /key !== 'aliases'/);
    assert.doesNotMatch(source, /v-html/);
});
