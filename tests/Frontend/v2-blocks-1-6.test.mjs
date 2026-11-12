import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const v2 = (n) => read(`resources/js/components/v2/${n}.vue`);
const css = read('resources/css/v2.css');

test('V2WorkLayout: title row, main panel and a right rail that drops below 1024px', () => {
    const s = v2('V2WorkLayout');
    assert.match(s, /lg:grid-cols-\[minmax\(0,1fr\)_21rem\]/);
    assert.match(s, /lg:grid-cols-\[minmax\(0,1fr\)_17rem\]/);
    assert.match(
        s,
        /<aside[\s\S]*v-if="\$slots\.rail"[\s\S]*:aria-label="railLabel"/,
    );
    assert.match(s, /v2-page-title/);
});

test('V2Toolbar: a named search box, More filters disclosure with Reset, 44px below 1024px', () => {
    const s = v2('V2Toolbar');
    assert.match(s, /searchLabel/);
    assert.match(s, /:aria-expanded="open"/);
    assert.match(s, /emit\('reset'\)/);
    assert.match(s, /h-11[^"]*lg:h-9/);
    assert.match(s, /focus-visible:ring-2 focus-visible:ring-ring/);
});

test('V2Tabs: tablist with roving tabindex, arrow keys, trailing slot and the selected tab scrolled into view', () => {
    const s = v2('V2Tabs');
    assert.match(s, /role="tablist"/);
    assert.match(s, /:tabindex="tab\.value === modelValue \? 0 : -1"/);
    assert.match(s, /ArrowRight/);
    assert.match(s, /Home/);
    assert.match(s, /scrollIntoView/);
    assert.match(s, /name="trailing"|\$slots\.trailing/);
    assert.match(s, /rounded-full/);
    assert.match(s, /h-11[^"]*lg:h-9/);
});

test('V2Table: 14px frame, 52px default and 44px dense rows, fade, sticky first column, phone cards', () => {
    const s = v2('V2Table');
    assert.match(s, /rounded-\[14px\]/);
    assert.match(s, /\[&_td\]:py-3\.5/);
    assert.match(s, /\[&_td\]:py-2/);
    assert.match(s, /mask-image:linear-gradient/);
    assert.match(s, /\[&_td:first-child\]:sticky/);
    assert.match(s, /ResizeObserver/);
    assert.match(css, /\.v2-cards-lg td\[data-label\]::before/);
    assert.match(css, /\.v2-cards-xl td\[data-actions\]/);
    assert.match(css, /td\[data-select\] > label/);
});

test('V2 blocks are V2-only and import nothing from the Classic ui components', () => {
    for (const n of [
        'V2WorkLayout',
        'V2Toolbar',
        'V2Tabs',
        'V2Table',
        'V2Chip',
        'WaitRing',
    ]) {
        assert.doesNotMatch(v2(n), /from '@\/components\/ui\//);
    }
});

test('Debt carried into V2: wrapping chips, balanced titles, reduced motion, "min" beside the ring', () => {
    assert.doesNotMatch(v2('V2Chip'), /whitespace-nowrap/);
    assert.match(v2('V2Chip'), /text-balance/);
    assert.match(css, /\.v2-page-title \{\s*text-wrap: balance;/);
    assert.match(v2('V2Tabs'), /motion-reduce:transition-none/);
    assert.match(v2('V2Toolbar'), /motion-reduce:transition-none/);
    assert.match(v2('WaitRing'), />\s*min\s*<\/span/);
});

test('v2.css only matches V2 classes, so Classic is untouched', () => {
    const selectors = css
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/@media[^{]*\{/g, '')
        .split('{')
        .filter((_, i, a) => i < a.length - 1)
        .map((chunk) => chunk.split('}').pop().trim())
        .filter(Boolean)
        .flatMap((group) => group.split(','))
        .map((s) => s.trim());

    assert.ok(selectors.length > 10);

    for (const selector of selectors) {
        assert.match(
            selector,
            /^\.v2-/,
            `${selector} is not scoped to a V2 class`,
        );
    }
});
