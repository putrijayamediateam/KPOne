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
        /v-if="shortNameParts\(call, displayMode\)"[\s\S]*?class="font-mono text-3xl leading-tight font-bold whitespace-nowrap tabular-nums lg:text-4xl"\s*>\{\{ call\.number \}\}<\/span[\s\S]*?class="text-lg leading-tight font-semibold break-words lg:text-xl"/,
    );
    assert.match(
        screen,
        /announcementText\(call, language, displayMode\.value\)/,
    );
    // Yasmin: the current call scales with screen height so the room line always fits.
    assert.match(screen, /lg:text-\[clamp\(3rem,10vh,6rem\)\]/);
    // Short-name mode shows only whole list rows; a name never ends in an ellipsis.
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
    assert.match(
        settings,
        /Number and short name \(A-012 · Ahmad Ismail\)/,
    );
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
