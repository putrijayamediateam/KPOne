import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Owner, 2026-11-11 (#484 follow-up b): HR gives a waiting application more working days.
const page = readFileSync(
    new URL('../../resources/js/pages/HR/Applications.vue', import.meta.url),
    'utf8',
);

test('the application panel offers more working days beside the closing date', () => {
    assert.match(page, /extendDays: number;/);
    assert.match(page, /data-test="application-extend"/);
    assert.match(page, /Give \{\{ viewing\.extendDays \}\} more working days/);
    assert.match(
        page,
        /Counts again from today\. Nothing is sent to the\s+candidate\./,
    );
    assert.match(
        page,
        /\/hr\/applications\/\$\{viewing\.value\.id\}\/extend-deadline/,
    );
    assert.match(page, /expected_lock_version: viewing\.value\.lockVersion/);
    assert.match(page, /If the candidate does not choose times,/);
});
