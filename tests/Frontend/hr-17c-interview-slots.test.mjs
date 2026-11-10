import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-17c interview slots (HR-PLAN 19.6).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    status: 'resources/js/pages/Careers/Status.vue',
    hr: 'resources/js/pages/HR/Applications.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('both pages compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the candidate ticks exactly three times, in both languages, with 44 px targets', () => {
    const t = template('status');

    for (const id of [
        'status-slots',
        'status-slots-send',
        'status-slots-count',
        'status-picked',
        'status-interview',
        'status-slots-none',
    ]) {
        assert.match(t, new RegExp(`data-test="${id}"`), id);
    }

    assert.match(t, /chosen\.length !== 3/);
    assert.match(t, /Choose your interview times/);
    assert.match(t, /Pilih masa temu duga anda/);
    assert.match(t, /min-h-11 cursor-pointer/);
    assert.match(source.status, /'\/careers\/status\/picks'/);
    assert.doesNotMatch(source.status, /localStorage|sessionStorage/);
});

test('HR confirms one time, asks for new times, or cancels with a reason after a confirm', () => {
    const t = template('hr');

    for (const id of [
        'application-interview',
        'interview-confirm',
        'interview-ask-again',
        'interview-cancel',
        'interview-cancel-reasons',
    ]) {
        assert.ok(t.includes(`data-test="${id}"`), id);
    }

    assert.match(t, /pick\.taken \|\|/);
    assert.match(t, /key: 'today'/);
    assert.match(t, /key: 'picks'/);
    assert.match(t, /title="Cancel this interview\?"/);
    assert.match(source.hr, /interview\/\$\{path\}/);
});

test('Candidate sees collapsible days, their choices with Remove, the time zone and a counted send button (Daniel)', () => {
    const t = template('status');

    for (const id of [
        'status-slots-zone',
        'status-slots-choices',
        'status-slots-count',
    ]) {
        assert.ok(t.includes(`data-test="${id}"`), id);
    }

    assert.match(t, /Your choices \/ Pilihan anda/);
    assert.match(t, /Choose 3 times \/ Pilih 3 masa/);
    assert.match(source.status, /ChevronRight/);
});

test('HR confirms a time through a dialog and moves to Interview only when arranged outside KPOne (Daniel)', () => {
    const t = template('hr');

    assert.match(t, /Arranged outside KPOne/);
    assert.match(t, /`Confirm \$\{confirming\?\.label \?\? ''\}\?`/);
    assert.match(source.hr, /const arrangedOutside = /);
    assert.match(
        source.hr,
        /interviewPost\('confirm', \{ start: confirming\.start \}\)/,
    );
});
