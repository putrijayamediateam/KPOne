import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';

// The date of a discipline allegation starts empty in Classic and Version 2 (owner decision, 2026-11-16).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const composable = read('resources/js/composables/hr/useDisciplinePage.ts');
const flat = (s) => s.replace(/\s+/g, ' ');
const classic = flat(
    parse(read('resources/js/pages/HR/Discipline.vue')).descriptor.template
        .content,
);
const v2 = flat(
    parse(read('resources/js/pages/HR/V2/Discipline.vue')).descriptor
        .scriptSetup.content,
);

test('opening the form leaves the date empty (no prefill of today)', () => {
    assert.match(composable, /form\.incidentOn = '';/);
    assert.doesNotMatch(
        composable,
        /form\.incidentOn = props\.discipline\.today/,
    );
});

test('Classic keeps Open case off until a date is chosen, with the reason in words', () => {
    assert.match(
        classic,
        /!form\.worker \|\| !form\.incidentOn \|\| form\.allegation\.trim\(\)\.length < 10/,
    );
    assert.match(
        classic,
        /id="case-save-reason"[^>]*> Open case: choose a staff file and a date, and write the allegation first\./,
    );
});

test('Version 2 keeps Open case off until a date is chosen, with the reason in words', () => {
    assert.match(
        v2,
        /!form\.incidentOn \? 'Choose the date it happened to continue\.'/,
    );
});

test('the date is still limited to today by the field; the server keeps its own date rules', () => {
    assert.match(
        read('resources/js/pages/HR/Discipline.vue'),
        /:max="discipline\.today"/,
    );
    assert.match(
        read('resources/js/pages/HR/V2/Discipline.vue'),
        /:max="discipline\.today"/,
    );
});

test('the one reason line is tied to the disabled button, and only while a requirement is missing', () => {
    assert.match(
        classic,
        /:aria-describedby=" ?!form\.worker \|\| !form\.incidentOn \|\| form\.allegation\.trim\(\)\.length < 10 \? 'case-save-reason' : undefined ?"/,
    );
    assert.doesNotMatch(classic, /v-else-if="!form\.incidentOn"/);
});
