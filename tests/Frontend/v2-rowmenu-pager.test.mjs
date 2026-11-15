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
    assert.match(s, /:aria-label="busy \? 'Working…' : label"/);
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

test('V2RowMenu: the word Actions can sit beside the dots in cards, with the name for screen readers', () => {
    const s = v2('V2RowMenu');
    assert.match(s, /showLabel\?: boolean/);
    assert.match(s, /v-if="showLabel"/);
    assert.match(s, />\s*Actions\s*<\/span/);
});

test('V2RowMenu: an item with an href is a real link, and busy names the trigger Working with a spinner and disables it', () => {
    const s = v2('V2RowMenu');
    assert.match(s, /import \{ Link \} from '@inertiajs\/vue3'/);
    assert.match(s, /href\?: string/);
    assert.match(
        s,
        /<Link[\s\S]*v-if="item\.href && item\.allowed"[\s\S]*:href="item\.href"/,
    );
    assert.match(s, /:as-child="!!\(item\.href && item\.allowed\)"/);
    assert.match(s, /@select="!item\.href && emit\('select', item\.id\)"/);
    assert.match(s, /busy\?: boolean/);
    assert.match(s, /:aria-label="busy \? 'Working…' : label"/);
    assert.match(s, /:aria-busy="busy"/);
    assert.match(s, /:disabled="busy"/);
    assert.match(s, /:open="open && !busy"/);
    assert.match(s, /animate-spin motion-reduce:animate-none/);
});

test('V2RowMenu: a group with an empty label renders no heading', () => {
    const s = read('resources/js/components/v2/V2RowMenu.vue');
    assert.match(s, /<DropdownMenuLabel\s+v-if="group\.label"/);
    assert.match(s, /:key="`\$\{index\}-\$\{group\.label\}`"/);
});

test('V2RowMenu: the open state is kept inside, so a menu opens again after busy ends', () => {
    const s = v2('V2RowMenu');
    assert.match(s, /const open = ref\(false\);/);
    assert.match(s, /:open="open && !busy"/);
    assert.match(s, /@update:open="\(value: boolean\) => \(open = value\)"/);
    // busy closes it
    assert.match(s, /if \(busy\) \{\s*open\.value = false;/);
    // the old always-controlled binding is gone
    assert.doesNotMatch(s, /:open="busy \? false : undefined"/);
});
