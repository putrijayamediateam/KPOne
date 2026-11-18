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

test('New look step C: the rail is the ten areas, built from the server shell, with named landmarks and buttons', () => {
    assert.match(bar, /defineProps<\{ shell: V2Shell; userName: string \}>/);
    assert.match(bar, /props\.shell\.groups/);
    assert.match(bar, /props\.shell\.more/);
    const keys = [
        ...bar.matchAll(/\{ key: '([a-z_]+)', title: '[^']+', icon: /g),
    ].map((m) => m[1]);
    assert.deepEqual(keys, [
        'today',
        'front_desk',
        'consultation',
        'pharmacy',
        'billing',
        'panel',
        'stock',
        'hr',
        'reports',
        'settings',
    ]);
    assert.match(bar, /aria-label="Areas"/);
    assert.match(bar, /aria-label="Area pages"/);
    assert.match(bar, /:aria-label="area\.title"/);
    assert.match(bar, /aria-label="Close panel"/);
    assert.match(bar, /isHere\(row\.href\)\s*\? 'page'/);
    assert.match(bar, /w-\[72px\]/);
    assert.match(bar, /w-\[248px\]/);
    // No counts and no "+" actions yet, and no request of its own.
    assert.doesNotMatch(
        bar.replace(/\/\*[\s\S]*?\*\//g, ''),
        /router\.|fetch\(|axios|\bcount/i,
    );
});

test('New look step C: keyboard (one Tab stop, arrows, Home/End, Esc, skip link) and a tooltip that is never clipped', () => {
    assert.match(bar, /:tabindex="tabbable\(index\) \? 0 : -1"/);
    assert.match(bar, /ArrowDown: index \+ 1/);
    assert.match(bar, /Home: 0/);
    assert.match(bar, /End: areas\.value\.length - 1/);
    assert.match(bar, /event\.key === 'Escape'/);
    assert.match(bar, /href="#v2-main"/);
    assert.match(shell, /id="v2-main"\s+tabindex="-1"/);
    // The name shows on hover and on focus, rendered in the body so the rail's scroll box cannot cut it.
    assert.match(bar, /<Teleport to="body">/);
    assert.match(bar, /@focus="[^"]*showTip/);
    assert.match(bar, /@mouseenter="showTip/);
    // The rail scrolls on short windows with a hidden bar and a fade.
    assert.match(bar, /\[scrollbar-width:none\]/);
    assert.match(bar, /data-test="v2-newlook-rail-scroll"/);
    assert.match(bar, /maskImage/);
});

test('New look step C: open/closed is remembered in this browser only, busy areas start closed, motion respects the preference', () => {
    assert.match(bar, /kpone\.newlook\.sidebar/);
    assert.match(bar, /try \{[\s\S]*?localStorage[\s\S]*?\} catch/);
    assert.match(bar, /BUSY = \['consultation', 'billing', 'hr'\]/);
    assert.match(bar, /motion-reduce:transition-none/);
    assert.match(bar, /:inert="!open"/);
    // The selected area has a strong fill in both themes.
    assert.match(bar, /dark:bg-foreground dark:text-background/);
});
