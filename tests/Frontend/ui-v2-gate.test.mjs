import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const css = read('resources/css/app.css');

// The Clean Look token anchors (docs/design/CLEAN-LOOK-SPEC.md section 2), which replaced Classic on 25 Nov 2026.
const CLASSIC = {
    root: {
        '--background': '#f5f5f7',
        '--foreground': '#1d1d1f',
        '--primary': '#0852da',
        '--primary-foreground': '#ffffff',
        '--muted-foreground': '#6e6e73',
        '--brand': '#c2185b',
        '--brand-strong': '#9f1239',
        '--success': '#1d7a3a',
        '--warning': '#8a5a00',
        '--destructive': '#c7141f',
        '--ring': '#0a56d1',
        '--radius': '0.875rem',
        '--card': '#ffffff',
    },
    dark: {
        '--background': '#0b0b0d',
        '--foreground': '#f5f5f7',
        '--primary': '#4d94ff',
        '--primary-foreground': '#0b0b0d',
        '--muted': '#2c2c30',
        '--muted-foreground': '#a1a1a6',
        '--brand': '#ff6b9d',
        '--brand-strong': '#ff9ec0',
        '--success': '#30d158',
        '--warning': '#ffb454',
        '--destructive': '#ff6961',
        '--border': '#2a2a2e',
        '--ring': '#6ba6ff',
        '--card': '#141416',
    },
};

const block = (selector) => {
    const start = css.indexOf(`\n${selector} {`);
    assert.notEqual(start, -1, `${selector} block is missing`);

    return Object.fromEntries(
        [
            ...css
                .slice(start)
                .split('\n}\n')[0]
                .matchAll(/(--[a-z0-9-]+):\s*([^;]+);/g),
        ].map((m) => [m[1], m[2].trim()]),
    );
};

test('Clean Look: :root and .dark carry the Clean Look token values', () => {
    for (const [name, selector] of [
        ['root', ':root'],
        ['dark', '.dark'],
    ]) {
        const tokens = block(selector);

        for (const [key, value] of Object.entries(CLASSIC[name])) {
            assert.equal(
                tokens[key],
                value,
                `${selector} ${key} changed from the Clean Look value`,
            );
        }
    }
});
