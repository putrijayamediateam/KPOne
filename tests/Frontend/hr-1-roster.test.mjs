import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/HR/Roster.vue');
// The page's state and actions moved to useRosterPage (shared with the Version 2 page); read both.
const source = `${page}\n${read('resources/js/composables/hr/useRosterPage.ts')}`;
const template = parse(page).descriptor.template.content;

test('HR-1 roster page compiles with one page header and the three tabs', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-roster',
            source: template,
            filename: 'Roster.vue',
        }).errors,
        [],
    );
    assert.equal((template.match(/<PageHeader/g) ?? []).length, 1);
    assert.match(template, /eyebrow="KPOne HR"/);
    assert.match(template, /title="Roster"/);
    assert.match(source, /my: 'My roster'/);
    assert.match(source, /planner: 'Planner'/);
    assert.match(source, /minimums: 'Minimum staffing'/);
    assert.doesNotMatch(source, /Scheduling|MySyarikat/);
});

test('the planner uses shared components, a phone card list and confirm dialogs, never browser confirm', () => {
    assert.match(template, /<OperationalTable/);
    assert.match(template, /class="hidden lg:block"/);
    assert.match(
        template,
        /class="flex flex-col gap-3 lg:hidden"\s+data-test="planner-days"/,
    );
    assert.match(template, /min-h-11 min-w-11/);
    assert.match(template, /<OperationalConfirmDialog/);
    assert.doesNotMatch(source, /window\.confirm|\bconfirm\(/);
    assert.match(template, /<StatusBadge/);
    assert.match(template, /<EmptyState/);
});

test('the roster page uses tokens only, with no palette colours that need a dark style', () => {
    assert.doesNotMatch(
        template,
        /\b(bg|text|border)-(white|black|slate|gray|zinc|red|amber|pink|rose|green|emerald|yellow|blue)-\d*/,
    );
    assert.match(template, /border-l-warning/);
});

test('every shift field has a connected label and an error under it', () => {
    for (const id of [
        'shift-staff',
        'shift-date',
        'shift-start',
        'shift-end',
        'shift-break',
        'shift-session',
        'shift-room',
    ]) {
        assert.match(template, new RegExp(`<Label for="${id}">`), id);
        assert.match(template, new RegExp(`id="${id}"`), id);
    }

    for (const field of [
        'staff_user_id',
        'date',
        'start_time',
        'end_time',
        'break_minutes',
        'session_type',
        'branch_room_id',
    ]) {
        assert.match(
            template,
            new RegExp(
                `<InputError\\s+:message="shiftForm.errors.${field}"\\s*/>`,
            ),
            field,
        );
    }
});

test('every mutation carries the version lock and a fresh idempotency key, and returns to the same branch and week', () => {
    assert.match(source, /expected_version_lock: version.lockVersion/);
    assert.match(source, /dialog.key = crypto.randomUUID\(\)/);
    assert.match(source, /shiftKey.value = crypto.randomUUID\(\)/);
    assert.match(source, /idempotency_key: dialog.key/);
    assert.match(source, /expected_week_lock_version: p.weekLockVersion/);
    assert.match(source, /expected_lock_version: m.lockVersion/);
    assert.match(
        source,
        /branch_id: p.branch.id,\s+week: props.roster.week.start/,
    );
});

test('roster statuses are words with shared tones', () => {
    const presentation = read('resources/js/lib/presentation.ts');

    for (const [key, label] of [
        ['roster_draft', 'Draft'],
        ['roster_submitted', 'Waiting for approval'],
        ['roster_approved', 'Approved'],
        ['roster_superseded', 'Replaced'],
        ['roster_short', 'Short'],
    ]) {
        assert.match(presentation, new RegExp(`${key}: '${label}'`));
    }
});

test('the hub link uses the roster permissions and never the word Scheduling', () => {
    const nav = read('app/Domain/Access/ClinicManagementNavigation.php');
    assert.match(
        nav,
        /'KPOne HR roster', 'Plan branch shifts and see your own roster.', 'hr.roster.index'/,
    );
    assert.match(nav, /hr\.roster\.view\.own/);
});

test('phone Edit buttons name the person, day and times, and the day total stays on one line', () => {
    assert.match(
        template,
        /:aria-label="`Edit shift: \$\{item.row.name\}, \$\{day.long\}, \$\{item.shift.startLabel\} to \$\{item.shift.endLabel\}`"/,
    );
    assert.match(
        template,
        /class="inline-flex items-center gap-2 whitespace-nowrap"\s+data-test="day-total"/,
    );
});
