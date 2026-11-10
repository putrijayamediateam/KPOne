import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-14 staff messages (HR-PLAN 15.3; the owner's "start", 2026-11-10: email now, SMS and WhatsApp later).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const path = 'resources/js/pages/HR/Messages.vue';
const source = read(path);
const template = parse(source).descriptor.template.content;

test('the messages page compiles', () => {
    assert.deepEqual(
        compileTemplate({ id: 'messages', source: template, filename: path })
            .errors,
        [],
    );
});

test('a staff member turns a channel on or off with the lock version and a fresh key', () => {
    assert.match(source, /`\/hr\/messages\/my\/\$\{c\.channel\}`/);
    assert.match(source, /expected_lock_version: c\.lockVersion/);
    assert.match(source, /idempotency_key: crypto\.randomUUID\(\)/);
    // A channel that is not connected has no button, only a "Not connected" chip (Daniel, HR-14).
    assert.match(template, /v-else-if="c\.connected"/);
    assert.match(template, /label="Not connected"/);
    assert.match(template, /Nothing is on unless you turn it on\./);
    assert.match(template, /Turn on/);
    assert.match(template, /Turn off/);
});

test('settings send the expected version, a cap and the clinic price, and ship no number', () => {
    assert.match(source, /`\/hr\/messages\/channels\/\$\{s\.channel\}`/);
    assert.match(source, /expected_version: s\.version/);
    assert.match(source, /monthly_cap:/);
    assert.match(template, /KPOne ships no number/);
    assert.match(template, /placeholder="Not set"/);
    assert.match(template, /:disabled="!s\.connected"/);
    // Nothing is saved ahead for a channel that is not connected, and Save is the primary button only with changes.
    assert.match(template, /Settings open when this channel is connected\./);
    assert.match(template, /:variant="dirty\(s\) \? 'default' : 'outline'"/);
    assert.match(template, /· cost \{\{ s\.cost \}\}/);
});

test('the sent list never shows message text, and the page keeps nothing in the browser', () => {
    assert.match(template, /The text itself is never kept\./);
    // The Sent tab says how many it shows and filters by status.
    assert.match(source, /Showing \$\{from\}/);
    assert.match(template, /labelledby="sent-status-label"/);
    assert.doesNotMatch(template, /r\.body|r\.text|r\.subject/);
    assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB/);
    assert.match(
        template,
        /never carry an amount, a reason or anything about patients/,
    );
});
