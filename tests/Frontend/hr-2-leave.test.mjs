import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/Leave.vue');
const template = parse(source).descriptor.template.content;
const roster = read('resources/js/pages/HR/Roster.vue');

test('HR-2a leave page compiles with one page header and the four tabs', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-leave',
            source: template,
            filename: 'Leave.vue',
        }).errors,
        [],
    );
    assert.equal((template.match(/<PageHeader/g) ?? []).length, 1);
    assert.match(template, /eyebrow="KPOne HR"/);
    assert.match(template, /title="Leave"/);
    assert.match(
        template,
        /description="Apply for leave and see your balance\. Someone else approves each request\."/,
    );

    for (const label of [
        "my: 'My leave'",
        "approvals: 'Approvals'",
        "calendar: 'Team calendar'",
        "settings: 'Settings'",
    ]) {
        assert.ok(source.includes(label), label);
    }

    assert.match(template, /max-w-7xl/);
    assert.doesNotMatch(source, /MySyarikat/);
});

test('empty states and toasts use the agreed wording', () => {
    assert.match(template, /title="Leave is not set up yet\."/);
    assert.match(
        template,
        /description="Ask HR to add the leave types for the clinic\."/,
    );
    assert.match(template, /title="No leave requests yet\."/);
    assert.match(template, /title="Nothing waiting for you\."/);
    assert.match(template, /Do not write medical details here\./);
    assert.match(
        template,
        /For example, the MC serial number\. HR checks the\s+paper copy\./,
    );
});

test('shared components only: tables on wide screens, cards on phones, confirm dialogs, no browser confirm', () => {
    assert.match(template, /<OperationalTable/);
    assert.match(template, /<OperationalConfirmDialog/);
    assert.match(template, /<Sheet /);
    assert.match(template, /<StatusBadge/);
    assert.match(template, /class="hidden lg:block"/);
    assert.match(template, /lg:hidden/);
    assert.doesNotMatch(source, /window\.confirm|\balert\(/);
    assert.match(template, /min-h-11/);
});

test('tokens only, no palette colours that need a dark style', () => {
    assert.doesNotMatch(
        template,
        /\b(bg|text|border)-(white|black|slate|gray|zinc|red|amber|pink|rose|green|emerald|yellow|blue)-\d*/,
    );
});

test('every apply field has a connected label and the evidence field shows only for types that need it', () => {
    for (const id of [
        'apply-type',
        'apply-start',
        'apply-end',
        'apply-reason',
    ]) {
        assert.match(template, new RegExp(`<Label for="${id}"`), id);
        assert.match(template, new RegExp(`id="${id}"`), id);
    }

    assert.match(template, /v-if="chosenType\?\.evidenceNeeded"/);
    assert.match(template, /v-if="chosenType\?\.allowHalfDays"/);
    assert.match(source, /idempotency_key: applyKey\.value/);
});

test('approve asks for "Evidence seen" and reject or manager cancel need a reason', () => {
    assert.match(template, /<Label for="evidence-seen">Evidence seen<\/Label>/);
    assert.match(template, /<Label for="action-reason">Reason \*<\/Label>/);
    assert.match(
        source,
        /Tick "Evidence seen" after checking the paper copy\./,
    );
    assert.match(source, /action\.key = crypto\.randomUUID\(\)/);
});

test('the team calendar and the roster show "On leave" only, never the leave type', () => {
    const calendar = template.slice(
        template.indexOf('<!-- Team calendar -->'),
        template.indexOf('<!-- Settings -->'),
    );
    assert.doesNotMatch(calendar, /\.type\b/);
    assert.match(calendar, /On leave/);
    assert.match(roster, /data-test="on-leave-chip"/);
    assert.match(roster, /'On leave \(half day\)'/);
    assert.match(
        roster,
        /On leave: \{\{ onLeave\(day\.date\)\.join\(', '\) \}\}/,
    );
});
