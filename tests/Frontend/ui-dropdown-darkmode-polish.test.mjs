import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const patientDirectory = read('resources/js/pages/Patient/Index.vue');
const patientFields = read(
    'resources/js/components/patient/PatientFormFields.vue',
);
const patientCreate = read('resources/js/pages/Patient/Create.vue');
const staffDirectory = read('resources/js/pages/Staff/Index.vue');
const registerVisit = read('resources/js/pages/Registration/Create.vue');

test('Patient search radio pills and other custom selects keep submitted values', () => {
    assert.match(
        patientDirectory,
        /<input[\s\S]*?v-model="form\.search_type"[\s\S]*?type="radio"[\s\S]*?name="patient-search-type"/,
    );
    assert.doesNotMatch(patientDirectory, /<select\b/);

    for (const value of [
        'name',
        'patient_number',
        'nric',
        'passport',
        'phone',
    ]) {
        assert.match(patientDirectory, new RegExp(`value: '${value}'`));
    }

    assert.match(
        patientFields,
        /<OperationalSelect[\s\S]*v-model="fields\.sex"/,
    );
    assert.match(patientFields, /labelledby="patient-gender-label"/);
    assert.doesNotMatch(patientFields, /<select\b/);

    for (const value of ['female', 'male', 'indeterminate', 'unknown']) {
        assert.match(patientFields, new RegExp(`value: '${value}'`));
    }

    assert.match(
        patientCreate,
        /<OperationalSelect[\s\S]*:model-value="identifier\.identifier_type"/,
    );
    assert.match(patientCreate, /value: 'nric', label: 'Malaysian IC'/);
    assert.match(patientCreate, /value: 'passport', label: 'Passport'/);
    assert.match(patientCreate, /updateIdentifierType\(identifier, \$event\)/);
    assert.doesNotMatch(patientCreate, /<select\b/);
});

test('Patient search radios and custom selects retain accessible labels', () => {
    assert.match(patientDirectory, /<fieldset[\s\S]*?<legend[^>]*>\s*Search type\s*<\/legend>/);
    assert.match(patientDirectory, /<label[\s\S]*?v-for="option in searchTypeOptions"[\s\S]*?name="patient-search-type"[\s\S]*?\{\{ option.label \}\}/);
    assert.match(patientFields, /id="patient-gender"/);
    assert.match(patientFields, /id="patient-gender-label"/);
    assert.match(patientCreate, /'patient-identifier-type-' \+ index/);
    assert.match(patientCreate, /'patient-identifier-type-label-' \+ index/);
});

test('Staff directory filters use the shared custom select with unchanged criteria', () => {
    assert.doesNotMatch(staffDirectory, /<select\b/);

    for (const [model, id] of [
        ['branch', 'staff-filter-branch'],
        ['department', 'staff-filter-department'],
        ['role', 'staff-filter-role'],
        ['status', 'staff-filter-status'],
    ]) {
        assert.match(
            staffDirectory,
            new RegExp(
                `<OperationalSelect[\\s\\S]*?id="${id}"[\\s\\S]*?v-model="form\\.${model}"`,
            ),
        );
    }

    assert.match(staffDirectory, /router\.get\('\/staff', form/);
    assert.match(
        staffDirectory,
        /Object\.assign\(form, \{[\s\S]*branch: ''[\s\S]*department: ''[\s\S]*role: ''[\s\S]*status: ''/,
    );
});

test('Register Visit keeps the light selection and uses readable neutral dark styling', () => {
    assert.match(registerVisit, /bg-emerald-50\/70/);
    assert.match(registerVisit, /dark:bg-zinc-900\/85/);
    assert.match(registerVisit, /dark:border-brand\/40/);
    assert.match(registerVisit, /dark:text-slate-300/);
    assert.match(registerVisit, /selectedPatient\.fullName/);
    assert.match(registerVisit, /selectedPatient\.patientNumber/);
    assert.match(registerVisit, /selectedPatient\.dateOfBirth/);
    assert.match(registerVisit, /selectedPatient\.identifier\?\.maskedValue/);
    assert.doesNotMatch(registerVisit, /dark:bg-emerald-(?:50|100|200)/);
});
