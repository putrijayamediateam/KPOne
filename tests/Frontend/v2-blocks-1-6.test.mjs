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
    assert.match(v2('WaitRing'), />min(<template|\s*<\/span)/);
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
            /^(\.dark )?\.v2-/,
            `${selector} is not scoped to a V2 class`,
        );
    }
});

test("Daniel's notes: words beside the ring from 30 minutes, primary field first in cards, fade on a cut-off tab strip", () => {
    assert.match(v2('WaitRing'), /words\?: boolean/);
    assert.match(v2('WaitRing'), /v-if="words && level !== 'ok'"/);
    assert.match(v2('WaitRing'), /very long wait/);
    assert.match(css, /td\[data-primary\]/);
    assert.match(css, /td\[data-actions\] \{\s*order: 99;/);
    assert.match(css, /td\[data-select\] \{\s*order: -2;/);
    assert.match(v2('V2Tabs'), /mask-image:linear-gradient/);
    assert.match(v2('V2Tabs'), /ResizeObserver/);
});

test('V2 muted text reaches 4.5:1 on the muted fill and on the page, light and dark', () => {
    const appCss = read('resources/css/app.css');
    const block = (selector) =>
        Object.fromEntries(
            [
                ...appCss
                    .slice(appCss.indexOf(`${selector} {`))
                    .split('}')[0]
                    .matchAll(/--([a-z-]+):\s*(#[0-9a-f]{6});/gi),
            ].map((m) => [m[1], m[2]]),
        );
    const rgb = (hex) =>
        [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const lum = (c) => {
        const [r, g, b] = c
            .map((v) => v / 255)
            .map((v) =>
                v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4,
            );

        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const ratio = (a, b) => {
        const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);

        return (hi + 0.05) / (lo + 0.05);
    };

    for (const [v2sel, base] of [
        [":root[data-ui='v2']:not(.dark)", ':root'],
        [".dark[data-ui='v2']", '.dark'],
    ]) {
        const t = { ...block(base), ...block(v2sel) };

        for (const fill of ['muted', 'background', 'card']) {
            const value = ratio(rgb(t['muted-foreground']), rgb(t[fill]));
            assert.ok(
                value >= 4.5,
                `${v2sel} muted text on ${fill} is ${value.toFixed(2)}`,
            );
        }
    }
});
