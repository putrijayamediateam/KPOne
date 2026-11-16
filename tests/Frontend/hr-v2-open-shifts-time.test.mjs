import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-79: the Open shifts post dialog uses the V2 time field, not the browser's time input.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/HR/V2/OpenShifts.vue');
const flat = page.replace(/\s+/g, ' ');

test('Start and End time are V2TimeField with the same model, labels and errors', () => {
    assert.doesNotMatch(flat, /type="time"/);
    assert.match(
        flat,
        /<V2TimeField v-model="form\.start_time" label="Start time" required :error="errors\.start_time"/,
    );
    assert.match(
        flat,
        /<V2TimeField v-model="form\.end_time" label="End time" required hint="If the end is earlier than the start, the shift ends the next day\." :error="errors\.end_time"/,
    );
});

test('a time that is not a time leaves the field empty and blocks Send, with the reason in words', () => {
    assert.match(flat, /Enter a start time such as 8:00 AM\./);
    assert.match(flat, /Enter an end time such as 2:00 PM\./);
    assert.match(flat, /missing: sharedMissing/);
});
