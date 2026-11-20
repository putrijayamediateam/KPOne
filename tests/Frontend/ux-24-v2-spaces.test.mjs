import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Spaces/Show.vue');
const shell = read('resources/js/components/workspace/V2AppShell.vue');
const menu = read('resources/js/components/UserMenuContent.vue');
const clock = read('resources/js/composables/useClockShortcut.ts');

test('UX-24 (owner, 18 Nov 2026): there is one Clock in, on Me home; the shell, the menus and the space pages have none', () => {
    assert.doesNotMatch(shell, /data-test="clock-shortcut"/);
    assert.doesNotMatch(shell, /TOP_BAR_CLOCK|useClockShortcut/);
    assert.doesNotMatch(page, /data-test="v2-space-clock"/);
    assert.doesNotMatch(page, /data-test="clock-shortcut"/);
    assert.doesNotMatch(page, /useClockShortcut/);
    // CO-1 (Classic only): the account menu keeps its one Clock in row on every page (no link-row variant any more).
    assert.doesNotMatch(menu, /clock-link-row|inNewLayout/);
    assert.match(
        menu,
        /v-if="accountMenu\?\.clock && clockVisible && !onMeHome"[\s\S]*?clock-shortcut-row/,
    );
    assert.match(
        read('resources/js/components/spaces/MeHome.vue'),
        /useClockShortcut/,
    );
    // A press opens the photo dialog (the confirm step); it never posts a time itself.
    assert.doesNotMatch(clock, /router\.post|clock-in'|crypto\.randomUUID/);
});

test('UX-24 Daniel: after the photo dialog closes, focus returns to the control that opened it', () => {
    assert.match(
        clock,
        /const press = \(returnFocusTo\?: HTMLElement \| null\) => \{/,
    );
    assert.match(clock, /watch\(\s*\(\) => clockPhotoState\.step,/);
    assert.match(clock, /'\[data-test="clock-photo-dialog"\]'/);
    assert.match(clock, /returnFocusTo\.focus\(\);/);
    // Never a removed control, and never while a new flow has opened (Sofia, page-driven closes).
    assert.match(
        clock,
        /clockPhotoState\.step === 'idle' &&\s+returnFocusTo\.isConnected/,
    );
});
