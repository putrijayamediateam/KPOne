import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const frame = read('resources/js/components/ui/page-frame/PageFrame.vue');
const bar = read('resources/js/components/ui/page-frame/StickyActionBar.vue');

test('0.3a: the page frame has the four widths and the GUIDE section 19 padding', () => {
    for (const cls of [
        'max-w-4xl',
        'max-w-7xl',
        'max-w-[1500px]',
        'max-w-[1600px]',
    ]) {
        assert.ok(frame.includes(cls), cls);
    }

    assert.match(frame, /px-4 py-6 lg:px-8/);
    assert.match(frame, /gap-6/);
});

test('0.3a: the activity strip renders only when a page passes the slot', () => {
    assert.match(frame, /v-if="\$slots\.activity"/);
});

test('0.3a: the sticky action bar has a solid backdrop, a hairline and phone-sized buttons', () => {
    assert.match(bar, /sticky bottom-0/);
    assert.match(bar, /border-t bg-background /);
    assert.doesNotMatch(bar, /bg-background\//);
    assert.doesNotMatch(bar, /backdrop-blur/);
    assert.match(bar, /max-lg:\[&_button\]:min-h-11/);
});
