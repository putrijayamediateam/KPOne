import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const chip = read('resources/js/components/v2/V2Chip.vue');
const ring = read('resources/js/components/v2/WaitRing.vue');
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
        .map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
    const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
};

test('V2Chip: a dot plus the word, the dot is decoration', () => {
    assert.match(chip, /aria-hidden="true"/);
    assert.match(chip, /\{\{ label \}\}/);

    for (const tone of ['neutral', 'info', 'success', 'warning', 'danger']) {
        assert.match(chip, new RegExp(`${tone}:`));
    }
});

test('WaitRing: 30 and 60 minute thresholds, minutes shown and spoken with the state', () => {
    assert.match(ring, /warnAt: 30, dangerAt: 60/);
    assert.match(ring, /long wait/);
    assert.match(ring, /very long wait/);
    assert.match(ring, /role="img"/);
    assert.match(ring, /tabular-nums/);
});

test('V2 chip dots and ring strokes reach 3:1 on the card in the Version 2 look, light and dark', () => {
    for (const selector of [
        ":root[data-ui='v2']:not(.dark)",
        ".dark[data-ui='v2']",
    ]) {
        const v2 = block(selector);
        const base = block(selector.startsWith('.dark') ? '.dark' : ':root');
        const t = { ...base, ...v2 };
        const card = rgb(t.card);

        for (const name of [
            'muted-foreground',
            'primary',
            'success',
            'warning',
            'destructive',
        ]) {
            const value = ratio(rgb(t[name]), card);
            assert.ok(value >= 3, `${selector} ${name} ${value.toFixed(2)}`);
        }
    }
});

test('V2Chip: plain drops the dot for a label chip such as a role', () => {
    assert.match(chip, /plain\?: boolean/);
    assert.match(chip, /v-if="!plain"/);
});
