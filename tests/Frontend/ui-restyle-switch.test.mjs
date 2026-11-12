import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const css = read('resources/css/app.css');
const sw = read('resources/js/components/ui/switch/Switch.vue');

const block = (selector) =>
    Object.fromEntries(
        [
            ...css
                .slice(css.indexOf(`${selector} {`))
                .split('}')[0]
                .matchAll(/--([a-z-]+):\s*(#[0-9a-f]{6});/gi),
        ].map((m) => [m[1], m[2]]),
    );
const rgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
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
const mix = (a, b, alpha) => a.map((v, i) => v * alpha + b[i] * (1 - alpha));

test('Switch: a 44px button around a 44x24 track, with a name and a visible word option', () => {
    assert.match(sw, /size-11/);
    assert.match(sw, /h-6 w-11/);
    assert.match(sw, /role="switch"/);
    assert.match(sw, /:aria-label="label"/);
    assert.match(sw, /onText/);
    assert.match(sw, /focus-visible:ring-2 focus-visible:ring-ring/);
});

test('Switch: the knob and the off outline reach 3:1, light and dark', () => {
    for (const selector of [':root', '.dark']) {
        const t = block(selector);
        const card = rgb(t.card);
        const knob = ratio(rgb(t['success-foreground']), rgb(t.success));
        const off = ratio(mix(rgb(t.foreground), card, 0.55), card);

        assert.ok(knob >= 3, `${selector} knob ${knob.toFixed(2)}`);
        assert.ok(off >= 3, `${selector} off outline ${off.toFixed(2)}`);
    }
});
