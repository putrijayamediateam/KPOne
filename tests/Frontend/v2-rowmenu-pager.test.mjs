import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const v2 = (n) => read(`resources/js/components/v2/${n}.vue`);

test('V2RowMenu: grouped items, no permission logic, a disabled item states its reason, 44px targets', () => {
    const s = v2('V2RowMenu');
    assert.match(s, /groups: RowMenuGroup\[\]/);
    assert.match(s, /allowed: boolean/);
    assert.match(s, /reason\?: string/);
    assert.match(s, /!item\.allowed && item\.reason/);
    assert.match(s, /:disabled="!item\.allowed"/);
    assert.match(s, /min-h-11[^"]*lg:min-h-9/);
    assert.match(s, /h-11 min-w-11/);
    assert.match(s, /:aria-label="label"/);
    assert.match(s, /text-destructive/);
    assert.doesNotMatch(
        s.replace(/\/\*[\s\S]*?\*\//g, ''),
        /can\(|usePage|permission|hasRole|router/i,
    );
    assert.doesNotMatch(s, /from '@\/components\/ui\//);
});

test('V2Pager: a count line, Previous and Next at 44px, disabled shown not hidden', () => {
    const s = v2('V2Pager');
    assert.match(s, /of \{\{ total \}\}/);
    assert.match(s, /h-11[^'"]*lg:h-9/);
    assert.match(s, /disabled:border-dashed/);
    assert.doesNotMatch(s, /v-if="currentPage/);
    assert.doesNotMatch(s, /v-show/);
    assert.match(s, /order-first col-span-2/);
});
