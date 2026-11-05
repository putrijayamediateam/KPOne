import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// REG-2 (owner decisions 2026-11-05): staff Registration and Patient screens use English labels and
// messages ("Panggil semula" stays). The nine phone and IC/Passport messages are English everywhere,
// including the public QR form; the QR form's own wording (purposes, contact labels) stays as it is.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const exports = {};
runInNewContext(
    ts.transpileModule(read('resources/js/lib/patient-registration.ts'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS },
    }).outputText,
    { exports, require: createRequire(import.meta.url) },
);
const { phoneError, identityError } = exports;

test('REG-2: staff phone and identity messages are English', () => {
    assert.equal(phoneError('', 'MY', true), 'Phone number is required.');
    assert.equal(
        phoneError('0991234567', 'MY'),
        'Enter a valid phone number for the selected country.',
    );
    assert.equal(
        phoneError('+600123456789', 'MY'),
        'Check the country code and phone number.',
    );
    assert.equal(
        identityError('nric', '1234', 'MY'),
        'IC number must have 12 digits.',
    );
    assert.equal(
        identityError('other', 'X', 'MY'),
        'Choose IC number or Passport.',
    );
    assert.equal(
        identityError('passport', 'SYN-PASS', ''),
        'Passport issuing country is required.',
    );
    assert.equal(
        identityError('passport', 'A1', 'MY'),
        'Passport number is incomplete.',
    );
    assert.equal(
        identityError('passport', '-ABC', 'MY'),
        'Check the Passport number format.',
    );
});

test('REG-2: QR Intake tab on the Registration board shows English visit purposes', () => {
    const table = read(
        'resources/js/components/registration/QrIntakeTable.vue',
    );

    for (const label of [
        "doctor_illness: 'See a doctor / illness'",
        "pregnancy_check: 'Pregnancy check'",
        "vaccination: 'Vaccination'",
        "procedure: 'Procedure'",
        "other: 'Other'",
    ]) {
        assert.ok(table.includes(label), label);
    }
});

test('REG-2: staff Registration and Patient sources carry no Malay labels', () => {
    const files = [
        'resources/js/lib/patient-registration.ts',
        'resources/js/components/registration/QrIntakeTable.vue',
        'resources/js/components/patient-board/PatientBoard.vue',
        'resources/js/components/patient-board/VisitCancellationDialog.vue',
        ...['Patient', 'Registration', 'RegistrationSettings'].flatMap((dir) =>
            readdirSync(
                new URL(`../../resources/js/pages/${dir}`, import.meta.url),
            )
                .filter((file) => file.endsWith('.vue'))
                .map((file) => `resources/js/pages/${dir}/${file}`),
        ),
    ];
    const malay =
        /\b(diperlukan|nombor|telefon|semak|pilih|mesti|tidak lengkap|negara pengeluar|jumpa doktor|pemeriksaan|vaksin|prosedur|lain-lain|sila|pesakit)\b/i;

    for (const file of files) {
        const text = read(file).replace(/Panggil semula/g, '');
        assert.doesNotMatch(text, malay, file);
    }
});

test('REG-2: the public QR form shows the English phone message but keeps its own wording', () => {
    const intake = read('resources/js/lib/public-intake-form.ts');
    const form = read('resources/js/pages/PublicCheckIn/Show.vue');
    const contact = read(
        'resources/js/components/patient/RegistrationContactFields.vue',
    );

    assert.ok(
        intake.includes("add('mobile_phone', 'Phone number is required.')"),
    );
    assert.ok(!intake.includes('Nombor telefon diperlukan.'));
    // Owner's QR wording is unchanged.
    assert.ok(intake.includes("doctor_illness: 'Jumpa doktor / sakit'"));
    assert.ok(form.includes('Jumpa doktor / sakit'));
    assert.ok(
        contact.includes(
            "address_line_1: ['Address line 1', 'Alamat baris 1']",
        ),
    );
});

test('REG-2: the QR review list shows the same English visit purposes', () => {
    const review = read('resources/js/pages/RegistrationReview/Index.vue');

    for (const label of [
        "doctor_illness: 'See a doctor / illness'",
        "pregnancy_check: 'Pregnancy check'",
        "vaccination: 'Vaccination'",
        "procedure: 'Procedure'",
        "other: 'Other'",
    ]) {
        assert.ok(review.includes(label), label);
    }
});
