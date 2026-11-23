import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
    new URL(
        '../../resources/js/pages/RegistrationReview/Show.vue',
        import.meta.url,
    ),
    'utf8',
).replace(/\s+/g, ' ');

test('DD-73: Gender, Visit purpose, both coverage choices and Priority use the shared select, not a native one', () => {
    for (const [model, label, options] of [
        ['correction.sex', 'Gender', 'sexOptions'],
        ['correction.visit_purpose', 'Visit purpose', 'visitPurposeOptions'],
        [
            'correction.coverage_type',
            'Patient-reported coverage',
            'reportedCoverageOptions',
        ],
        [
            'acceptance.coverage_type',
            'Verified coverage',
            'verifiedCoverageOptions',
        ],
        ['acceptance.priority', 'Priority', 'priorityOptions'],
    ]) {
        assert.ok(
            source.includes(
                `<OperationalSelect v-model="${model}" :options="${options}" label="${label}" />`,
            ),
            model,
        );
        assert.ok(
            !source.includes(`<select v-model="${model}"`),
            `${model} is still native`,
        );
    }
});

test('DD-73: the choices keep their stored values, and verified coverage still starts unpicked', () => {
    for (const value of [
        'female',
        'male',
        'indeterminate',
        'unknown',
        'doctor_illness',
        'pregnancy_check',
        'scan',
        'vaccination',
        'medical_checkup',
        'procedure',
        'self_pay',
        'panel',
        'normal',
        'urgent',
    ]) {
        assert.ok(source.includes(`value: '${value}'`), value);
    }

    assert.ok(
        source.includes(
            "{ value: '', label: 'Select after verification', disabled: true }",
        ),
    );
});
