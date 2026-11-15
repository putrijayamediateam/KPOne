import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const leave = read('resources/js/pages/HR/Leave.vue');
const overtime = read(
    'resources/js/components/hr/attendance/OvertimeSettingsForm.vue',
);
const table = read('resources/js/components/hr/payroll/TableSheet.vue');

test('Leave settings: "Use KPOne defaults" is an outline action that confirms first and posts with a key', () => {
    assert.match(
        leave,
        /variant="outline"[\s\S]{0,120}data-test="use-defaults"[\s\S]{0,40}@click="openYear\('defaults'\)"/,
    );
    assert.match(
        leave,
        /type YearAction = 'grant' \| 'carry-forward' \| 'expire' \| 'defaults';/,
    );
    // The shared year dialog posts to /hr/leave/{kind} with the year and a fresh idempotency key.
    assert.match(leave, /`\/hr\/leave\/\$\{yearAction\.kind\}`/);
    assert.match(leave, /idempotency_key: yearAction\.key/);
    assert.match(leave, /confirm: 'Add these defaults'/);
    assert.match(leave, /data-test="defaults-details"/);
});

test('every default carries the owner-instruction label, and the dialog lists who to check by hand', () => {
    assert.match(
        leave,
        /data-test="defaults-label"[\s\S]{0,40}\{\{ leave\.settings\.defaults\.label \}\}/,
    );
    assert.match(leave, /data-test="defaults-entitlement-label"/);
    assert.match(leave, />KPOne default<\/Badge/);
    assert.match(leave, /· KPOne default<\/span/);
    assert.match(leave, /Check by hand:/);
    assert.match(
        leave,
        /gets annual\s+leave by completed months \(Employment Act section\s+60E\(1A\)\)/,
    );
    assert.doesNotMatch(leave, /full year's days/);
    assert.match(leave, /no employment start date in their staff file/);
});

test('overtime rates and statutory tables say where their numbers come from', () => {
    // Only before the first save: once HR saves rates, they are the clinic's own (Suresh m2).
    assert.match(
        overtime,
        /v-if="!card\.current"\s+class="text-xs text-muted-foreground"\s+data-test="overtime-defaults-label"/,
    );
    assert.match(
        overtime,
        /set by KPOne defaults at the owner's\s+instruction; not professional advice\. Check against current law\s+before real pay\./,
    );
    assert.doesNotMatch(overtime, /clinic HR proposal/);
    assert.match(table, /aria-describedby="table-source-hint"/);
    assert.match(
        table,
        /official KWSP, PERKESO or HRD\s+Corp schedule, never from memory/,
    );
    assert.match(
        table,
        /A second person checks the table before it\s+counts\./,
    );
});
