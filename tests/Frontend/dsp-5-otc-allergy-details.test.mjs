import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const show = read('resources/js/pages/Dispensary/Show.vue');
const form = read(
    'resources/js/pages/Dispensary/Partials/OtcAllergyRecordForm.vue',
);

test('the OTC allergy step asks for the allergy before "has an allergy" can be confirmed', () => {
    assert.ok(
        show.includes(
            "const otcAllergyChoice = ref<'' | 'none' | 'has_allergy'>(",
        ),
    );
    assert.ok(show.includes('<OtcAllergyRecordForm'));
    assert.ok(show.includes('dispensary.can.addOtcAllergy'));
    assert.ok(show.includes(':disabled="busy || otcConfirmBlocked"'));
    assert.ok(show.includes('Add the allergy before you confirm.'));
    assert.ok(show.includes('data-action="open-otc-allergy-form"'));
    assert.ok(show.includes('data-testid="otc-allergy-readonly"'));
});

test('the form posts a structured allergy with the versions the CA saw', () => {
    assert.ok(
        form.includes(
            '`/dispensary/${props.casePublicId}/otc-allergy-records`',
        ),
    );

    for (const key of [
        'allergen_text',
        'category',
        'severity',
        'reaction_text',
        'case_lock_version: props.caseLockVersion',
        'profile_lock_version: props.profileVersion',
    ]) {
        assert.ok(form.includes(key), key);
    }

    assert.ok(form.includes('maxlength="500"'));
    assert.ok(form.includes('maxlength="1000"'));
    assert.ok(form.includes('Only a doctor can change or'));
});

test('the form is accessible and fits a phone', () => {
    for (const id of [
        'otc-allergy-allergen',
        'otc-allergy-category',
        'otc-allergy-severity',
        'otc-allergy-reaction',
    ]) {
        assert.ok(form.includes(`for="${id}"`), id);
        assert.ok(form.includes(`id="${id}"`), id);
    }

    assert.ok(form.includes('otc-allergy-allergen-error'));
    assert.ok(form.includes('trigger-class="h-11 sm:h-9"'));
    assert.ok(form.includes('h-11 w-full sm:h-9 sm:w-auto'));
    assert.ok(show.includes('flex min-h-11 items-center gap-2'));
});

test('a line that matches an allergy added later can be ticked and saved again', () => {
    assert.ok(show.includes('allergyUnconfirmed?: boolean;'));
    assert.ok(show.includes('!item.allergyUnconfirmed'));
    assert.ok(
        show.includes('props.dispensary.can.addOtcAllergy === true'),
        'the form shows only when the server allows it',
    );
});
