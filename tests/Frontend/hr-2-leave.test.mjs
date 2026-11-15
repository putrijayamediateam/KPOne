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

test('Daniel: no primary button in a list; the primary lives in the dialog', () => {
    const approvals = template.slice(
        template.indexOf('<!-- Approvals -->'),
        template.indexOf('<!-- Team calendar -->'),
    );

    assert.match(
        approvals,
        /variant="outline"\s+class="min-h-11 sm:min-h-9"\s+@click="openAction\('approve', r\)"/,
    );
    assert.match(
        template,
        /variant="outline"\s+class="min-h-11 sm:min-h-8"\s+@click="decideEntry\(e, 'confirm'\)"/,
    );
    assert.match(source, /confirm: 'Approve leave'/);
    assert.equal((template.match(/<CalendarCheck/g) ?? []).length, 2);
});

test('Daniel: tabs scroll with a fade on phones, the calendar keeps names sticky, cells have names', () => {
    assert.match(template, /ref="tabStrip"/);
    assert.match(template, /max-sm:\[&>button\]:h-11/);
    assert.match(source, /nav\.scrollLeft \+= tabBox\.right - navBox\.right/);
    assert.match(
        template,
        /Leave for \$\{branchName\}, \$\{calendar\.monthLabel\}/,
    );
    assert.match(template, /class="sticky left-0 z-10 bg-card font-medium"/);
    assert.match(source, /half day' : 'on leave'/);
    assert.match(template, /aria-live="polite"/);
    assert.match(template, /data-test="entitlement-cards"/);
    assert.match(template, /:for="`ent-\$\{t\.id\}-\$\{role\.value\}`"/);
    assert.match(source, /rejected: 'neutral'/);
});
