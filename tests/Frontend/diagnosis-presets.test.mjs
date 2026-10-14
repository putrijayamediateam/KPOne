import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    'resources/js/pages/ConsultationSettings/Index.vue',
    'utf8',
);
const service = readFileSync(
    'app/Domain/Clinical/Services/DiagnosisPresetSettingsService.php',
    'utf8',
);
const controller = readFileSync(
    'app/Http/Controllers/DiagnosisPresetSettingsController.php',
    'utf8',
);

test('Consultation settings replicates diagnosis list, batch add and disabled later modules', () => {
    for (const label of [
        'Consultation settings',
        'Registration settings',
        'Checkout settings',
        'Clinical modules',
        'Antenatal record (Buku Pink)',
        'Dental chart',
        'NCD follow-up',
        'Not available yet',
        'Diagnosis list',
        'Add diagnosis',
        'Add more diagnosis',
        'Rename',
        'Archive',
    ]) {
        assert.ok(page.includes(label));
    }

    assert.match(page, /\['all', 'active', 'archived'\]/);
    assert.match(page, /:aria-checked="false"\s+disabled/);
    assert.match(page, /maxlength="500"/);
    assert.match(
        page.replace(/\s+/g, ' '),
        /Saved encounter diagnoses keep their text/,
    );
    assert.doesNotMatch(
        page,
        /Delete|localStorage|sessionStorage|diagnosis_code|code_system/,
    );
});

test('Preset settings mutations retain authority, versions, retry keys and filters', () => {
    for (const field of [
        'expected_branch_id',
        'lock_version',
        'idempotency_key',
        'statusKeys',
        'crypto.randomUUID()',
        'InputError',
        'names.',
    ]) {
        assert.ok(page.includes(field));
    }

    assert.match(page, /@change="go"/);
    assert.match(page, /status: props.status/);
    assert.match(controller, /to_route\('diagnosis-presets.index'/);
    assert.match(controller, /redirectTo\(route\('diagnosis-presets.index'/);
    assert.doesNotMatch(controller, /\bback\(/);
    assert.match(service, /catalogue.settings.manage.organisation/);
    assert.doesNotMatch(
        service,
        /EncounterDiagnosis|encounter_diagnoses|Patient::|patient_id/,
    );
});
