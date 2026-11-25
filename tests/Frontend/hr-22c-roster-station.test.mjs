import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-22c station on a shift (HR-PLAN 29.4 and 29.8; owner, 24 Nov 2026).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/HR/Roster.vue');
const composable = read('resources/js/composables/hr/useRosterPage.ts');
const month = read('resources/js/components/hr/roster/MonthCalendar.vue');

test('the shift form offers a station only to a CA, CA Supervisor or MA, never on call', () => {
    assert.match(page, /<div v-if="takesStation" class="grid gap-2">/);
    assert.match(page, /id="shift-station"/);
    assert.match(page, /:message="shiftForm\.errors\.station"/);
    assert.match(composable, /shiftForm\.session_type === 'on_call'/);
    assert.match(composable, /option \? option\.takesStation/);
    assert.match(
        composable,
        /station: data\.station === '' \? null : data\.station/,
    );
    assert.match(composable, /watch\(takesStation/);
});

test('the station shows on the planner, the day list, My roster and the month calendar', () => {
    assert.match(page, /data-test="my-shift-station"/);
    assert.match(page, /shift\.stationLabel/);
    assert.match(page, /item\.shift\.stationLabel/);
    assert.match(month, /shift\.stationLabel/);
});

test('Plan stations is a switch saved with the minimums, a warning only', () => {
    assert.match(page, /data-test="plan-stations"/);
    assert.match(page, /v-model="minimumForm\.plan_stations"/);
    assert.match(
        page,
        /A warning\s+only: weeks can still be sent and approved\./,
    );
    assert.match(composable, /plan_stations: data\.plan_stations/);
});
