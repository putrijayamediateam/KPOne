import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// Q-3 gentler TV name mode (owner decisions 2026-11-05): "A-012 · Ahmad Ismail",
// spoken as the number, then the short name, then the room. Number and full-name
// modes render exactly as before.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const module = { exports: {} };
runInNewContext(
    ts.transpileModule(read('resources/js/lib/queue-display.ts'), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports: module.exports, module, Date, Intl, URLSearchParams },
);
const { announcementText, callLabel, shortNameParts } = module.exports;
const call = {
    number: 'A-012',
    name: ' Ahmad Ismail ',
    room: 'Consultation Room 2',
    service: 'consultation',
};

test('short name parts only in short_name mode and only with a usable name', () => {
    assert.deepEqual(
        { ...shortNameParts(call, 'short_name') },
        { number: 'A-012', name: 'Ahmad Ismail' },
    );

    for (const mode of ['number', 'name', undefined]) {
        assert.equal(shortNameParts(call, mode), null, String(mode));
    }

    assert.equal(
        shortNameParts({ number: 'A-012', name: null }, 'short_name'),
        null,
    );
    assert.equal(
        shortNameParts({ number: 'A-012', name: '  ' }, 'short_name'),
        null,
    );
});

test('the spoken call reads the number, the short name, then the room; the dot is never spoken', () => {
    assert.equal(
        announcementText(call, 'en', 'short_name'),
        'A, zero, one, two. Ahmad Ismail. Consultation Room 2.',
    );
    assert.equal(
        announcementText(call, 'ms', 'short_name'),
        'A, kosong, satu, dua. Ahmad Ismail. Consultation Room 2.',
    );
    assert.doesNotMatch(announcementText(call, 'en', 'short_name'), /·/);
    assert.equal(
        announcementText({ ...call, name: null }, 'en', 'short_name'),
        'A, zero, one, two. Consultation Room 2.',
        'no usable name: number only, as in number mode',
    );
    // Full-name mode is unchanged.
    assert.equal(
        announcementText(call, 'en', 'name'),
        'Ahmad Ismail. Consultation Room 2.',
    );
    assert.equal(callLabel({ number: 'A-012' }), 'A-012');
});

test('the TV keeps the number whole: one line with the name in the current call, the name on its own line in the list', () => {
    const screen = read('resources/js/pages/QueueDisplay/Screen.vue');
    assert.match(
        screen,
        /v-if="shortNameParts\(current, displayMode\)"[\s\S]*?<span class="whitespace-nowrap"\s*>\{\{ current\.number \}\} ·<\/span/,
    );
    assert.match(
        screen,
        /v-if="shortNameParts\(call, displayMode\)"[\s\S]*?class="font-mono text-3xl leading-tight font-bold whitespace-nowrap tabular-nums lg:text-4xl"\s*>\{\{ call\.number \}\}<\/span[\s\S]*?class="truncate text-lg leading-tight font-semibold lg:text-xl"/,
    );
    // Yasmin: a row with no usable name keeps the short-mode number size.
    assert.match(
        screen,
        /v-else-if="displayMode === 'short_name'"\s*class="font-mono text-3xl leading-tight font-bold whitespace-nowrap tabular-nums lg:text-4xl"\s*>\{\{ call\.number \}\}/,
    );
    assert.match(
        screen,
        /announcementText\(call, language, displayMode\.value\)/,
    );
    // Yasmin: the current call scales with screen height so the room line always fits.
    assert.match(screen, /lg:text-\[clamp\(3rem,10vh,6rem\)\]/);
    // Short-name mode shows only whole list rows.
    assert.match(
        screen,
        /displayMode === 'short_name'\s*\?\s*'flex min-h-0 flex-1 flex-col flex-wrap content-start overflow-hidden \[&>li\]:w-full'/,
    );
    // Number and full-name modes keep their original markup.
    assert.match(screen, /callLabel\(current\)/);
    assert.match(screen, /callLabel\(call\)/);
});

test('settings offer the three modes in privacy order with help text tied to the select', () => {
    const settings = read('resources/js/pages/QueueDisplay/Settings.vue');
    const order = ['value="number"', 'value="short_name"', 'value="name"'].map(
        (value) => settings.indexOf(value),
    );
    assert.ok(order.every((index) => index > 0));
    assert.deepEqual(
        [...order].sort((a, b) => a - b),
        order,
    );
    // Daniel: the option stays short so it fits a closed 390 px select; the example lives in the help text.
    assert.match(
        settings,
        /<option value="short_name">\s*Number and short name\s*<\/option>/,
    );
    assert.match(settings, /For example A-012 · Ahmad Ismail\./);
    assert.match(settings, /aria-describedby="call-display-mode-help"/);
    assert.match(settings, /id="call-display-mode-help"/);
    assert.match(settings, /No patient name is shown or spoken\./);
    assert.match(
        settings,
        /Everyone in the waiting room sees and hears part of the name\./,
    );
    assert.match(settings, /This is the least private choice\./);
    assert.match(settings, /\(only the first word if both are long\)/);
});

test('Q-3b: on a TV the current call keeps the number and short name on one line, shrinking only to fit', () => {
    // Owner decision 2026-11-05: "keep number and name on one line for 720p".
    const screen = read('resources/js/pages/QueueDisplay/Screen.vue');
    // The normal heading size stays the largest size; the fit only ever makes it smaller.
    assert.match(
        screen,
        /ref="currentLabel"[\s\S]*?lg:w-full lg:text-\[clamp\(3rem,10vh,6rem\)\]"\s*:class="currentLabelOneLine \? 'lg:truncate' : ''"/,
    );
    assert.match(screen, /fontSize: `\$\{currentLabelFontPx\}px`/);
    assert.match(screen, /const FIT_MIN_PX = 24;/);
    assert.match(
        screen,
        /Math\.floor\(\(largest \* label\.clientWidth\) \/ label\.scrollWidth\)/,
    );
    // Only on TV-size screens and only in short-name mode, and never cut: a name that cannot fit wraps instead.
    // Only on 720p-class TVs; larger TVs and phones keep the Q-3 look (B.E.R.N.I.S.S for the owner, 2026-11-05).
    assert.match(
        screen,
        /const FIT_MEDIA = '\(min-width: 1024px\) and \(max-height: 800px\)';/,
    );
    assert.match(screen, /window\.matchMedia\(FIT_MEDIA\)/);
    assert.match(
        screen,
        /currentLabelOneLine\.value = label\.scrollWidth <= label\.clientWidth \+ 1;/,
    );
    assert.match(screen, /window\.addEventListener\?\.\('resize', onResize\)/);
    // Daniel: on those TVs the room line is capped at the call line's size and keeps wrapping, never cut.
    assert.match(screen, /currentRoomFontPx\.value = callPx;/);
    assert.match(
        screen,
        /data-testid="current-room"\s*ref="currentRoom"\s*:style="\s*currentRoomFontPx/,
    );
    // Yasmin: wraps by default until measured, and measures again once the web font is ready.
    assert.match(screen, /const currentLabelOneLine = ref\(false\);/);
    assert.match(screen, /document\.fonts\?\.ready\.then\(onResize\)/);
    assert.match(
        screen,
        /window\.removeEventListener\?\.\('resize', onResize\)/,
    );
});
