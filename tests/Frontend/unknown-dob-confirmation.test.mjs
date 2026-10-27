import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('staff adult acknowledgement is explicit, English and accessible', () => {
    const component = read('resources/js/components/patient/AdultConfirmation.vue');
    assert.match(component, /Date of birth unknown\. I confirm the patient is an adult/);
    assert.match(component, /18 or\s+older/);
    assert.match(component, /min-h-11/);
    assert.match(component, /size-11/);
    assert.match(component, /type="checkbox"/);
    assert.doesNotMatch(component, /v-html|localStorage|sessionStorage/);
});

test('staff creation and DOB clearing expose acknowledgement without changing public QR', () => {
    assert.match(read('resources/js/components/patient/PatientFormFields.vue'), /newPatient \|\| !!originalDob/);
    assert.match(read('resources/js/components/patient/PatientFormFields.vue'), /!requirements\?\.requireDateOfBirth/);
    assert.match(read('resources/js/pages/Registration/Create.vue'), /!quick.date_of_birth && !requirements.requireDateOfBirth/);

    for (const name of ['Patient/Create.vue', 'Patient/Edit.vue', 'Registration/Create.vue']) {
        assert.match(read(`resources/js/pages/${name}`), /adult_confirmed: false/);
        assert.match(read(`resources/js/pages/${name}`), /adult_confirmation_key: crypto.randomUUID\(\)/);
    }

    for (const name of ['PublicCheckIn/Show.vue', 'PublicCheckIn/Status.vue', 'Billing/Print.vue']) {
        assert.doesNotMatch(read(`resources/js/pages/${name}`), /adult_confirm|AdultConfirmation/);
    }
});
