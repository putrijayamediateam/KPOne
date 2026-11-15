import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const s = readFileSync(
    new URL(
        '../../resources/js/components/v2/V2StickyBar.vue',
        import.meta.url,
    ),
    'utf8',
);

test('V2StickyBar hideFrom: explicit, wins over the layout, default unchanged, rule written in the docs', () => {
    assert.match(s, /hideFrom\?: 'sm' \| RailFrom/);
    assert.match(s, /hideFrom: undefined/);
    assert.match(s, /sm: 'sm:hidden'/);
    assert.match(s, /if \(props\.hideFrom\)/);
    // The inferred behaviour is still there after the explicit branch.
    assert.match(s, /layoutRailFrom\?\.value/);
    assert.match(s, /exactly once/);
    assert.match(
        s,
        /passes `hideFrom="sm"` so the bar disappears at the\s+\* same width/,
    );
});
