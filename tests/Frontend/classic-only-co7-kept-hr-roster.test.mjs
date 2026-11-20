// Classic only, CO-7: the Classic half of hr-v2-roster.test.mjs, kept when its V2 page was removed. The V2-only grid
// helpers (lib/hr-roster-v2.ts: grouped checks, per-day rest warnings) went with it.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/HR/Roster.vue');
const composable = read('resources/js/composables/hr/useRosterPage.ts');
const classicRules = read(
    'resources/js/components/hr/compliance/ComplianceRules.vue',
);

test('Classic Roster takes its state and every request from useRosterPage', () => {
    assert.match(classic, /= useRosterPage\(props\);/);

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

test('the Classic rules cards send the coverage and work-hours versions with a key', () => {
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
    }
});

test('a new shift starts at 8:00 AM', () => {
    assert.match(composable, /start_time: '08:00'/);
});
