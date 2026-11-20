// Classic only, CO-7: direct Classic pins for checks that only read a V2 HR page before it was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Classic Job applications marks the video link field invalid only while it has an error (hr-15e lines 76-77)', () => {
    assert.match(
        read('resources/js/pages/HR/Applications.vue'),
        /:aria-invalid="errors\.video_link \? true : undefined"/,
    );
});
