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
    assert.match(template, /class="2xl:hidden"\s+:title="checkTitle"/);
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
