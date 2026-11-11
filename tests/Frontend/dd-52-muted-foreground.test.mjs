import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);

const luminance = (hex) => {
    const [r, g, b] = [1, 3, 5]
        .map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
        .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
};

test('DD-52: light muted text reaches 4.5:1 on the page, a card, a muted fill and a read-only field', () => {
    const light = css.match(/--muted-foreground:\s*(#[0-9a-f]{6});/i)[1];
    assert.equal(light.toLowerCase(), '#686870');
    for (const fill of ['#f8f9fa', '#ffffff', '#f4f4f5', '#f6f6f7']) {
        assert.ok(
            ratio(light, fill) >= 4.5,
            `${light} on ${fill} is ${ratio(light, fill).toFixed(2)}`,
        );
    }
});
