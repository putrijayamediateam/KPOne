// Classic only, CO-8: the Classic half of v2-consultation-settings, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/ConsultationSettings/Index.vue');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('Classic Consultation settings calls its routes with the payloads, lock versions and list state', () => {
    for (const key of [
        "'/consultation-settings'",
        "mutationUrl('/consultation-settings/diagnoses')",
        '`/consultation-settings/diagnoses/${selected.value.id}/rename`',
        'mutationUrl(`/consultation-settings/diagnoses/${preset.id}/active`)',
        'new URLSearchParams({ status: props.status, q: props.q })',
        'expected_branch_id: props.branchId',
        'lock_version: preset.lockVersion',
        'active: !preset.active',
        'idempotency_key: key',
        'names: [',
        'addForm.names.length >= 20',
        'maxlength="500"',
    ]) {
        assert.ok(squash(classic).includes(squash(key)), `classic ${key}`);
    }
});

test('Classic Consultation settings keeps the three clinical modules listed', () => {
    for (const name of [
        'Antenatal record (Buku Pink)',
        'Dental chart',
        'NCD follow-up',
    ]) {
        assert.ok(classic.includes(name), name);
    }
});
