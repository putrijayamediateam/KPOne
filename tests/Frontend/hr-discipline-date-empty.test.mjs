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

test('Classic keeps Open case off until a date is chosen; the one reason line is built from what is missing', () => {
    assert.match(
        classic,
        /!form\.worker \|\| !form\.incidentOn \|\| form\.allegation\.trim\(\)\.length < 10/,
    );
    assert.match(classic, /v-if="reasonLine" id="case-save-reason"/);
    assert.doesNotMatch(classic, /v-else-if="!form\.incidentOn"/);

    // Every combination of the three requirements, worked out by the composable's own rules.
    const line = (worker, date, allegation) => {
        const choose = [
            ...(worker ? [] : ['a staff file']),
            ...(date ? [] : ['a date']),
        ];
        const write = allegation.trim().length < 10;

        if (!choose.length && !write) {
            return '';
        }

        if (!write) {
            return `Open case: choose ${choose.join(' and ')} first.`;
        }

        if (!choose.length) {
            return 'Open case: write the allegation first.';
        }

        return choose.length === 1
            ? `Open case: choose ${choose[0]} and write the allegation first.`
            : `Open case: choose ${choose.join(', ')}, and write the allegation first.`;
    };

    assert.equal(
        line('', '', ''),
        'Open case: choose a staff file, a date, and write the allegation first.',
    );
    assert.equal(
        line('', '2026-11-03', 'x'.repeat(10)),
        'Open case: choose a staff file first.',
    );
    assert.equal(
        line('w', '', 'x'.repeat(10)),
        'Open case: choose a date first.',
    );
    assert.equal(
        line('', '', 'x'.repeat(10)),
        'Open case: choose a staff file and a date first.',
    );
    assert.equal(
        line('w', '2026-11-03', ''),
        'Open case: write the allegation first.',
    );
    assert.equal(
        line('w', '', ''),
        'Open case: choose a date and write the allegation first.',
    );
    assert.equal(
        line('', '2026-11-03', ''),
        'Open case: choose a staff file and write the allegation first.',
    );
    assert.equal(line('w', '2026-11-03', 'x'.repeat(10)), '');

    // The test's copy of the rules must be the composable's.
    for (const text of [
        "'Open case: write the allegation first.'",
        "`Open case: choose ${choose.join(' and ')} first.`",
        '`Open case: choose ${choose[0]} and write the allegation first.`',
        "`Open case: choose ${choose.join(', ')}, and write the allegation first.`",
    ]) {
        assert.ok(composable.includes(text), text);
    }
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

test('the reason line is tied to the disabled button exactly when the line shows', () => {
    assert.match(
        classic,
        /:aria-describedby=" ?reasonLine \? 'case-save-reason' : undefined ?"/,
    );
});
