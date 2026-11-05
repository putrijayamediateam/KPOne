import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-3 (owner decisions 2026-11-05): two-person merge by link, fixed reasons, English labels.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const panel = read('resources/js/components/patient/PatientMergePanel.vue');
const show = read('resources/js/pages/Patient/Show.vue');
const index = read('resources/js/pages/Patient/Index.vue');
const inbox = read('resources/js/pages/Patient/MergeRequests.vue');

test('REG-3: the patient page shows the merge panel only from its own prop', () => {
    assert.match(show, /<PatientMergePanel\s+v-if="merge"/);
    assert.match(show, /merge\?: PatientMergePanelData/);
});

test('REG-3: proposing checks the duplicate first and sends only a fixed reason', () => {
    assert.match(panel, /'\/merge-compare\?other_patient_number='/);
    assert.match(panel, /v-for="option in merge\.mergeReasons"/);
    assert.match(panel, /v-for="option in merge\.unmergeReasons"/);
    assert.doesNotMatch(panel, /<textarea/);
    assert.match(panel, /idempotency_key: crypto\.randomUUID\(\)/);
    assert.match(panel, /v-if="comparison\.blockers\.length"/);
    assert.match(panel, /Send for approval/);
});

test('REG-3: approval actions are offered only where the server allows them', () => {
    for (const source of [panel, inbox]) {
        assert.match(source, /<template v-if="request\.canDecide">/);
        assert.match(source, /v-if="request\.canWithdraw"/);
        assert.match(source, /lock_version: request\.lockVersion/);
        assert.match(
            source,
            /`\/patient-merges\/\$\{request\.publicId\}\/\$\{action\}`/,
        );
    }
});

test('REG-3: the comparison shows masked identity only', () => {
    assert.match(panel, /summary\.identifier\.maskedValue/);
    assert.match(panel, /summary\.maskedPhone/);
    assert.doesNotMatch(panel, /mobilePhone|normalized_value|normalizedValue/);
});

test('REG-3: the patient directory links to merge requests for permitted staff', () => {
    assert.match(index, /v-if="canSeeMergeRequests"/);
    assert.match(index, /href="\/patient-merges"/);
});

test('REG-3: merge screens use English labels', () => {
    for (const source of [panel, inbox]) {
        assert.doesNotMatch(
            source,
            /\b(gabung|pesakit|lulus|tolak|sila|sebab)\b/i,
        );
    }
});
