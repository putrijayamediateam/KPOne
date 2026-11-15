import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the QR form offers a doctor visit or medicine only, and medicine only skips purpose and duration', () => {
    const form = read('resources/js/pages/PublicCheckIn/Show.vue');
    assert.match(form, /v-model="form\.visit_kind"/);
    assert.match(form, /value="otc"/);
    assert.match(form, /Beli ubat sahaja/);
    assert.match(form, /v-if="form\.visit_kind !== 'otc'"/);
    const lib = read('resources/js/lib/public-intake-form.ts');
    assert.match(lib, /medicineOnly/);
});

test('the phone status page shows the pharmacy call for a medicine-only visit', () => {
    const status = read('resources/js/pages/PublicCheckIn/Status.vue');
    assert.match(status, /Sila ke kaunter farmasi/);
    assert.match(status, /props\.status\.called \?\?/);
});

test('staff review hides doctor and reasons for a medicine-only intake', () => {
    const review = read('resources/js/pages/RegistrationReview/Show.vue');
    assert.match(review, /const medicineOnly = computed/);
    assert.equal(
        (review.match(/v-if="!medicineOnly"/g) ?? []).length >= 4,
        true,
    );
});
