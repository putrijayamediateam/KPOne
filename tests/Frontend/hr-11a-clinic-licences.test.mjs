import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-11a clinic licences (owner 2026-11-10: "i want to start all that"; order approved: licences, fridge log, audits).
const path = 'resources/js/pages/ClinicCompliance/Index.vue';
const src = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const template = parse(src).descriptor.template.content;

test('the page compiles', () => {
    assert.deepEqual(
        compileTemplate({ id: 'cc', source: template, filename: path }).errors,
        [],
    );
});

test('statuses come from the server and expired or expiring are warnings, never red', () => {
    assert.match(src, /:label="row\.status\.words"/);
    assert.match(src, /code === 'expired' \|\| code === 'expiring'/);
    assert.doesNotMatch(src, /destructive|text-red|bg-red/);
});

test('every field has a visible label and every error is announced through InputError', () => {
    for (const id of [
        'lic-ref',
        'lic-issued',
        'lic-expires',
        'lic-note',
        'type-name',
        'type-warn',
    ]) {
        assert.match(src, new RegExp(`<Label for="${id}"`), id);
    }

    for (const field of [
        'type_id',
        'branch_id',
        'reference',
        'issued_on',
        'expires_on',
        'files',
        'reason',
        'name',
        'warn_from_days',
    ]) {
        assert.match(src, new RegExp(`errors\\.${field}`), field);
    }
});

test('touch targets are 44 px below 1024 px and saves carry an idempotency key and the lock version', () => {
    assert.match(src, /min-h-11 lg:min-h-9/);
    assert.match(src, /crypto\.randomUUID\(\)/);
    assert.match(src, /expected_lock_version/);
    assert.match(src, /forceFormData: true/);
});

test('files open through the audited route and at most three can be added', () => {
    assert.match(src, /`\$\{base\}\/files\/\$\{f\.id\}`/);
    assert.match(src, /sheet\.files\.length < 3/);
});

test('dates use the shared dd/mm/yyyy box, Expired has an icon and stronger text, and History is a chevron button', () => {
    assert.match(src, /<DateField/);
    assert.doesNotMatch(src, /type="date"/);
    assert.match(src, /status\.code === 'expired'/);
    assert.match(src, /TriangleAlert/);
    assert.match(src, /:aria-expanded="openRows\.has\(row\.id\)"/);
    assert.doesNotMatch(src, /<details|<summary/);
    assert.match(src, /Suggested names:/);
});
