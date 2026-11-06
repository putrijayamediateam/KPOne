import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-13 (B.E.R.N.I.S.S for the owner, 2026-11-06): the shared print letterhead type carries the CAT-9 second phone,
// and Business details reuses that shape instead of its own copy.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('PrintLetterhead lists phone_secondary beside phone', () => {
    const type = read('resources/js/types/letterhead.ts');
    assert.match(
        type,
        /phone: string \| null;[\s\S]*phone_secondary: string \| null;[\s\S]*email: string \| null;/,
    );
});

test('Business details uses the shared details shape', () => {
    const page = read('resources/js/pages/BusinessDetails/Index.vue');
    assert.match(
        page,
        /import type \{ PrintLetterhead \} from '@\/types\/letterhead';/,
    );
    assert.match(page, /type Details = PrintLetterhead\['details'\];/);
});
