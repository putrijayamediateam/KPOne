import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('the space shell page uses the server list and has no menu link or mutation', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.match(page, /defineProps<\{ space: Space; spaces: Space\[\] \}>/);
    assert.match(page, /aria-current/);
    assert.match(page, /id="main-content"/);
    assert.doesNotMatch(page, /router\.(post|put|patch|delete)|fetch\(/);
    assert.match(page, /defineOptions\(\{ layout:/);
});

test('the shell touch targets are 44 px and the layout collapses below 768 px', () => {
    const css = read('resources/css/space-shell-layout.css');
    assert.match(css, /\.space-item \{[^}]*min-height: 44px/);
    assert.match(css, /@media \(max-width: 767px\)/);
});

test('spaces are not linked from the menu until the DS-2 switch', () => {
    for (const f of ['resources/js/components/AppShell.vue']) {
        assert.doesNotMatch(read(f), /\/spaces/);
    }
});

test('the shell layout css is imported and every selector is scoped to the spaces shell', () => {
    assert.match(read('resources/css/app.css'), /space-shell-layout\.css/);
    const css = read('resources/css/space-shell-layout.css').replace(
        /\/\*[\s\S]*?\*\//g,
        '',
    );

    for (const [, selector] of css
        .replace(/@media[^{]*\{/g, '')
        .matchAll(/([^{}]+)\{/g)) {
        for (const part of selector.split(',')) {
            assert.match(part.trim(), /^\.space-shell\b/, part);
        }
    }
});
