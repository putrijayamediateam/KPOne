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

test('V2 blocks are V2-only and import nothing from the Classic ui components', () => {
    for (const n of ['V2WorkLayout', 'V2Chip']) {
        assert.doesNotMatch(v2(n), /from '@\/components\/ui\//);
    }
});

test('Debt carried into V2: wrapping chips and balanced titles', () => {
    assert.doesNotMatch(v2('V2Chip'), /whitespace-nowrap/);
    assert.match(v2('V2Chip'), /text-balance/);
    assert.match(css, /\.v2-page-title \{\s*text-wrap: balance;/);
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

test("Daniel's notes: primary field first in cards", () => {
    assert.match(css, /td\[data-primary\]/);
    assert.match(css, /td\[data-actions\] \{\s*order: 99;/);
    assert.match(css, /td\[data-select\] \{\s*order: -2;/);
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
