import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const bar = read('resources/js/components/workspace/V2NewLookSidebar.vue');
const shell = read('resources/js/components/workspace/V2AppShell.vue');

test('New look step C: the shell shows the two-part sidebar only for the look, and keeps the old one otherwise', () => {
    assert.match(shell, /page\.props\.workspace\?\.newLook === true/);
    assert.match(shell, /<V2NewLookSidebar\s+v-if="!focusMode && newLook"/);
    // The existing desktop sidebar is untouched and only hidden at lg when the look is on.
    assert.match(shell, /:class="\{ 'lg:hidden!': newLook \}"/);
    assert.match(shell, /data-test="v2-shell-sidebar"/);
});

test('New look step C: rail and panel are built from the server shell, with named buttons and the current page marked', () => {
    assert.match(bar, /defineProps<\{ shell: V2Shell; userName: string \}>/);
    assert.match(bar, /props\.shell\.groups\.flatMap/);
    assert.match(bar, /props\.shell\.more\.map/);
    assert.match(bar, /w-\[72px\]/);
    assert.match(bar, /w-\[248px\]/);
    assert.match(bar, /:aria-label="area\.title"/);
    assert.match(bar, /isHere\(shown\.href\) \? 'page'/);
    assert.match(bar, /aria-label="Close panel"/);
    // No counts and no "+" actions yet, and no request of its own.
    assert.doesNotMatch(
        bar.replace(/\/\*[\s\S]*?\*\//g, ''),
        /router\.|fetch\(|axios|\bcount/i,
    );
});

test('New look step C: open/closed is remembered in this browser only, busy areas start closed, motion respects the preference', () => {
    assert.match(bar, /kpone\.newlook\.sidebar/);
    assert.match(bar, /try \{[\s\S]*?localStorage[\s\S]*?\} catch/);
    assert.match(bar, /BUSY = \['consultation', 'billing', 'hr'\]/);
    assert.match(bar, /motion-reduce:transition-none/);
    assert.match(bar, /:inert="!open"/);
});
