import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/pages/HR/Leave.vue', import.meta.url),
    'utf8',
);

test('the evidence days say the 7-year cap and an older longer number says the 7 years win', () => {
    assert.match(page, /const MAX_EVIDENCE_DAYS = 2555;/);
    assert.match(
        page,
        /At\s+most\s+2,555\s+days\s+\(7\s+years\)\.\s+When\s+you\s+lower\s+the\s+days,\s+files\s+already\s+older\s+than\s+that\s+are\s+deleted\s+the\s+next\s+night\./,
    );
    assert.match(page, /v-if="savedRetentionDays > MAX_EVIDENCE_DAYS"/);
    assert.match(
        page,
        /the 7 years win\. Enter 2,555 or fewer to save a\s+change\./,
    );
    assert.match(
        page,
        /savedRetentionDays\.value = Number\(row\?\.evidenceRetentionDays \?\? 0\);/,
    );
});
