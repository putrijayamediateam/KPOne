import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR month dry run S1 (owner, 16 Nov 2026): worked time takes the rostered break off, so the clock card tells staff
// to stay clocked in for their break, in Classic and in Version 2.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Classic and V2 attendance tell staff to stay clocked in for their break', () => {
    for (const page of [
        'resources/js/pages/HR/Attendance.vue',
        'resources/js/pages/HR/V2/Attendance.vue',
    ]) {
        assert.match(
            read(page),
            /v-if="attendance\.my\.todayHasBreak"[\s\S]{0,160}data-test="break-line"[^>]*>\s*Stay\s+clocked\s+in\s+for\s+your\s+break;\s+KPOne\s+takes\s+the\s+rostered\s+break\s+off\./,
            page,
        );
    }
});
