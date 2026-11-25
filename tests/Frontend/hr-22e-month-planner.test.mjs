import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-22e month planner (HR-PLAN 29.6; owner, via B.E.R.N.I.S.S, 24 Nov 2026).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/HR/Roster.vue');
const composable = read('resources/js/composables/hr/useRosterPage.ts');
const month = read('resources/js/components/hr/roster/PlannerMonth.vue');

test('the planner has a Week | Month switch with its own address key', () => {
    assert.match(page, /data-test="planner-view-switch"/);
    assert.match(page, /aria-label="Show the planner by"/);
    assert.match(page, /:aria-pressed="isPlannerMonth"/);
    assert.match(composable, /plannerView: 'month'/);
    assert.match(composable, /plannerView: undefined/);
    assert.match(page, /data-test="planner-month-switcher"/);
});

test('in month view the week section is replaced by its own month section', () => {
    assert.match(
        page,
        /v-if="roster\.tab === 'planner' && planner && !isPlannerMonth"/,
    );
    assert.match(
        page,
        /v-else-if="roster\.tab === 'planner' && planner && isPlannerMonth"/,
    );
    assert.match(page, /<PlannerMonth/);
    assert.match(page, /data-test="month-copy-result"/);
});

test('each week shows its status, short shifts and a station tag with its full name for screen readers', () => {
    assert.match(month, /data-test="planner-month-week"/);
    assert.match(month, /:status="`roster_\$\{week\.status\}`"/);
    assert.match(month, /cell\.station/);
    assert.match(month, /class="sr-only"\s*>, \{\{ cell\.stationLabel \}\}/);
    assert.match(
        month,
        /The approved week stays in force until this one is\s+approved\./,
    );
    assert.match(month, /<caption class="sr-only">/);
});

test('editing stays in the week view and the copy asks first, with an idempotency key', () => {
    assert.match(month, />Open week</);
    assert.match(month, /Copy to the rest of the month/);
    assert.match(month, /<OperationalConfirmDialog/);
    assert.match(month, /Each week is still sent for approval on its own\./);
    assert.match(composable, /\/copy-to-month`/);
    assert.match(composable, /idempotency_key: crypto\.randomUUID\(\)/);
    assert.doesNotMatch(month, /\/shifts/);
});

test('Daniel: a scroll hint and a sticky Staff column, one button row on phones, a word beside each result', () => {
    assert.match(month, /Scroll sideways for the rest of the week\./);
    assert.match(month, /class="sticky left-0 z-10/);
    assert.match(month, /<span class="sm:hidden">Copy to rest of month<\/span/);
    assert.match(page, /created: 'Draft created'/);
    assert.match(page, /skipped: 'Skipped'/);
    assert.match(page, /:data-outcome="item\.outcome"/);
});
