import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const tabs = readFileSync(
    new URL(
        '../../resources/js/components/ui/tabs/OperationalTabs.vue',
        import.meta.url,
    ),
    'utf8',
);

test('DD-40: the shared tab strip fades its right edge while tabs are hidden there', () => {
    assert.match(tabs, /moreTabsRight/);
    assert.match(
        tabs,
        /mask-image:linear-gradient\(to_right,#000_calc\(100%-2\.5rem\),transparent\)/,
    );
    assert.match(
        tabs,
        /scrollLeft \+ element\.clientWidth < element\.scrollWidth - 1/,
    );
    assert.match(tabs, /ResizeObserver/);
    assert.match(tabs, /removeEventListener/);
});
