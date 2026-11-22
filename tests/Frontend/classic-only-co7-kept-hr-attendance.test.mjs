// Classic only, CO-7: the Classic half of hr-v2-attendance.test.mjs, kept when its V2 page was removed. The V2-only
// date/time helpers (lib/hr-attendance-v2.ts) went with it; the Classic Save checks are carried over and pinned directly.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/HR/Attendance.vue');
const composable = read('resources/js/composables/hr/useAttendancePage.ts');

test('Classic Attendance takes its requests from useAttendancePage', () => {
    assert.match(classic, /useAttendancePage\(props\)/);
    assert.match(composable, /'\/hr\/attendance\/corrections'/);
    assert.match(composable, /'\/hr\/attendance\/settings'/);
});

test('Classic Attendance renders its own markup and no V2 part', () => {
    assert.match(
        classic,
        /<PageHeader\s+eyebrow="KPOne HR"\s+title="Attendance"/,
    );
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
});

test('Classic Settings Save sends without keeping page state, and renews its key once saved', () => {
    assert.match(composable, /const saveSettings = \(\) => sendSettings\(\);/);
    assert.match(classic, /@submit\.prevent="saveSettings"/);
    assert.match(
        composable,
        /onSuccess: \(\) => \{\s+settingsForm\.key = crypto\.randomUUID\(\);/,
    );

    // The Classic overtime and location cards call their composables with no options, so they never keep state.
    assert.match(
        read('resources/js/components/hr/attendance/OvertimeSettingsForm.vue'),
        /useOvertimeSettings\(props\);/,
    );
    assert.match(
        read('resources/js/components/hr/attendance/LocationCheckSettings.vue'),
        /useLocationCheckSettings\(props\);/,
    );
});
