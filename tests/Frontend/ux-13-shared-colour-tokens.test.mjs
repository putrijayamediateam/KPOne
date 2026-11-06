import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UX-13 (design debt DD-20): shared shell components used fixed palette colours instead of
// the theme tokens. They rendered correctly, but a token change would not have reached them.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const palette =
    /(?:^|[\s'"`:])(?:text|bg|border|ring|fill|stroke|outline)-(?:red|pink|rose|fuchsia|purple|violet|indigo|blue|sky|cyan|teal|emerald|green|lime|yellow|amber|orange|slate|gray|zinc|neutral|stone)-\d{2,3}\b/;

test('UX-13: the top bar, branch switcher and form errors use theme tokens', () => {
    for (const file of [
        'resources/js/components/workspace/WorkspaceHeader.vue',
        'resources/js/components/workspace/WorkspaceBranchSwitcher.vue',
        'resources/js/components/InputError.vue',
    ]) {
        assert.doesNotMatch(read(file), palette, file);
    }

    const header = read(
        'resources/js/components/workspace/WorkspaceHeader.vue',
    );
    assert.match(header, /'border-brand bg-muted text-foreground'/);
    // The drawer's active marker used to be hidden by an always-on border-transparent.
    assert.match(header, /: 'border-transparent text-muted-foreground'/);
    assert.doesNotMatch(header, /border-l-2 border-transparent/);
    assert.match(header, /after:h-0\.5 after:bg-brand'/);
    assert.match(header, /text-brand-strong sm:block/);
    assert.match(
        read('resources/js/components/InputError.vue'),
        /class="text-sm text-destructive"/,
    );
});
