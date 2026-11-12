import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Owner decision 2026-11-11: the one-Director case is recorded visibly as signed off by the same person.
const page = readFileSync(
    new URL('../../resources/js/pages/HR/Appraisal.vue', import.meta.url),
    'utf8',
);

test('a same-person sign-off says so on the appraisal and in the sign-off question', () => {
    assert.match(page, /appraisal\.signedOffBy && appraisal\.sameSigner/);
    assert.match(page, /signed off by the same person on/);
    assert.match(
        page,
        /You rated this appraisal, so it will show as signed off by the same person\./,
    );
});
