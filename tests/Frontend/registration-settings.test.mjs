import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
const read = (p) => readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('SET-04 checkbox screen compiles with four configurable requirements and fixed safety rows', () => {
    const source = read('resources/js/pages/RegistrationSettings/Index.vue');
    assert.deepEqual(compileTemplate({ id: 'set04', source: parse(source).descriptor.template.content, filename: 'Index.vue' }).errors, []);

    for (const label of ['Name', 'Phone number', 'NRIC/Passport', 'Required by system', 'Gender', 'Unknown allowed', 'Public QR always requires date of birth']) {
assert.ok(source.includes(label));
}

    assert.match(source, /v-model="form.require_date_of_birth"/);
    assert.match(source, /v-model="form.require_nationality"/);
    assert.match(source, /v-model="form.require_email"/);
    assert.match(source, /v-model="form.require_address"/);
    assert.match(source, /checked\s+disabled/);
    assert.match(source, /href="\/clinic-management"/);
    assert.match(source, /crypto.randomUUID\(\)/);
    assert.match(source, /form.lock_version = props.requirements.lockVersion/);
    assert.match(source, /Reload latest settings/);
    assert.match(source, /role="status"/);
    assert.doesNotMatch(source, /Patient image|Religion|Race|Emergency contact|Clear queue|Panel member|<aside/);
    assert.match(read('app/Domain/Access/ClinicManagementNavigation.php'), /\[\$settings, 'Registration settings', 'Set required details in registration.', 'registration-settings.index'\]/);
});

test('Nationality is editable and errors visible in all creation paths and QR correction; existing edits stay optional', () => {
    for (const path of ['Registration/Create.vue', 'PublicCheckIn/Show.vue', 'RegistrationReview/Show.vue']) {
        const source = read(`resources/js/pages/${path}`);
        assert.match(source, /v-model="(?:quick|form|correction).nationality_code"/);
        assert.match(source, /(?:errorFor\('(?:quick_patient\.)?nationality_code'\)|correction.errors.nationality_code)/);
        assert.deepEqual(compileTemplate({ id: path, source: parse(source).descriptor.template.content, filename: path }).errors, []);
    }

    const fields = read('resources/js/components/patient/PatientFormFields.vue');
    assert.match(fields, /newPatient && requirements\?\.requireNationality/);
    assert.match(fields, /newPatient && requirements\?\.requireDateOfBirth/);
    assert.match(read('resources/js/pages/Patient/Create.vue'), /:requirements="requirements"/);
    assert.match(read('app/Http/Requests/StoreVisitRequest.php'), /quick_patient.nationality_code/);
});

test('QR nationality requirement uses server policy and keeps DOB mandatory independently', () => {
    const source = read('resources/js/lib/public-intake-form.ts');
    const js = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;
    const { validateIntakeStep } = runInNewContext(`${js.replaceAll('export ', '')}\n({validateIntakeStep})`);
    const form = { chief_complaint: '', complaint_duration: '', visit_kind: 'otc', full_name:'Synthetic Patient', date_of_birth:'1990-01-01', mobile_phone:'+60123456789', identifier_type:'passport', identifier_value:'SYN-SET04', identifier_issuing_country_code:'MY', submission_type:'patient', nationality_code:'' };
    const options = { today:new Date('2026-10-16'), minorAge:18 };
    assert.equal(validateIntakeStep(2,form,options).nationality_code, undefined);
    assert.ok(validateIntakeStep(2,form,{...options,requireNationality:true}).nationality_code);
    assert.equal(validateIntakeStep(2,{...form,nationality_code:'SG'},{...options,requireNationality:true}).nationality_code,undefined);
    assert.ok(validateIntakeStep(2,{...form,date_of_birth:''},options).date_of_birth);
    assert.match(read('resources/js/pages/PublicCheckIn/Show.vue'), /requireNationality: requirements.value\?\.requireNationality/);
});
