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
    assert.match(s, /inputId\?: string/);
    assert.match(s, /:id="inputId"/);
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
    assert.match(v2('WaitRing'), /min(<template|\s*<\/span)/);
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

test('V2WorkLayout: railFrom puts the rail beside the main panel from lg or from 2xl, with a collapsed slot below 2xl', () => {
    const s = v2('V2WorkLayout');
    assert.match(s, /railFrom\?: RailFrom/);
    assert.match(
        read('resources/js/components/v2/railKey.ts'),
        /RailFrom = 'lg' \| 'xl' \| '2xl'/,
    );
    assert.match(s, /xl:grid-cols-\[minmax\(0,1fr\)_18rem\]/);
    assert.match(s, /2xl:grid-cols-\[minmax\(0,1fr\)_18rem\]/);
    assert.match(s, /hidden 2xl:flex/);
    assert.match(s, /\$slots\['rail-collapsed'\]/);
    assert.match(s, /name="rail-collapsed"/);
    assert.match(s, /'xl:hidden' : '2xl:hidden'/);
    assert.match(s, /provide\(\s*V2_RAIL_FROM/);
    // The default is unchanged: the rail sits beside the main panel from lg.
    assert.match(s, /railFrom: 'lg'/);
    assert.match(s, /lg:grid-cols-\[minmax\(0,1fr\)_21rem\]/);
});

test('V2DisclosureBar: a 44px button tied to its panel with aria-expanded and aria-controls', () => {
    const s = v2('V2DisclosureBar');
    assert.match(s, /:aria-expanded="open"/);
    assert.match(s, /:aria-controls="id"/);
    assert.match(s, /min-h-11[^"]*lg:min-h-9/);
    assert.match(s, /motion-reduce:transition-none/);
    assert.doesNotMatch(s, /from '@\/components\/ui\//);
});

test('V2DisclosureBar: tone warning adds an aria-hidden amber dot, neutral by default', () => {
    const s = v2('V2DisclosureBar');
    assert.match(s, /tone\?: 'neutral' \| 'warning'/);
    assert.match(s, /tone: 'neutral'/);
    assert.match(s, /v-if="tone === 'warning'"/);
    assert.match(s, /bg-warning/);
    assert.match(s, /aria-hidden="true"\n?\s*><\/span>/);
});

test('V2 page title is 24px on phones and 30px from lg', () => {
    const css = read('resources/css/v2.css');
    assert.match(
        css,
        /\.v2-page-title \{[^}]*font-size: 24px;[^}]*line-height: 30px;/,
    );
    assert.match(
        css,
        /min-width: 64rem\) \{\s*\.v2-page-title \{\s*font-size: 30px;/,
    );
});

test('V2WorkLayout: eyebrow is a muted line above the title, outside the heading', () => {
    const s = v2('V2WorkLayout');
    assert.match(s, /eyebrow\?: string/);
    assert.match(s, /v-if="eyebrow"/);
    assert.match(s, /text-muted-foreground"\s*>\s*\{\{ eyebrow \}\}/);
    assert.ok(s.indexOf('{{ eyebrow }}') < s.indexOf('<h1'));
});

test('V2DisclosureBar: keepMounted keeps the panel in the DOM, hidden, while closed', () => {
    const s = v2('V2DisclosureBar');
    assert.match(s, /keepMounted\?: boolean/);
    assert.match(s, /keepMounted: false/);
    assert.match(s, /v-if="keepMounted \|\| open"/);
    assert.match(s, /:hidden="!open"/);
    assert.doesNotMatch(s, /v-show/);
});

test('V2Table: the empty state cell has no padding and the empty block is never wider than its cell', () => {
    const s = v2('V2Table');
    assert.match(s, /<td :colspan="columns" class="!p-0">/);
    assert.match(s, /sticky left-0 flex min-h-28 max-w-full flex-col/);
});

test('V2 table cards: the card grid column can shrink (minmax(0, 1fr)), so an empty state cannot widen the page', () => {
    const css = read('resources/css/v2.css');
    assert.equal(
        (css.match(/grid-template-columns: minmax\(0, 1fr\);/g) ?? []).length >=
            2,
        true,
    );
    assert.match(
        css,
        /\.v2-cards-lg tbody \{\s*display: grid;\s*grid-template-columns: minmax\(0, 1fr\);/,
    );
    assert.match(
        css,
        /\.v2-cards-xl tbody \{\s*display: grid;\s*grid-template-columns: minmax\(0, 1fr\);/,
    );
});

test('V2 table cards: only a cell marked data-wrap breaks a long unbreakable value (overflow-wrap: anywhere); ordinary cells never split words or numbers', () => {
    const css = read('resources/css/v2.css');
    assert.match(
        css,
        /\.v2-cards-lg td\[data-wrap\] \{\s*overflow-wrap: anywhere;/,
    );
    assert.match(
        css,
        /\.v2-cards-xl td\[data-wrap\] \{\s*overflow-wrap: anywhere;/,
    );
    assert.doesNotMatch(css, /\.v2-cards-(lg|xl) td \{\s*overflow-wrap/);
    assert.match(read('resources/js/components/v2/V2Table.vue'), /data-wrap/);
});

test('V2WorkLayout railBelow: default hide is unchanged; stack-top and stack-bottom keep the rail rendered once', () => {
    const s = v2('V2WorkLayout');
    assert.match(s, /railBelow\?: 'hide' \| 'stack-top' \| 'stack-bottom'/);
    assert.match(s, /railBelow: 'hide'/);
    assert.match(s, /hidden 2xl:flex/);
    assert.match(s, /hidden xl:flex/);
    assert.match(s, /order-first xl:order-none/);
    assert.match(s, /order-first 2xl:order-none/);
    assert.match(s, /railBelow === 'hide'/);
});
