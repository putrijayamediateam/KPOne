import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const menu = read('resources/js/components/UserMenuContent.vue');
const blade = read('resources/views/app.blade.php');

test('New look (preview): a menu row beside the layout switch, only while the new layout is on', () => {
    assert.match(
        menu,
        /v-if="page\.props\.workspace\?\.spaces\?\.enabled"[\s\S]*?data-test="new-look-row"[\s\S]*?switchLook\(!newLook\)/,
    );
    assert.match(
        menu,
        /'Turn off New look \(preview\)' : 'New look \(preview\)'/,
    );
    assert.match(
        menu,
        /router\.put\('\/spaces\/preference', \{\s*enabled: true,\s*new_look: enabled,/,
    );
});

test('New look (preview): the page gets data-look="new" only in Version 2 and only when switched on', () => {
    assert.match(
        blade,
        /\$uiVersion === 'v2' && \(\$page\['props'\]\['workspace'\]\['newLook'\] \?\? false\) === true\) data-look="new"/,
    );
});

test('New look (preview): the attribute is kept right after an Inertia visit', () => {
    const app = read('resources/js/app.ts');
    const lib = read('resources/js/lib/ui-version.ts');
    assert.match(app, /applyNewLook\(event\.detail\.page\)/);
    assert.match(lib, /dataset\.look = 'new'/);
    assert.match(lib, /delete document\.documentElement\.dataset\.look/);
});

const css = read('resources/css/app.css');

test('New look (preview) step B: tokens and Geist apply only under data-look="new"; Geist is self-hosted, latin only', () => {
    assert.match(
        css,
        /:root\[data-ui='v2'\]\[data-look='new'\]:not\(\.dark\) \{/,
    );
    assert.match(css, /\.dark\[data-ui='v2'\]\[data-look='new'\] \{/);
    assert.match(css, /--background: #f4f4f6;[\s\S]*?--primary: #111114;/);
    assert.match(css, /--link: #0a56d1;/);
    assert.match(
        css,
        /html\[data-look='new'\] \.text-primary \{\s*color: var\(--link\);/,
    );
    assert.match(
        css,
        /html\[data-look='new'\] \.font-sans \{\s*font-family:\s*'Geist Variable'/,
    );
    assert.match(css, /geist-latin-wght-normal\.woff2/);
    assert.doesNotMatch(
        css,
        /geist-latin-ext|fonts\.googleapis|fonts\.gstatic/,
    );
    assert.match(
        blade,
        /html\[data-ui='v2'\]\[data-look='new'\]\.dark \{\s*background-color: #0f0f12;/,
    );
});
