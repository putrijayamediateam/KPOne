import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-15b follow-up: a filled open shift whose roster shift was moved or removed says "Needs posting again".
const page = readFileSync(
    new URL('../../resources/js/pages/HR/OpenShifts.vue', import.meta.url),
    'utf8',
);

test('a filled shift the roster no longer has shows Needs posting again in the warning tone', () => {
    assert.match(page, /needsPosting: boolean;/);
    assert.match(page, /s\.needsPosting\s*\?\s*'Needs posting again'/);
    assert.match(page, /s\.needsPosting\s*\?\s*'warning'/);
    assert.match(page, /data-test="open-shift-needs-posting"/);
    assert.match(
        page,
        /The roster no longer has this shift for the\s+confirmed locum\. Post it again if it is still\s+needed\./,
    );
});
