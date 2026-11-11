import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('the space shell page uses the server list and has no menu link or mutation', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.match(page, /defineProps<\{\s*space: Space;\s*spaces: Space\[\];/);
    assert.match(page, /aria-current/);
    assert.match(page, /id="main-content"/);
    assert.doesNotMatch(page, /router\.(put|patch|delete)|fetch\(/);
    assert.equal((page.match(/router\.post\(/g) ?? []).length, 1);
    assert.match(page, /router\.post\(\s*'\/branch-context'/);
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

test('DS-2: the switch lives in the user menu, defaults off and sends the lock version', () => {
    const menu = read('resources/js/components/UserMenuContent.vue');
    assert.match(menu, /router\.put\('\/spaces\/preference'/);
    assert.match(
        menu,
        /lock_version: page\.props\.workspace\?\.spaces\?\.lockVersion/,
    );
    assert.match(menu, /Back to the standard layout/);
    assert.match(menu, /v-if="page\.props\.workspace\?\.spaces\?\.offered"/);
});

test('DS-1: the shell has a named nav, a branch switcher, an account menu, a way back to the existing screens and a right-edge fade', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    const css = read('resources/css/space-shell-layout.css');
    assert.match(page, /<nav aria-label="Spaces"/);
    assert.match(page, /id="space-branch"/);
    assert.match(page, /UserMenuContent/);
    assert.match(page, /Open the existing \{\{ existing\.title \}\} screens/);
    assert.match(page, /scrollIntoView/);
    assert.match(css, /\.has-more/);
    assert.match(css, /\.space-select,[\s\S]*min-height: 44px/);
});

test('DS-3: the Pharmacy widgets render only what the server sent and mutate nothing', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.match(page, /v-if="pharmacy\.toDispense"/);
    assert.match(page, /v-if="pharmacy\.returnsToCheck"/);
});
