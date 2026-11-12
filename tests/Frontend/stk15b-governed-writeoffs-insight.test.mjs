import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const report = readFileSync(
    new URL('../../resources/js/pages/Insights/Report.vue', import.meta.url),
    'utf8',
);

// STK-15b: Recorded wastage also counts approved governed write-offs; the note says so (Daniel's wording).
test('STK-15b: the Recorded wastage note names approved write-offs and leaves out reversed ones', () => {
    assert.match(
        report,
        /note: 'Stocktake losses, damage adjustments and approved write-offs for expiry or other reasons\. Reversed write-offs and reversed count losses are left out\. Counts all stock units together\.'/,
    );
});
