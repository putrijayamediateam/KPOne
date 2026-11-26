import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Clean Look (docs/design/CLEAN-LOOK-SPEC.md section 2): the tokens a page reads, in light and dark, and the contrast
// each text or control pair must keep. Computed from the CSS itself, so a token edit that breaks contrast fails here.
const css = readFileSync('resources/css/app.css', 'utf8');
const block = (selector) => {
    const start = css.indexOf(`${selector} {\n    color-scheme`);
    assert.ok(start >= 0, `${selector} block`);

    return css.slice(start, css.indexOf('\n}\n', start));
};
const tokens = (selector) => {
    const out = {};

    for (const m of block(selector).matchAll(/--([a-z-]+):\s*([^;]+);/g)) {
        out[m[1]] = m[2].trim();
    }

    return out;
};
const light = tokens(':root');
const dark = tokens('.dark');

const rgb = (hex) => {
    const m = /^#([0-9a-f]{6})$/i.exec(hex);
    assert.ok(m, `${hex} must be a 6-digit hex`);

    return [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16));
};
const lum = (hex) => {
    const [r, g, b] = rgb(hex).map((v) => {
        const c = v / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
    const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
};

// [foreground token, background token, minimum ratio]
const PAIRS = [
    ['foreground', 'background', 4.5],
    ['card-foreground', 'card', 4.5],
    ['muted-foreground', 'card', 4.5],
    ['muted-foreground', 'background', 4.5],
    ['primary-foreground', 'primary', 4.5],
    ['link', 'card', 4.5],
    ['link', 'background', 4.5],
    ['success', 'card', 4.5],
    ['success', 'success-wash', 4.5],
    ['warning', 'warning-wash', 4.5],
    ['warning', 'card', 4.5],
    ['info', 'info-wash', 4.5],
    ['destructive', 'card', 4.5],
    ['destructive-foreground', 'destructive', 4.5],
    ['input', 'card', 3],
    ['input', 'field-fill', 3],
    ['ring', 'background', 3],
    ['accent-blue', 'card', 3],
];

for (const [name, set] of [
    ['light', light],
    ['dark', dark],
]) {
    test(`Clean Look ${name} tokens keep their contrast`, () => {
        for (const [fg, bg, min] of PAIRS) {
            // The page background `--background` and the muted/accent washes may be translucent in light; only solid pairs are checked.
            if (!/^#/.test(set[fg] ?? '') || !/^#/.test(set[bg] ?? '')) {
                continue;
            }

            const r = ratio(set[fg], set[bg]);
            assert.ok(
                r >= min,
                `${name}: ${fg} on ${bg} is ${r.toFixed(2)}:1, needs ${min}:1`,
            );
        }
    });
}

test('the accent is blue and pink stays on the logo token only', () => {
    assert.equal(light.primary, '#006ae0');
    assert.equal(light['accent-blue'], '#007aff');
    assert.equal(dark.primary, '#4d94ff');
    assert.equal(light.brand, '#c2185b');
    assert.notEqual(light.ring, light.brand);
});

test('the new tokens exist in both themes and are registered for utilities', () => {
    for (const t of [
        'card-quiet',
        'link',
        'success-wash',
        'warning-wash',
        'info',
        'info-wash',
        'shadow-card',
        'shadow-pop',
    ]) {
        assert.ok(light[t] && dark[t], `${t} in light and dark`);
        assert.ok(
            css.includes(
                `--${t.startsWith('shadow') ? t : `color-${t}`}: var(--${t});`,
            ),
            `${t} registered in @theme`,
        );
    }
});

test('fonts are self-hosted from /fonts and never from a third party', () => {
    assert.match(css, /url\('\/fonts\/geist-latin-wght-normal\.woff2'\)/);
    assert.match(css, /url\('\/fonts\/inter-latin-wght-normal\.woff2'\)/);
    assert.doesNotMatch(css, /fonts\.googleapis|fonts\.gstatic|https?:\/\//);
});
