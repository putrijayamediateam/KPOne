import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Owner, 2026-11-11 (#484 follow-up): the candidate's page shows the last day to choose interview times.
const page = readFileSync(
    new URL('../../resources/js/pages/Careers/Status.vue', import.meta.url),
    'utf8',
);

test('the slot picker says the last day to choose, in both languages', () => {
    assert.match(page, /chooseBy\?: string \| null;/);
    assert.match(page, /v-if="application\.interview\.chooseBy"/);
    assert.match(page, /data-test="status-choose-by"/);
    assert.match(page, /After that, the application closes\./);
    assert.match(page, /Selepas itu, permohonan ini ditutup\./);
});
