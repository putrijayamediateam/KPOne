import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Consultation settings (Preset diagnoses): look only. Same props, routes, payloads and lock versions as Classic.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/ConsultationSettings/Index.vue');
const v2 = read('resources/js/pages/ConsultationSettings/V2/Index.vue');
const registry = read('config/v2-pages.php');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('ConsultationSettings/Index is registered and the V2 page uses the V2 blocks', () => {
    assert.match(registry, /'ConsultationSettings\/Index'/);

    for (const block of [
        '<V2WorkLayout',
        '<PageFrame',
        '<V2FormCard',
        '<V2SwitchRow',
        '<V2Tabs',
        '<V2Table',
        '<V2Toolbar',
        '<V2Pager',
        '<V2RowMenu',
        '<V2Dialog',
    ]) {
        assert.ok(v2.includes(block), block);
    }
});

test('V2 calls the same routes with the same payloads, lock versions and list state', () => {
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
        assert.ok(squash(v2).includes(squash(key)), `v2 ${key}`);
    }
});

test('V2 keeps the three clinical modules off and unavailable, asks before archiving, and has no native select', () => {
    for (const name of [
        'Antenatal record (Buku Pink)',
        'Dental chart',
        'NCD follow-up',
    ]) {
        assert.ok(classic.includes(name) && v2.includes(name), name);
    }

    assert.ok(v2.includes('Not available yet.'));
    assert.match(v2, /archiving\.value = preset/);
    assert.ok(!/<select\b/.test(v2));
    assert.ok(v2.includes("title: 'Clinic management'"));
});

test('Classic imports nothing from V2', () => {
    assert.ok(!/\/V2\//.test(classic));
});
