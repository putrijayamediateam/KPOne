import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const tabs = readFileSync(
    new URL('../../resources/js/components/v2/V2Tabs.vue', import.meta.url),
    'utf8',
);

test('V2 tabs fade the left edge too while the strip is scrolled, so the first tab never looks like a stray cut-off word', () => {
    assert.match(tabs, /const before = ref\(false\)/);
    assert.match(tabs, /before\.value = !!el && el\.scrollLeft > 1/);
    assert.match(
        tabs,
        /mask-image:linear-gradient\(to_right,transparent,#000_2\.5rem,#000_calc\(100%-2\.5rem\),transparent\)/,
    );
    assert.match(
        tabs,
        /mask-image:linear-gradient\(to_right,transparent,#000_2\.5rem\)/,
    );
    // The right-edge fade is unchanged.
    assert.match(
        tabs,
        /mask-image:linear-gradient\(to_right,#000_calc\(100%-2\.5rem\),transparent\)/,
    );
});
