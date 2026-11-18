import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Spaces/Show.vue');
const layout = read('resources/js/layouts/SpacePassThroughLayout.vue');
const shell = read('resources/js/components/workspace/V2AppShell.vue');
const menu = read('resources/js/components/UserMenuContent.vue');
const clock = read('resources/js/composables/useClockShortcut.ts');
const css = read('resources/css/space-shell-layout.css');

test('UX-24 parts 1 and 2: every space home is framed by the V2 shell, drawn by the space layout', () => {
    assert.match(
        read('resources/js/lib/v2-spaces.ts'),
        /export const V2_FRAMED_SPACES: readonly string\[\] = \[\s*'me',\s*'front_desk',\s*'pharmacy',\s*'consultation',\s*'billing',\s*'panel',\s*'stock',\s*'hr',\s*'management',\s*\];/,
    );
    assert.match(
        layout,
        /<V2AppShell v-if="shell" :shell="shell">\s*<slot \/>\s*<\/V2AppShell>\s*<slot v-else \/>/,
    );
    // HR-PHOTO: the camera dialog stays mounted once, framed or not.
    assert.equal(layout.match(/<ClockPhotoDialog \/>/g)?.length, 1);
});

test('UX-24: a framed space drops its own sidebar and top bar (no second menu) and uses the V2 header', () => {
    assert.match(page, /v-if="!v2Shell"\s+ref="side"\s+class="space-side"/);
    assert.match(page, /<div v-if="!v2Shell" class="space-top">/);
    assert.match(
        page,
        /<h1 class="v2-page-title">\{\{ space\.title \}\}<\/h1>/,
    );
    assert.match(page, /<div v-else class="space-head">/);
    assert.match(css, /\.space-shell\[data-v2-frame\] \{\s*display: block;/);
    // The teams' blocks are unchanged.
    assert.match(page, /v-if="pharmacy\.toDispense"/);
    assert.match(page, /<MeHome/);
});

test('UX-24 (owner, 18 Nov 2026): there is one Clock in, on Me home; the shell, the menus and the space pages have none', () => {
    assert.doesNotMatch(shell, /data-test="clock-shortcut"/);
    assert.doesNotMatch(shell, /TOP_BAR_CLOCK|useClockShortcut/);
    assert.doesNotMatch(page, /data-test="v2-space-clock"/);
    assert.doesNotMatch(page, /data-test="clock-shortcut"/);
    assert.doesNotMatch(page, /useClockShortcut/);
    // The account menu: in the new layout the row is a link to Me home (never a second button); Classic keeps its own row.
    assert.match(
        menu,
        /v-if="accountMenu\?\.clock && inNewLayout"[\s\S]*?href="\/spaces\/me"/,
    );
    assert.match(
        menu,
        /v-else-if="accountMenu\?\.clock && clockVisible"[\s\S]*?clock-shortcut-row/,
    );
    assert.match(
        read('resources/js/components/spaces/MeHome.vue'),
        /useClockShortcut/,
    );
    // A press opens the photo dialog (the confirm step); it never posts a time itself.
    assert.doesNotMatch(clock, /router\.post|clock-in'|crypto\.randomUUID/);
});

test('UX-24: the shell defines --v2-topbar-h (the h-16 top bar; 0 in focus mode) for sticky page headers', () => {
    assert.match(
        shell,
        /:style="\{ '--v2-topbar-h': focusMode \? '0px' : '4rem' \}"/,
    );
    assert.match(shell, /sticky top-0 z-40 flex h-16/);
});

test('UX-24 Daniel: the sidebar mark is a small file at first paint; the header actions stack below 360 px', () => {
    assert.match(
        shell,
        /src="\/kp-mark-56\.png"[\s\S]*?width="28"\s+height="28"/,
    );
    assert.match(page, /max-\[359px\]:flex-col max-\[359px\]:items-stretch/);
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
