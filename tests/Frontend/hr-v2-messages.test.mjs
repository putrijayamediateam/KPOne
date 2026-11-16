import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Messages: the Version 2 page uses the same composable as Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Messages.vue');
const classic = read('resources/js/pages/HR/Messages.vue');
const composable = read('resources/js/composables/hr/useMessagesPage.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template.replace(/\s+/g, ' ');
const classicFlat = parse(classic).descriptor.template.content.replace(
    /\s+/g,
    ' ',
);

test('the V2 page compiles, is registered, and shares the composable with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-messages',
            source: template,
            filename: 'Messages.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Messages',/);
    assert.match(script, /useMessagesPage\(props\)/);
    assert.match(classic, /useMessagesPage\(props\)/);
    // Every request comes from the composable: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(script, /fetch\(|axios|useForm|crypto\.randomUUID/);
    assert.doesNotMatch(source, /localStorage|sessionStorage|v-html/);
    assert.match(composable, /`\/hr\/messages\/my\/\$\{c\.channel\}`/);
    assert.match(composable, /`\/hr\/messages\/channels\/\$\{s\.channel\}`/);
    assert.match(composable, /expected_lock_version: c\.lockVersion/);
    assert.match(composable, /expected_version: s\.version/);
    assert.equal((composable.match(/idempotency_key:/g) ?? []).length, 2);
});

test('the Classic template did not change: Classic still renders its own markup', () => {
    assert.match(
        classic,
        /<PageHeader\s+eyebrow="KPOne HR"\s+title="Messages"/,
    );
    assert.doesNotMatch(classic, /V2WorkLayout|V2Chip/);
});

test('opt-in and delivery wording is Classic word for word, and messages carry no amount, reason or patient', () => {
    for (const phrase of [
        'Choose how KPOne tells you about your own leave, roster, payslip and claims. Messages never carry an amount, a reason or anything about patients.',
        'You always see these in KPOne. Choose a channel to also get them there. Nothing is on unless you turn it on.',
        'HR has not switched this channel on yet, so nothing is sent for now.',
        'One row per message. The text itself is never kept.',
        'A channel sends only when it is connected, switched on here and under its monthly cap.',
        "The price is the clinic's own figure, used to show this month's cost; KPOne ships no number.",
        'Settings open when this channel is connected.',
    ]) {
        const text = phrase.replace(/\s+/g, ' ');
        // The words may be split by inline tags or sit in an attribute; compare the text with tags removed AND raw.
        const strip = (t) => t.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ');
        const inClassic = classicFlat + ' ' + strip(classicFlat);
        const inV2 = flat + ' ' + strip(flat);
        assert.ok(inClassic.includes(text), `classic: ${text}`);
        assert.ok(inV2.includes(text), `v2: ${text}`);
    }
});

test('a channel that is not connected has no button, only a Not connected chip', () => {
    assert.match(flat, /v-else-if="c\.connected"/);
    assert.match(flat, /label="Not connected"/);
});

test('Daniel: nothing is filled; turn on or off and Save are outline, Save waits for a change', () => {
    assert.doesNotMatch(template, /primaryButton|bg-primary/);
    assert.doesNotMatch(script, /primaryButton|bg-primary/);
    assert.match(
        flat,
        /!s\.connected \|\| !dirty\(s\) \|\| forms\[s\.channel\]\.processing/,
    );
});

test('errors show inside the card that was saved, announced, next to their field', () => {
    assert.match(flat, /errorFor\(s\.channel, 'price'\)/);
    assert.match(flat, /errorFor\(s\.channel, 'monthly_cap'\)/);
    assert.match(flat, /errorFor\(s\.channel, 'enabled'\)/);
    assert.equal((template.match(/role="alert"/g) ?? []).length >= 4, true);
    // The ids the composable focuses on an error exist on the V2 fields.
    assert.match(composable, /`price-\$\{s\.channel\}`/);
    assert.match(template, /:id="`price-\$\{s\.channel\}`"/);
    assert.match(template, /:id="`cap-\$\{s\.channel\}`"/);
});

test('the sent list never shows message text and the page keeps nothing in the browser', () => {
    assert.doesNotMatch(template, /\.body|\.text\b|\.content/);
    assert.match(flat, /\{\{ r\.to \}\}/);
    assert.match(
        flat,
        /\{\{ r\.template \}\} · \{\{ r\.channel \}\} · \{\{ r\.at \}\}/,
    );
});

test('the Sent tab pages and filters through the composable, with Previous and Next disabled at the ends', () => {
    assert.match(flat, /@click="goSent\(messages\.sent\.page - 1\)"/);
    assert.match(flat, /@click="goSent\(messages\.sent\.page \+ 1\)"/);
    assert.match(flat, /:disabled="messages\.sent\.page <= 1"/);
    assert.match(flat, /\(v\) => goSent\(1, String\(v\)\)/);
});
