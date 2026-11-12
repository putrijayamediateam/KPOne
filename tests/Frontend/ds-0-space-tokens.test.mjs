import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync(
    new URL('../../resources/css/space-shell.css', import.meta.url),
    'utf8',
);
const app = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);

const strip = (text) => text.replace(/\/\*[\s\S]*?\*\//g, '');
const blocks = {};

for (const [, selector, body] of strip(css).matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    blocks[selector.trim()] = Object.fromEntries(
        [...body.matchAll(/--space-([a-z0-9-]+):\s*([^;]+);/g)].map((match) => [
            match[1],
            match[2].trim(),
        ]),
    );
}

// UI-RESTYLE 0.9b: the space colours alias the app tokens, so each value is resolved against app.css before it is
// measured. The result is a plain #rrggbb or rgba() string, as before.
const appBlocks = {};

for (const [, selector, body] of strip(app).matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    appBlocks[selector.trim()] = Object.fromEntries(
        [...body.matchAll(/(?<![\w-])--([a-z0-9-]+):\s*([^;]+);/g)].map((m) => [
            m[1],
            m[2].trim(),
        ]),
    );
}

const appTokens = {
    light: appBlocks[':root'],
    dark: { ...appBlocks[':root'], ...appBlocks['.dark'] },
};
const hexToRgb = (hex) =>
    [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
const toHex = (rgb) =>
    `#${rgb.map((c) => c.toString(16).padStart(2, '0')).join('')}`;
const resolve = (value, mode) => {
    const ref = value.match(/^var\(--([a-z-]+)\)$/);

    if (ref) {
        return resolve(appTokens[mode][ref[1]], mode);
    }

    const mix = value.match(
        /^color-mix\(in srgb, (var\(--[a-z-]+\)) (\d+)%, (var\(--[a-z-]+\)|transparent)\)$/,
    );

    if (!mix) {
        return value;
    }

    const share = Number(mix[2]) / 100;
    const top = hexToRgb(resolve(mix[1], mode));

    if (mix[3] === 'transparent') {
        return `rgba(${top.join(', ')}, ${share})`;
    }

    const bottom = hexToRgb(resolve(mix[3], mode));

    return toHex(
        top.map((c, i) => Math.round(c * share + bottom[i] * (1 - share))),
    );
};
const resolved = (tokens, mode) =>
    Object.fromEntries(
        Object.entries(tokens).map(([k, v]) => [k, resolve(v, mode)]),
    );

const lightRaw = blocks['.space-shell'];
const darkRaw = { ...lightRaw, ...blocks['.dark .space-shell'] };
const light = resolved(lightRaw, 'light');
const dark = resolved(darkRaw, 'dark');

const parse = (value) => {
    const hex = value.match(/^#([0-9a-f]{6})$/i);

    if (hex) {
        return {
            rgb: [0, 2, 4].map((i) => parseInt(hex[1].slice(i, i + 2), 16)),
            alpha: 1,
        };
    }

    const rgba = value.match(/^rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)$/);

    return {
        rgb: [rgba[1], rgba[2], rgba[3]].map(Number),
        alpha: Number(rgba[4]),
    };
};
const over = (top, bottom) =>
    top.rgb.map((channel, i) =>
        Math.round(channel * top.alpha + bottom[i] * (1 - top.alpha)),
    );
const luminance = (rgb) => {
    const [r, g, b] = rgb
        .map((channel) => channel / 255)
        .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
};

const modes = { light, dark };

test('DS-0: every selector is scoped to the spaces shell, so no existing screen changes', () => {
    const selectors = Object.keys(blocks);
    assert.deepEqual(selectors.sort(), ['.dark .space-shell', '.space-shell']);
    assert.match(app, /@import '\.\/space-shell\.css';/);
});

test('DS-0: text and accent colours reach 4.5:1 on every surface they sit on, light and dark', () => {
    for (const [mode, tokens] of Object.entries(modes)) {
        const solid = (name) => parse(tokens[name]).rgb;
        const surfaces = ['bg', 'surface', 'side'];
        const tint = (surface) =>
            over(parse(tokens['accent-tint']), solid(surface));

        for (const surface of surfaces) {
            for (const text of ['fg', 'muted', 'accent', 'ok', 'warn', 'bad']) {
                const value = ratio(solid(text), solid(surface));
                assert.ok(
                    value >= 4.5,
                    `${mode}: ${text} on ${surface} is ${value.toFixed(2)}`,
                );
            }

            const tinted = ratio(solid('accent'), tint(surface));
            assert.ok(
                tinted >= 4.5,
                `${mode}: accent on its tint over ${surface} is ${tinted.toFixed(2)}`,
            );
        }

        const button = ratio(solid('accent-fg'), solid('accent'));
        assert.ok(
            button >= 4.5,
            `${mode}: text on the solid accent is ${button.toFixed(2)}`,
        );
    }
});

test('DS-0: status chip text reaches 4.5:1 on its own 13% tint over the card, light and dark', () => {
    for (const [mode, tokens] of Object.entries(modes)) {
        const card = parse(tokens.surface).rgb;

        for (const name of ['ok', 'warn', 'bad']) {
            const text = parse(tokens[name]).rgb;
            const tint = over({ rgb: text, alpha: 0.13 }, card);
            const value = ratio(text, tint);
            assert.ok(
                value >= 4.5,
                `${mode}: ${name} chip is ${value.toFixed(2)}`,
            );
        }
    }
});

test('DS-0: pink appears once, as the logo mark, and it is the app brand token', () => {
    assert.equal(lightRaw['logo-mark'], 'var(--brand)');
    assert.equal((strip(css).match(/--space-logo-mark:/g) ?? []).length, 1);
    assert.doesNotMatch(strip(css), /#e8348c|#db2777|#ec4899|pink/i);
});

test('DS-0: both modes define every token the shell uses', () => {
    for (const name of Object.keys(light).filter(
        (key) =>
            ![
                'font',
                'radius-card',
                'radius-control',
                'radius-item',
                'sidebar-width',
                'bar-height',
                'logo-mark',
            ].includes(key),
    )) {
        assert.ok(dark[name], `dark mode is missing --space-${name}`);
    }
});
