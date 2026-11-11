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
    // Owner "(b)": at most 2 in one stage, shown as "1 of 2 extensions used", then disabled with a reason.
    assert.match(page, /extensionsUsed: number;/);
    assert.match(
        page,
        /\{\{ viewing\.extensionsUsed \}\} of\s+\{\{ viewing\.extensionsMax \}\} extensions used\./,
    );
    assert.match(page, /viewing\.extensionsUsed >= viewing\.extensionsMax/);
    assert.match(page, /ask the Director\./);
    assert.match(page, /aria-describedby="application-extend-help"/);
});
