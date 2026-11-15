import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) => readFileSync(p, 'utf8');
const menu = read('resources/js/components/UserMenuContent.vue');
const page = read('resources/js/pages/Spaces/Show.vue');
const css = read('resources/css/space-shell-layout.css');
const clock = read('resources/js/composables/useClockShortcut.ts');

test('DS-12: the account menu lists the own pages from the server, then My profile, Security and Log out', () => {
    assert.match(menu, /page\.props\.workspace\?\.accountMenu/);
    assert.match(menu, /v-for="link in accountMenu\?\.links/);
    assert.match(menu, /My profile/);
    assert.match(menu, /href="\/settings\/security"/);
    assert.match(menu, /data-test="logout-button"/);
    assert.match(menu, /max-lg:min-h-11/);
});

test('DS-12: Me is no longer a nav item and the You heading is gone', () => {
    assert.doesNotMatch(page, /\['You', \['me'\]\]/);
    assert.match(page, /Me lives in the account menu/);
});

test('DS-12: the clock shortcut is an outline button from 1024 px and the first phone menu row', () => {
    assert.match(
        css,
        /@media \(min-width: 1024px\)[\s\S]*\.space-clock[\s\S]*display: inline-flex/,
    );
    assert.match(css, /\.space-clock \{\s*display: none;/);
    assert.match(menu, /lg:hidden/);
    assert.match(menu, /data-test="clock-shortcut-row"/);
});

test('DS-12: the name shows beside the avatar only from 1024 px', () => {
    assert.match(
        css,
        /@media \(max-width: 1023px\)[\s\S]*\.space-account > div[\s\S]*display: none/,
    );
});

test('DS-12: the shortcut reads the attendance state and opens the Attendance page; it never posts a press', () => {
    assert.match(clock, /clock-state/);
    assert.match(clock, /router\.visit\(ATTENDANCE\)/);
    assert.doesNotMatch(
        clock,
        /router\.post|clock-in|clock-out|crypto\.randomUUID/,
    );
});

test('DS-12: the phone clock row hides only where the top-bar button shows, so the two are never visible together', () => {
    assert.match(
        menu,
        /inSpaceShell = computed\(\(\) => page\.url\.startsWith\('\/spaces'\)\)/,
    );
    assert.match(
        menu,
        /:class="\{ 'lg:hidden': inSpaceShell \|\| topBarClock \}"/,
    );
    // UX-24: topBarClock comes from the V2 shell and is true only where its top-bar button is drawn.
    assert.match(menu, /const topBarClock = inject\(\s*TOP_BAR_CLOCK,/);
    assert.doesNotMatch(
        menu,
        /class="cursor-pointer max-lg:min-h-11 lg:hidden"/,
    );
});

test('DS-12: a person with no space and no Clinic management link gets no empty rail', () => {
    assert.match(
        page,
        /const bare = computed\(\(\) => groups\.value\.length === 0 && !hub\.value\)/,
    );
    assert.match(page, /:class="\{ 'is-bare': bare \}"/);
    assert.match(
        css,
        /\.space-shell\.is-bare \.space-side \{\s*display: none;/,
    );
});

test('DS-12: the own-page rows line up with the icon rows and a phone gets a dim layer behind the open menu', () => {
    assert.match(menu, /class="pl-10 max-lg:min-h-11"/);
    assert.match(page, /@update:open="menuOpen = \$event"/);
    assert.match(page, /v-if="menuOpen"[\s\S]*?bg-black\/30 sm:hidden/);
});
