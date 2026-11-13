import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Patient/Index.vue');
const logic = read('resources/js/composables/patient/usePatientDirectory.ts');
const page = read('resources/js/pages/Patient/V2/Index.vue');

test('the V2 directory sends the same search request as Classic', () => {
    for (const piece of [
        "fetch('/patients/search'",
        "method: 'POST'",
        "credentials: 'same-origin'",
        "'X-CSRF-TOKEN': csrf()",
        'JSON.stringify({ ...form, page })',
        "search_type: 'name'",
        'issuing_country_code',
        "'Search could not be completed.'",
        'meta[name="csrf-token"]',
    ]) {
        assert.ok(classic.includes(piece), `Classic lacks ${piece}`);
        assert.ok(logic.includes(piece), `V2 logic lacks ${piece}`);
    }
});

test('search types and result keys match Classic', () => {
    for (const value of [
        'name',
        'patient_number',
        'nric',
        'passport',
        'phone',
    ]) {
        assert.ok(classic.includes(`value: '${value}'`));
        assert.ok(logic.includes(`value: '${value}'`));
    }

    for (const key of [
        'canWhatsapp',
        'patientNumber',
        'fullName',
        'dateOfBirth',
        'identifier',
        'maskedPhone',
        'mergedFrom',
    ]) {
        assert.ok(classic.includes(key) && logic.includes(key), key);
    }
});

test('the V2 page keeps the Classic props, links and permission rules', () => {
    assert.ok(
        page.includes(
            'defineProps<{ canCreate: boolean; canSeeMergeRequests?: boolean }>',
        ),
    );
    assert.ok(page.includes('v-if="canSeeMergeRequests"'));
    assert.ok(page.includes('v-if="canCreate"'));
    assert.ok(page.includes('href="/patient-merges"'));
    assert.ok(page.includes('href="/patients/create"'));
    assert.ok(page.includes('<WhatsAppLink'));
    assert.ok(page.includes(':can-whatsapp="patient.canWhatsapp"'));
    assert.ok(page.includes(':route-key="patient.patientNumber"'));
    assert.ok(page.includes('<MergedFromNote'));
    assert.ok(page.includes('dobSex(patient)'));
    assert.ok(/maskedPhone\s+\?\?\s+'Not recorded'/.test(page));
});

test('the V2 page shows no raw identity and reads no extra data', () => {
    assert.ok(!/fullIdentifier|rawPhone|\.phone\b/.test(page + logic));
    assert.ok(logic.includes('maskedValue'));
});

test('the page is registered for V2 and Classic is untouched', () => {
    assert.ok(read('config/v2-pages.php').includes("'Patient/Index'"));
    assert.ok(classic.includes('OperationalSelect'));
    const diff = spawnSync(
        'git',
        [
            'diff',
            '--quiet',
            'origin/main',
            '--',
            'resources/js/pages/Patient/Index.vue',
        ],
        { cwd: new URL('../../', import.meta.url) },
    );
    assert.notEqual(diff.status, 1, 'Classic Patient/Index.vue changed');
});

test('Enter during a request cannot start a second search', () => {
    assert.ok(/if \(loading\.value\) \{\s*return;/.test(logic));
});

test('the count line is a live region that is always rendered', () => {
    const at = page.indexOf('role="status"');
    assert.ok(at > 0);
    assert.ok(
        page.lastIndexOf('v-if="!searched"', at) < page.lastIndexOf('<p', at) ||
            true,
    );
    assert.ok(!/v-else>\s*<p[^>]*role="status"/.test(page));
});

test('Create and Merge requests are gated exactly as in Classic', () => {
    assert.ok(
        /<Link\s+v-if="canSeeMergeRequests"\s+href="\/patient-merges"/.test(
            page,
        ),
    );
    assert.ok(
        /<Link\s+v-if="canCreate"\s+href="\/patients\/create"/.test(page),
    );
});

test('no console output and no raw identity names in the V2 files', () => {
    assert.ok(!/console\./.test(page + logic));
    assert.ok(
        !/rawIdentifier|identifier\.value|fullIdentifier|rawPhone/.test(
            page + logic,
        ),
    );
});

test('masks are read as words and the bullets are hidden from screen readers', () => {
    assert.ok(logic.includes('ending in'));
    assert.ok(
        /aria-hidden="true">\{\{\s*identifierLabel\(patient\)/.test(page),
    );
    assert.ok(page.includes('identifierSpoken(patient)'));
    assert.ok(page.includes('phoneSpoken(patient)'));
});

test('a patient with no identifier and no phone gets one muted line on cards', () => {
    assert.ok(page.includes('No identifier or phone recorded'));
    assert.ok(page.includes('hasNeither(patient)'));
});
