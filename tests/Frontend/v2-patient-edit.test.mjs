import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Patient/Edit.vue');
const page = read('resources/js/pages/Patient/V2/Edit.vue');
const classicFields = read(
    'resources/js/components/patient/PatientFormFields.vue',
);
const classicGuardian = read(
    'resources/js/components/patient/GuardianFields.vue',
);
const fields = read('resources/js/components/patient-v2/PatientFormV2.vue');

test('the V2 page sends the same request as Classic', () => {
    for (const piece of [
        'adult_confirmed: false',
        'adult_confirmation_key: crypto.randomUUID()',
        'retry_key: crypto.randomUUID()',
        'expected_branch_id',
        'lock_version: props.patient.administrative.lockVersion',
        "phone_country: 'MY'",
        'form.patch(',
        "'/patients/' + encodeURIComponent(props.patient.patientNumber)",
        'preserveState: true',
        'onError: focusInvalidField',
        'phoneError(',
        '!knownMinor(form.date_of_birth, props.guardianToday)',
        'const { adult_confirmation_key, adult_confirmed, ...rest } = data;',
        '!data.date_of_birth && !!props.patient.identity.dateOfBirth',
    ]) {
        assert.ok(classic.includes(piece), `Classic lacks ${piece}`);
        assert.ok(page.includes(piece), `V2 lacks ${piece}`);
    }
});

test('every form key of Classic is a model key in the V2 form', () => {
    for (const key of [
        'full_name',
        'date_of_birth',
        'sex',
        'nationality_code',
        'mobile_phone',
        'phone_country',
        'email',
        'address_line_1',
        'address_line_2',
        'postcode',
        'city',
        'state',
        'country_code',
    ]) {
        assert.ok(
            classicFields.includes(`fields.${key}`),
            `Classic lacks ${key}`,
        );
        assert.ok(fields.includes(`fields.${key}`), `V2 lacks ${key}`);
    }
});

test('the guardian and adult-confirmation rules are the Classic rules', () => {
    for (const piece of [
        'knownMinor(',
        'blankGuardian()',
        'lock_version === 0',
        'crypto.randomUUID()',
        'requireDateOfBirth',
        'requireNationality',
        'requireEmail',
        'requireAddress',
    ]) {
        assert.ok(
            classicFields.includes(piece) || classicGuardian.includes(piece),
            `Classic lacks ${piece}`,
        );
        assert.ok(fields.includes(piece), `V2 lacks ${piece}`);
    }

    assert.ok(fields.includes('I confirm this patient is an adult'));

    for (const relationship of [
        'mother',
        'father',
        'legal_guardian',
        'other_family_member',
    ]) {
        assert.ok(
            classicGuardian.includes(relationship) &&
                fields.includes(relationship),
        );
    }
});

test('no native select, no placeholder-only field, and names of unsaved fields only', () => {
    assert.ok(!/<select\b/.test(fields + page));
    assert.ok(!/placeholder=/.test(fields));
    assert.ok(page.includes('Unsaved changes'));
    assert.ok(page.includes("Reflect.deleteProperty(rest, 'retry_key')"));
    assert.equal(
        (page.match(/bg-primary text-primary-foreground/g) ?? []).length,
        1,
    );
});

test('registered, and Classic files are untouched', () => {
    assert.ok(read('config/v2-pages.php').includes("'Patient/Edit'"));
    const diff = spawnSync(
        'git',
        [
            'diff',
            '--quiet',
            'origin/main',
            '--',
            'resources/js/pages/Patient/Edit.vue',
            'resources/js/components/patient/PatientFormFields.vue',
            'resources/js/components/patient/GuardianFields.vue',
            'resources/js/components/patient/PhoneInput.vue',
        ],
        { cwd: new URL('../../', import.meta.url) },
    );
    assert.notEqual(diff.status, 1, 'a Classic patient file changed');
    assert.ok(!/console\./.test(page + fields));
});

test('date of birth is shown in words, the nationality label is plain, errors are plain and clear per field', () => {
    assert.ok(
        fields.includes(
            'Born ${formatDate(fields.date_of_birth)} · age ${age}',
        ),
    );
    assert.ok(fields.includes('a guardian contact is required'));
    // The words stay the hint, hidden only while the box shows its own error.
    assert.match(
        fields,
        /:hint="\s*dobBoxError \|\| err\('date_of_birth'\)\s*\? undefined\s*: dobWords\s*"/,
    );
    assert.ok(fields.includes('Nationality (2-letter country code)'));
    assert.ok(fields.includes("full_name: 'Enter the full name.'"));
    assert.ok(fields.includes("sex: 'Choose a gender.'"));
    assert.ok(fields.includes("emit('clear', key)"));
    assert.ok(page.includes('form.clearErrors(key as never)'));
});

test('leaving with unsaved changes uses the shared V2LeaveGuard, and the rail starts at 1280 px', () => {
    assert.ok(
        page.includes('<V2LeaveGuard :dirty="dirty && !form.processing" />'),
    );
    assert.ok(page.includes(':href="patientUrl"'));
    assert.ok(!page.includes('event.preventDefault();'));
    assert.ok(page.includes('rail-from="xl"'));
});

test('the date of birth uses V2DateField, blocks Save on unreadable text and the guardian name is full width', () => {
    assert.ok(fields.includes('<V2DateField'));
    assert.ok(!fields.includes('import { DateField }'));
    assert.ok(fields.includes('@update:invalid'));
    assert.ok(fields.includes("emit('dobInvalid', value)"));
    assert.ok(page.includes('@dob-invalid'));
    assert.match(
        page,
        /if \(dobInvalid\.value\)\s*\{\s*return focusInvalidField\(\);/,
    );
    assert.match(
        fields,
        /<div class="md:col-span-2">\s*<V2Field\s+label="Guardian full name"/,
    );
});

test('the words under the date of birth hide while the box shows its own error', () => {
    assert.ok(fields.includes('const dobBoxError = ref(false);'));
    assert.match(
        fields,
        /dobBoxError \|\| err\('date_of_birth'\)\s*\? undefined\s*: dobWords/,
    );
});
