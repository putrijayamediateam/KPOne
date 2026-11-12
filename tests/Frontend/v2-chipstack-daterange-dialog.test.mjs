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

test('V2Dialog: Confirm is aria-disabled with an empty reason, pressing it shows the error and focuses the box', () => {
    const s = v2('V2Dialog');
    assert.match(s, /:aria-disabled="[^"]*reason\.trim\(\) === ''[^"]*'true'/);
    assert.match(s, /nextTick\(\(\) => box\.value\?\.focus\(\)\)/);
    assert.match(s, /ref="box"/);
});

test('V2Dialog: the filled Confirm text reaches 4.5:1 in the Version 2 look, light and dark', () => {
    const css = read('resources/css/app.css');
    const block = (selector) =>
        Object.fromEntries(
            [
                ...css
                    .slice(css.indexOf(`${selector} {`))
                    .split('}')[0]
                    .matchAll(/--([a-z-]+):\s*(#[0-9a-f]{6}|hsl\([^)]*\));/gi),
            ].map((m) => [m[1], m[2]]),
        );
    const rgb = (value) => {
        const hsl = value.match(
            /^hsl\((\d+(?:\.\d+)?) (\d+(?:\.\d+)?)% (\d+(?:\.\d+)?)%\)$/,
        );

        if (hsl) {
            const [h, s, l] = [
                Number(hsl[1]),
                Number(hsl[2]) / 100,
                Number(hsl[3]) / 100,
            ];
            const a = s * Math.min(l, 1 - l);
            const f = (n) => {
                const k = (n + h / 30) % 12;

                return Math.round(
                    255 * (l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1))),
                );
            };

            return [f(0), f(8), f(4)];
        }

        return [1, 3, 5].map((i) => parseInt(value.slice(i, i + 2), 16));
    };
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

        for (const pair of ['destructive', 'primary']) {
            const value = ratio(rgb(t[`${pair}-foreground`]), rgb(t[pair]));
            assert.ok(
                value >= 4.5,
                `${v2sel} filled ${pair} button text is ${value.toFixed(2)}`,
            );
        }
    }
});
