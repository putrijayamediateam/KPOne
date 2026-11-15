import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/lib/public-intake-form.ts');
const javascript = ts.transpileModule(source, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const module = { exports: {} };
runInNewContext(javascript, { exports: module.exports, module, Date });
const {
    ageOn,
    formatCountdown,
    parseDateOfBirth,
    reviewRows,
    secondsRemaining,
    validateIntakeStep,
} = module.exports;
// Values created inside the VM context carry that context's prototypes.
const validate = (...args) =>
    JSON.parse(JSON.stringify(validateIntakeStep(...args)));
const publicForm = read('resources/js/pages/PublicCheckIn/Show.vue');

const today = new Date(Date.UTC(2026, 9, 5));
const options = { minorAge: 18, today };
const blank = {
    submission_type: 'patient',
    full_name: '',
    date_of_birth: '',
    sex: 'unknown',
    mobile_phone: '',
    identifier_type: 'nric',
    identifier_value: '',
    identifier_issuing_country_code: 'MY',
    visit_purpose: '',
    chief_complaint: '',
    complaint_duration: '',
    coverage_type: '',
    panel_id: '',
    coverage_member_reference: '',
    guardian_name: '',
    guardian_relationship: '',
    guardian_contact_number: '',
    guardian_attestation: false,
    consent_confirmed: false,
};
const complete = {
    ...blank,
    full_name: 'Aisyah Demo',
    date_of_birth: '1990-01-01',
    mobile_phone: '0123456789',
    identifier_value: '900101-14-0000',
    visit_purpose: 'doctor_illness',
    chief_complaint: 'Demam sejak semalam',
    coverage_type: 'self_pay',
    consent_confirmed: true,
};
const keys = (errors) => Object.keys(errors).sort();

test('patient details step reports every required field before submission', () => {
    assert.deepEqual(keys(validate(2, blank, options)), [
        'chief_complaint',
        'date_of_birth',
        'full_name',
        'identifier_value',
        'mobile_phone',
        'visit_purpose',
    ]);
    assert.deepEqual(keys(validate(2, complete, options)), []);
});

test('date of birth is parsed strictly and may not be in the future', () => {
    assert.equal(parseDateOfBirth('2000-02-30'), null);
    assert.equal(parseDateOfBirth('2023'), null);
    assert.equal(parseDateOfBirth('1990/01/01'), null);
    assert.ok(parseDateOfBirth('1990-01-01'));
    assert.deepEqual(
        validate(2, { ...complete, date_of_birth: '2026-10-06' }, options)
            .date_of_birth,
        ['Tarikh lahir tidak boleh pada masa depan.'],
    );
    assert.deepEqual(
        validate(
            2,
            { ...complete, date_of_birth: '2026-10-05' },
            {
                ...options,
                minorAge: 0,
            },
        ),
        {},
    );
});

test('a minor must be submitted by a guardian, matching the server rule', () => {
    const minor = { ...complete, date_of_birth: '2010-10-06' };
    assert.equal(ageOn(parseDateOfBirth('2010-10-06'), today), 15);
    assert.equal(ageOn(parseDateOfBirth('2008-10-05'), today), 18);
    assert.match(validate(2, minor, options).date_of_birth[0], /Saya penjaga/);
    assert.deepEqual(
        validate(2, { ...minor, submission_type: 'guardian' }, options),
        {},
    );
    assert.deepEqual(
        validate(2, { ...complete, date_of_birth: '2008-10-05' }, options),
        {},
    );
});

test('length limits mirror the server validator', () => {
    const errors = validate(
        2,
        {
            ...complete,
            full_name: 'a'.repeat(256),
            chief_complaint: 'a'.repeat(501),
            complaint_duration: 'a'.repeat(121),
            identifier_value: 'a'.repeat(101),
        },
        options,
    );
    assert.deepEqual(keys(errors), [
        'chief_complaint',
        'complaint_duration',
        'full_name',
        'identifier_value',
    ]);
    assert.deepEqual(
        validate(
            2,
            {
                ...complete,
                identifier_type: 'passport',
                identifier_issuing_country_code: 'MYS',
            },
            options,
        ).identifier_value,
        ['Kod negara pengeluar passport mesti 2 huruf, contoh MY.'],
    );
});

test('guardian step is required only for guardian submissions', () => {
    assert.deepEqual(validate(3, complete, options), {});
    assert.deepEqual(
        keys(
            validate(3, { ...complete, submission_type: 'guardian' }, options),
        ),
        [
            'guardian_attestation',
            'guardian_contact_number',
            'guardian_name',
            'guardian_relationship',
        ],
    );
});

test('coverage step requires a type and a panel when Panel is chosen', () => {
    assert.deepEqual(keys(validate(4, blank, options)), ['coverage_type']);
    assert.deepEqual(
        keys(validate(4, { ...complete, coverage_type: 'panel' }, options)),
        ['panel_id'],
    );
    assert.deepEqual(
        validate(
            4,
            { ...complete, coverage_type: 'panel', panel_id: 3 },
            options,
        ),
        {},
    );
    assert.deepEqual(keys(validate(5, blank, options)), ['consent_confirmed']);
});

test('session countdown never goes negative and formats as minutes and seconds', () => {
    const now = new Date('2026-10-05T04:00:00Z');
    assert.equal(secondsRemaining('2026-10-05T04:15:00+00:00', now), 900);
    assert.equal(secondsRemaining('2026-10-05T03:59:00+00:00', now), 0);
    assert.equal(secondsRemaining('not a date', now), 0);
    assert.equal(formatCountdown(900), '15:00');
    assert.equal(formatCountdown(65), '1:05');
    assert.equal(formatCountdown(-4), '0:00');
});

test('review summary shows what will be submitted and links back to each step', () => {
    const panelRows = reviewRows(
        {
            ...complete,
            submission_type: 'guardian',
            guardian_name: 'Penjaga Demo',
            guardian_relationship: 'parent',
            guardian_contact_number: '0129876543',
            coverage_type: 'panel',
            panel_id: '7',
            coverage_member_reference: 'M-001',
        },
        [{ id: 7, name: 'Panel Demo' }],
    );
    const byLabel = Object.fromEntries(
        panelRows.map((row) => [row.label, row]),
    );
    assert.equal(byLabel['Diisi oleh'].value, 'Penjaga');
    assert.equal(byLabel['Hubungan'].value, 'Ibu / Bapa');
    assert.equal(byLabel['Hubungan'].step, 3);
    assert.equal(byLabel['Jenis bayaran'].value, 'Panel — Panel Demo');
    assert.equal(byLabel['Nombor ahli panel'].step, 4);
    assert.equal(byLabel['Tujuan lawatan'].value, 'Jumpa doktor / sakit');

    const selfPayRows = reviewRows(complete, []);
    const labels = selfPayRows.map((row) => row.label);
    assert.ok(!labels.includes('Nama penjaga'));
    assert.ok(!labels.includes('Nombor ahli panel'));
    assert.equal(
        selfPayRows.find((row) => row.label === 'Jenis bayaran').value,
        'Bayar sendiri',
    );
});

test('form validates each step before moving on and keeps the server authoritative', () => {
    assert.match(publicForm, /if \(!checkStep\(step\.value\)\)/);
    assert.match(publicForm, /if \(!checkStep\(5\)\)/);
    assert.ok(publicForm.includes("request('/check-in/intakes'"));
    assert.match(publicForm, /Semak maklumat anda/);
    assert.match(publicForm, /@click="goTo\(row\.step\)"/);
    assert.match(publicForm, /role="timer"/);
    assert.match(publicForm, /formatCountdown\(sessionSecondsLeft\)/);
    assert.match(publicForm, /window\.clearInterval\(clock\)/);
});

test('an elapsed client clock warns but never blocks a submission on its own', () => {
    const submitButton = publicForm
        .split('type="submit"')[1]
        .split('</button>')[0];
    assert.doesNotMatch(submitButton, /sessionLapsed/);
    assert.match(publicForm, /Anda masih boleh cuba\s+menghantar/);
});

test('no draft of patient data is kept in browser storage', () => {
    assert.doesNotMatch(publicForm, /localStorage|sessionStorage|indexedDB/);
    assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB/);
});

test('every guardian field surfaces its own error', () => {
    for (const field of [
        'guardian_name',
        'guardian_relationship',
        'guardian_contact_number',
        'guardian_attestation',
    ]) {
        assert.ok(publicForm.includes(`errorFor('${field}')`), field);
    }
});
