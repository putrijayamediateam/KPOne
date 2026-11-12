import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const v2 = (n) => read(`resources/js/components/v2/${n}.vue`);
const code = (n) => v2(n).replace(/\/\*[\s\S]*?\*\//g, '');

test('V2ChipStack: wraps, one column in a phone card, built from V2Chip', () => {
    const s = v2('V2ChipStack');
    assert.match(s, /flex-wrap/);
    assert.match(s, /max-sm:flex-col/);
    assert.match(s, /<V2Chip/);
});

test('V2DateRange: a named From and To pair with an announced order error, 44px', () => {
    const s = v2('V2DateRange');
    assert.match(s, /<fieldset/);
    assert.match(s, /<legend/);
    assert.match(s, /type="date"/);
    assert.match(s, /From must be on or before To\./);
    assert.match(s, /role="alert"/);
    assert.match(s, /h-11[^'"]*lg:h-9/);
});

test('V2Dialog: 18px corners, a phone bottom sheet, a pinned footer, a required reason, focus trap from reka-ui', () => {
    const s = v2('V2Dialog');
    assert.match(s, /from 'reka-ui'/);
    assert.match(s, /rounded-\[18px\]/);
    assert.match(s, /max-sm:bottom-0/);
    assert.match(s, /max-sm:rounded-t-\[18px\]/);
    assert.match(s, /max-h-\[90dvh\]/);
    assert.match(s, /env\(safe-area-inset-bottom\)/);
    assert.match(s, /reasonLabel/);
    assert.match(s, /Enter a reason to continue\./);
    assert.match(s, /tone === 'danger'\s*\?\s*'bg-destructive/);
    assert.match(s, /aria-label="Close"/);
    assert.match(s, /size-11/);
    assert.match(s, /h-11[^'"]*lg:h-9/);
    assert.doesNotMatch(code('V2Dialog'), /fetch\(|axios|router|usePage/);
});

test('The new blocks import nothing from the Classic ui components', () => {
    for (const n of ['V2ChipStack', 'V2DateRange', 'V2Dialog']) {
        assert.doesNotMatch(v2(n), /from '@\/components\/ui\//);
    }
});
