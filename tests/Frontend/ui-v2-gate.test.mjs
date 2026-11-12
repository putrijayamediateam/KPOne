import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const css = read('resources/css/app.css');

// The Classic look, as it was before UI-RESTYLE 0.1 (#547). These values must never change for Classic users.
const CLASSIC = {
    root: {
        '--background': '#f8f9fa',
        '--foreground': '#18181b',
        '--primary': '#18181b',
        '--primary-foreground': '#ffffff',
        '--muted': '#f4f4f5',
        '--muted-foreground': '#71717a',
        '--brand': '#db2777',
        '--brand-strong': '#be185d',
        '--success': '#047857',
        '--warning': '#b45309',
        '--destructive': '#b91c1c',
        '--border': '#e4e4e7',
        '--ring': '#db2777',
        '--radius': '0.75rem',
        '--card': '#ffffff',
    },
    dark: {
        '--background': 'hsl(0 0% 3.9%)',
        '--foreground': 'hsl(0 0% 98%)',
        '--primary': 'hsl(0 0% 98%)',
        '--primary-foreground': 'hsl(0 0% 9%)',
        '--muted': 'hsl(0 0% 16.08%)',
        '--muted-foreground': 'hsl(0 0% 63.9%)',
        '--brand': 'hsl(330 81% 60%)',
        '--brand-strong': 'hsl(330 81% 72%)',
        '--success': 'hsl(160 84% 39%)',
        '--warning': 'hsl(38 92% 50%)',
        '--destructive': 'hsl(0 84% 60%)',
        '--border': 'hsl(0 0% 14.9%)',
        '--ring': 'hsl(330 81% 72%)',
        '--card': 'hsl(0 0% 3.9%)',
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

test('Classic: :root and .dark keep the pre-restyle token values', () => {
    for (const [name, selector] of [
        ['root', ':root'],
        ['dark', '.dark'],
    ]) {
        const tokens = block(selector);

        for (const [key, value] of Object.entries(CLASSIC[name])) {
            assert.equal(
                tokens[key],
                value,
                `${selector} ${key} changed for Classic`,
            );
        }
    }
});

test('Version 2: the new look lives only under data-ui="v2"', () => {
    const light = block(":root[data-ui='v2']:not(.dark)");
    const dark = block(".dark[data-ui='v2']");

    assert.equal(light['--primary'], '#0a56d1');
    assert.equal(light['--background'], '#f4f6fb');
    assert.equal(dark['--primary'], '#6ba6ff');
    assert.equal(dark['--background'], '#12141a');
    assert.match(css, /@custom-variant v2 \(&:where\(\[data-ui='v2'\] \*\)\);/);
});

test('Version 2 is set from the saved preference on first paint and on every navigation', () => {
    const blade = read('resources/views/app.blade.php');
    assert.match(blade, /data-ui="\{\{ \$uiVersion \}\}"/);
    assert.match(blade, /\['props'\]\['workspace'\]\['spaces'\]\['enabled'\]/);
    assert.match(blade, /html\[data-ui='v2'\]/);

    const app = read('resources/js/app.ts');
    assert.match(app, /applyUiVersion\(uiVersionFor\(event\.detail\.page\)\)/);
});

test('Guard: the tokens added for Version 2 are never used without a v2: prefix in pages or components', () => {
    const hits = execSync(
        "grep -rnE '(^|[^:a-z-])(bg|text|border|from|to)-(success-soft|warning-soft|destructive-soft|tint-[1-4]|grad-[12])' resources/js --exclude-dir=v2 || true",
        { cwd: new URL('../..', import.meta.url).pathname, encoding: 'utf8' },
    )
        .split('\n')
        .filter((line) => line && !line.includes('v2:'));

    assert.deepEqual(hits, []);
});
