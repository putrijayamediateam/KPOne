import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const blade = read('resources/views/app.blade.php');

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
    // Text, hover/active text and focus rings read the cobalt link colour; fills keep the black primary.
    assert.match(css, /\[class\*=':text-primary'\]/);
    assert.match(css, /\[class\*='ring-primary'\]/);
    assert.match(css, /\) \{\s*--primary: var\(--link\);\s*\}/);
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
