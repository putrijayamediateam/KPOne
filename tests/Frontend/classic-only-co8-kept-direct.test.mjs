// Classic only, CO-8: direct Classic pins for checks that only read a V2 page before it was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Classic agent office shows each step summary as the server sent it (ag-13 line 56)', () => {
    assert.match(
        read('resources/js/pages/Agents/Office.vue'),
        /\{\{ step\.summary \}\}/,
    );
});
