import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const source = readFileSync('resources/js/lib/diagnosis-draft.ts', 'utf8');
const javascript = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ESNext },
}).outputText;
const { appendPresetDiagnosis } = await import(
    `data:text/javascript;base64,${Buffer.from(javascript).toString('base64')}`
);
const picker = readFileSync(
    'resources/js/components/clinical/DiagnosisPresetPicker.vue',
    'utf8',
);
const show = readFileSync('resources/js/pages/Clinical/Show.vue', 'utf8');

test('Preset selection appends editable text without changing existing rows or codes', () => {
    const existing = [
        {
            diagnosis_text: 'Synthetic existing',
            diagnosis_code: 'SYN',
            code_system: 'Synthetic',
            is_primary: true,
        },
    ];
    const before = structuredClone(existing);
    const result = appendPresetDiagnosis(existing, 'Synthetic chosen');
    assert.deepEqual(existing, before);
    assert.deepEqual(result[0], before[0]);
    assert.deepEqual(result[1], {
        diagnosis_text: 'Synthetic chosen',
        diagnosis_code: '',
        code_system: '',
        is_primary: false,
    });
    assert.equal(
        appendPresetDiagnosis([], 'Synthetic first')[0].is_primary,
        true,
    );
    assert.equal(
        appendPresetDiagnosis(
            Array.from({ length: 20 }, () => existing[0]),
            'Synthetic extra',
        ).length,
        20,
    );
});

test('Picker preserves free text, uses only read lookup, cancels stale results and submits no clinical write', () => {
    assert.match(show, /@click="addDiagnosis"/);
    assert.match(show, /@select="choosePreset"/);
    assert.match(show, /v-model="diagnosis.diagnosis_text"/);
    assert.match(show, /form.diagnoses.length >= 20/);
    assert.match(picker, /Choose preset/);
    assert.match(picker, /expected_branch_id/);
    assert.match(picker, /generation/);
    assert.match(picker, /controller\?\.abort\(\)/);
    assert.match(picker, /onBeforeUnmount\(cancel\)/);
    assert.match(picker, /emit\('select', preset.name\)/);
    assert.match(picker, /No active presets found/);
    assert.doesNotMatch(
        picker,
        /router\.(post|patch)|localStorage|sessionStorage|catalogue.settings.manage/,
    );
});
