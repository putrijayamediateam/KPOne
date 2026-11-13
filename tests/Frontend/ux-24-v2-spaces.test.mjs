import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const squash = (s) => s.replace(/\s+/g, ' ');
const page = read('resources/js/pages/Spaces/Show.vue');
const layout = read('resources/js/layouts/SpacePassThroughLayout.vue');
const shell = read('resources/js/components/workspace/V2AppShell.vue');
const menu = read('resources/js/components/UserMenuContent.vue');
const clock = read('resources/js/composables/useClockShortcut.ts');
const css = read('resources/css/space-shell-layout.css');

test('UX-24 part 1: Me home and Management are framed by the V2 shell, drawn by the space layout', () => {
    assert.match(
        read('resources/js/lib/v2-spaces.ts'),
        /export const V2_FRAMED_SPACES: readonly string\[\] = \['me', 'management'\];/,
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

test('UX-24 Daniel: the clock shortcut is an outline control from 1024 px, never on pages with their own Clock in', () => {
    assert.match(
        clock,
        /const OWN_CLOCK = \['\/spaces\/me', '\/hr\/roster', ATTENDANCE\];/,
    );
    assert.match(
        squash(shell),
        /<Button v-if="showClock" variant="outline" size="sm" class="hidden h-9 lg:inline-flex" data-test="clock-shortcut" @click="pressClock\(\)" >/,
    );
    assert.match(
        squash(shell),
        /accountMenu\.value\?\.clock === true && clockVisible\.value && !pageHasOwnClock\(page\.url\)/,
    );
    // Phones: the header action on framed space homes (not Me) and the account menu row; never in the top bar.
    assert.match(page, /data-test="v2-space-clock"/);
    assert.match(
        squash(page),
        /accountMenu\?\.clock && clockVisible && space\.key !== 'me'/,
    );
    // The menu row hides only where a top-bar button is drawn (Sofia: not in focus mode), so exactly one shows.
    assert.match(
        squash(shell),
        /provide\( TOP_BAR_CLOCK, computed\(\(\) => showClock\.value && !props\.focusMode\), \);/,
    );
    assert.match(
        squash(menu),
        /const topBarClock = inject\( TOP_BAR_CLOCK, computed\(\(\) => false\), \);/,
    );
    // A press opens the photo dialog (the confirm step) or the Attendance page; it never posts a time itself.
    assert.doesNotMatch(clock, /router\.post|clock-in'|crypto\.randomUUID/);
});

test('UX-24: the shell defines --v2-topbar-h (the h-16 top bar; 0 in focus mode) for sticky page headers', () => {
    assert.match(
        shell,
        /:style="\{ '--v2-topbar-h': focusMode \? '0px' : '4rem' \}"/,
    );
    assert.match(shell, /sticky top-0 z-40 flex h-16/);
});

test('UX-24 Daniel: the sidebar mark is a small file at first paint; the header actions stack below 360 px, filled first', () => {
    assert.match(
        shell,
        /src="\/kp-mark-56\.png"[\s\S]*?width="28"\s+height="28"/,
    );
    assert.match(page, /max-\[359px\]:flex-col max-\[359px\]:items-stretch/);
    assert.ok(
        page.indexOf(
            ':href="primaryAction.href"',
            page.indexOf('v2-space-head'),
        ) < page.indexOf('data-test="v2-space-clock"'),
        'the filled space action comes before Clock in',
    );
});

test('UX-24 Daniel: after the photo dialog closes, focus returns to the control that opened it (the menu button for the menu row)', () => {
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
    assert.match(menu, /@select="pressClockAfterMenu"/);
    assert.match(
        squash(menu),
        /pressClock\( document\.querySelector<HTMLElement>\( '\[aria-haspopup="menu"\]\[aria-expanded="true"\]', \), \)/,
    );
});
