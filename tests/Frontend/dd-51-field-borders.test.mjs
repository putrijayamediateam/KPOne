import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
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

test('DD-51: field borders reach 3:1 against the surfaces they sit on, light and dark', () => {
    const light = css.match(/--input:\s*(#[0-9a-f]{6});/gi);
    assert.equal(light.length, 2);
    const [lightBorder, darkBorder] = light.map(
        (line) => line.match(/#[0-9a-f]{6}/i)[0],
    );

    for (const surface of ['#ffffff', '#f8f9fa', '#f4f4f5']) {
        assert.ok(
            ratio(lightBorder, surface) >= 3,
            `${lightBorder} on ${surface} is ${ratio(lightBorder, surface).toFixed(2)}`,
        );
    }

    assert.ok(
        ratio(darkBorder, '#0a0a0a') >= 3,
        `${darkBorder} on the dark page is ${ratio(darkBorder, '#0a0a0a').toFixed(2)}`,
    );
});

test('DD-51: dark field fills use their own token, so darkening the border leaves them as they were', () => {
    assert.match(css, /--color-field-fill: var\(--field-fill\)/);
    const walk = (dir) =>
        readdirSync(dir).flatMap((name) => {
            const path = join(dir, name);

            return statSync(path).isDirectory() ? walk(path) : [path];
        });
    const offenders = walk(
        new URL('../../resources/js', import.meta.url).pathname,
    ).filter(
        (file) =>
            /\.(vue|ts)$/.test(file) &&
            readFileSync(file, 'utf8').includes('bg-input/'),
    );
    assert.deepEqual(offenders, []);
});
