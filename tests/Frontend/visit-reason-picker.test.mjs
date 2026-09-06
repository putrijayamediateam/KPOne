import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const picker = readFileSync(
    new URL(
        '../../resources/js/components/visit/VisitReasonPicker.vue',
        import.meta.url,
    ),
    'utf8',
);
const registration = readFileSync(
    new URL('../../resources/js/pages/Registration/Create.vue', import.meta.url),
    'utf8',
);

test('Visit Reason catalogue creation requires the explicit Add action', () => {
    assert.match(picker, /@click="add"/);
    assert.match(picker, /Add &quot;\{\{ query\.trim\(\)/);
    assert.doesNotMatch(picker, /@blur="add"/);
    assert.doesNotMatch(picker, /event\.key === 'Enter'[\s\S]{0,180}\badd\(/);
});

test('Visit Reason selection exposes primary ordering removal and max five', () => {
    assert.match(picker, /index === 0/);
    assert.match(picker, /Make Primary/);
    assert.match(picker, /@click="remove\(reason\.publicId\)"/);
    assert.match(picker, /props\.selected\.length >= 5/);
    assert.match(registration, /visit_reason_public_ids/);
    assert.doesNotMatch(registration, /v-model="form\.visit_reason"/);
});
