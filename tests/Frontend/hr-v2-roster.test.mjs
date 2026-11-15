import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR Version 2 Roster: the same props, requests, lock versions and keys as Classic, through one shared composable.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/HR/Roster.vue');
const v2 = read('resources/js/pages/HR/V2/Roster.vue');
const composable = read('resources/js/composables/hr/useRosterPage.ts');
const config = read('config/v2-pages.php');
const checks = read('resources/js/components/hr-v2/RosterChecks.vue');
const rules = read('resources/js/components/hr-v2/ComplianceRulesCards.vue');
const classicRules = read(
    'resources/js/components/hr/compliance/ComplianceRules.vue',
);
const lib = read('resources/js/lib/hr-roster-v2.ts');
const template = parse(v2).descriptor.template.content;
const script = parse(v2).descriptor.scriptSetup.content;

test('the V2 Roster page compiles and is registered for the Version 2 dispatch', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-roster',
            source: template,
            filename: 'Roster.vue',
        }).errors,
        [],
    );
    assert.match(config, /'HR\/Roster',/);
});

test('Classic and V2 take their state and every request from useRosterPage', () => {
    assert.match(classic, /= useRosterPage\(props\);/);
    assert.match(v2, /= useRosterPage\(props\);/);
    assert.match(v2, /defineProps<RosterProps>\(\)/);
    // V2 sends nothing itself: no router calls, no form submits of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(script, /useForm\(/);

    for (const url of [
        "'/hr/roster/drafts'",
        '`/hr/roster/versions/${version.id}/${dialog.action}`',
        '`/hr/roster/versions/${version.id}/shifts`',
        "'/hr/roster/minimum-staffing'",
    ]) {
        assert.ok(composable.includes(url), url);
    }

    assert.match(composable, /expected_version_lock: version.lockVersion/);
    assert.match(composable, /idempotency_key: dialog.key/);
    assert.match(composable, /idempotency_key: shiftKey.value/);
    assert.match(composable, /expected_week_lock_version: p.weekLockVersion/);
    assert.match(composable, /expected_lock_version: m.lockVersion/);
});

test('V2 shows the same tabs and actions only from the server flags', () => {
    for (const flag of [
        'planner.can.decide',
        'planner.can.edit',
        'planner.can.change',
        'planner.can.copy',
        'can.discard',
        'can.submit',
        'can.start',
        'roster.my.canClock',
        'roster.my.canAskOpenShifts',
    ]) {
        assert.ok(v2.includes(flag), flag);
    }

    assert.match(template, /:tabs="tabs"/);
});

test('one filled action per screen; Save minimums is filled only when changed', () => {
    assert.match(script, /const primary = computed<Primary>/);
    assert.match(template, /minimumForm\.isDirty \? primaryButton : button/);
    // Discard lives in the row menu Danger group and confirms in a danger dialog.
    assert.match(script, /label: 'Danger'/);
    assert.match(
        template,
        /dialog\.action === 'discard' \? 'danger' : 'default'/,
    );
});

test('V2 blocks, crumb and the rail from 1536 px with a disclosure bar below it', () => {
    for (const block of [
        '<PageFrame',
        '<V2WorkLayout',
        '<V2Tabs',
        '<V2Dialog',
        '<V2StickyBar',
        '<V2Chip',
        '<V2FormCard',
        '<V2Field',
    ]) {
        assert.ok(template.includes(block), block);
    }

    assert.match(script, /usePageCrumbs\(/);
    assert.match(template, /rail-from="2xl"/);
    assert.match(template, /class="2xl:hidden"/);
    assert.equal((template.match(/<RosterChecks/g) ?? []).length, 2);
    // The rail never outgrows the page.
    assert.match(
        template,
        /sticky top-20 -mx-2 flex max-h-\[calc\(100dvh-6rem\)\] flex-col gap-3 overflow-y-auto/,
    );
    // Round 2: the rail scrolls, fades while more is below, and is reachable by keyboard; no line looks cut.
    assert.match(template, /:style="railMask"/);
    assert.match(script, /black calc\(100% - 3rem\), transparent/);
    assert.match(script, /transparent, black 2rem/);
    assert.match(
        template,
        /tabindex="0"\s+role="region"\s+:aria-label="checksLabel"/,
    );
});

test('minimum staffing fields are typed number fields with 44 px minus and plus buttons on phones', () => {
    assert.match(
        template,
        /type="number"\s+inputmode="numeric"\s+min="0"\s+max="20"/,
    );
    assert.match(template, /w-11 px-0 lg:w-9/);
    assert.match(
        template,
        /:aria-label="`Fewer \$\{role\.plural\}, \$\{session\.label\}`"/,
    );
    assert.match(
        template,
        /:aria-label="`More \$\{role\.plural\}, \$\{session\.label\}`"/,
    );
});

test('no system text: statuses are words, no ISO dates or codes in the template', () => {
    assert.match(script, /statusLabel/);
    assert.doesNotMatch(template, /\{\{\s*(day|shift)\.date\s*\}\}/);
    assert.doesNotMatch(template, /\bCHERAS\b|SUNGAI_BESI|PUCHONG\b/);
});

// Daniel's first V2 Roster review (2026-11-13).
const loadLib = async () => {
    const ts = (await import('typescript')).default;
    const js = ts.transpileModule(lib, {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
        },
    }).outputText;

    return import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );
};

test('checks are grouped by day, one gap per line; other lines are kept as they are', async () => {
    const { groupChecks, gapLine, gapCount } = await loadLib();
    const grouped = groupChecks([
        'Short on Mon 16 Nov: Morning 0 Resident Doctors (minimum 1); Evening 1 CA (minimum 2).',
        'Room 2 is proposed for two people on Tue 17 Nov at 8:00 AM.',
    ]);
    assert.deepEqual(grouped.days, [
        {
            day: 'Mon 16 Nov',
            gaps: ['Morning: 0 of 1 Resident Doctor', 'Evening: 1 of 2 CAs'],
        },
    ]);
    assert.deepEqual(grouped.other, [
        'Room 2 is proposed for two people on Tue 17 Nov at 8:00 AM.',
    ]);
    assert.equal(gapLine('Something new'), 'Something new');
    assert.equal(gapCount(1), '1 gap');
    assert.equal(gapCount(3), '3 gaps');
});

test('the checks panel: first three days open, "Show all days", no repeated chip, rules grouped, rotation in a dialog', () => {
    assert.match(checks, /grouped\.value\.days\.slice\(0, 3\)/);
    assert.match(checks, /`Show all days \(\$\{grouped\.days\.length\}\)`/);
    assert.doesNotMatch(checks, /label="Check"/);
    assert.match(checks, /Below minimum staffing/);
    assert.match(checks, /<V2Dialog/);
    assert.match(
        script,
        /warning: isShort\(d\.date\) \? 'Below minimum' : undefined/,
    );
    assert.doesNotMatch(template, /label="Short"/);
});

test('V2 rules cards send what Classic sends, and show at most one filled Save', () => {
    for (const piece of [
        "'/hr/roster/compliance/coverage'",
        "'/hr/roster/compliance/work-hours'",
        'expected_version: props.settings.coverage.version',
        'expected_version: props.settings.workHours.version',
        'idempotency_key: cover.key',
        'idempotency_key: work.key',
        'min_resident_doctors: cover.min',
        'max_days_in_row: work.days',
    ]) {
        assert.ok(classicRules.includes(piece), `Classic: ${piece}`);
        assert.ok(rules.includes(piece), `V2: ${piece}`);
    }

    assert.match(rules, /props\.primaryAllowed && coverDirty\.value/);
    assert.match(
        rules,
        /props\.primaryAllowed && !coverDirty\.value && workDirty\.value/,
    );
    assert.match(template, /:primary-allowed="!minimumForm\.isDirty"/);
    assert.doesNotMatch(rules, /from '@\/components\/ui\//);
});

test('Session is an option group; a day row opens the editor on a tap; My roster cards are at most 40rem', () => {
    assert.match(
        template,
        /<V2OptionGroup\s+v-model="shiftForm\.session_type"/,
    );
    assert.match(
        template,
        /<V2WeekGrid[\s\S]*?:editable="planner\.can\.edit"[\s\S]*?@open="openShift"/,
    );
    assert.match(script, /if \(shift\) \{\s*openEdit\(shift\);/);
    assert.match(template, /max-w-\[40rem\]/);
});

test('an approved or replaced week reads as notes, never as waiting for approval', () => {
    assert.match(
        script,
        /shown\.value\?\.status === 'draft' \|\| shown\.value\?\.status === 'submitted'/,
    );
    assert.match(script, /things to note on \$\{week\}/);
    assert.match(script, /'this approved week'/);
    assert.match(template, /:rail-label="checksLabel"/);
});

test('the checks panel says "Other notes", not "Other checks", when nothing waits for approval', () => {
    const panel = readFileSync(
        new URL(
            '../../resources/js/components/hr-v2/RosterChecks.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(
        panel,
        /awaitingApproval === false \? 'Other notes' : 'Other checks'/,
    );
    assert.equal(
        (template.match(/:awaiting-approval="awaitingApproval"/g) ?? []).length,
        2,
    );
});

test('rest lines become per-day warnings on the grid, for one day and for a run of days', async () => {
    const { restWarningsByDay } = await loadLib();
    const days = [
        { date: '2026-11-09', label: 'Mon 9', long: 'Mon 9 Nov' },
        { date: '2026-11-10', label: 'Tue 10', long: 'Tue 10 Nov' },
        { date: '2026-11-11', label: 'Wed 11', long: 'Wed 11 Nov' },
        { date: '2026-11-12', label: 'Thu 12', long: 'Thu 12 Nov' },
    ];
    const out = restWarningsByDay(
        [
            {
                kind: 'rest',
                count: 1,
                message: 'Aisyah: 10 h rest before Thu 12 Nov (11 h set)',
            },
            {
                kind: 'rest',
                count: 3,
                message:
                    'Demo Director: 9 to 10 h rest before Mon 9 to Wed 11 Nov, 3 times (12 h set)',
            },
            {
                kind: 'hours',
                count: 1,
                message: 'Aisyah: planned 43 h this week (limit 40 h)',
            },
            {
                kind: 'rest',
                count: 1,
                message: 'Something the grid cannot read',
            },
        ],
        days,
    );
    assert.deepEqual(out, {
        Aisyah: { '2026-11-12': 'Rest under 11 h' },
        'Demo Director': {
            '2026-11-09': 'Rest under 12 h',
            '2026-11-10': 'Rest under 12 h',
            '2026-11-11': 'Rest under 12 h',
        },
    });
});

test('the header carries the filled action from 640 px, so the sticky bar hides from 640 px (one filled action)', () => {
    assert.match(template, /<V2StickyBar v-if="primary" hide-from="sm">/);
    assert.match(template, /:class="\[primaryButton, 'max-sm:hidden'\]"/);
});
