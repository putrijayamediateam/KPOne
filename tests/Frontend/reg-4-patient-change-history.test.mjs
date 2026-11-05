import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-4 (owner decisions 2026-11-05): field names only, CA Supervisor and Director only, replaces the raw list.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const section = read(
    'resources/js/components/patient/PatientChangeHistory.vue',
);
const show = read('resources/js/pages/Patient/Show.vue');
const types = read('resources/js/types/patient.ts');

test('REG-4: the patient page shows Change history only from its own prop and drops the raw list', () => {
    assert.match(
        show,
        /<PatientChangeHistory v-if="history" :history="history"/,
    );
    assert.match(show, /history\?: PatientChangeHistoryData \| null/);
    assert.doesNotMatch(show, /Authorized activity|patient\.activity/);
    assert.doesNotMatch(types, /activity:/);
});

test('REG-4: rows show server labels and field names, never raw codes or values', () => {
    assert.match(section, /\{\{ row\.label \}\}/);
    assert.match(section, /v-for="field in row\.fields"/);
    assert.doesNotMatch(section, /row\.event \}\}|changedFields|metadata/);
    assert.match(
        section,
        /Shows which details changed, not the old or new values\./,
    );
});

test('REG-4: older rows load by cursor and every state has its wording', () => {
    assert.match(
        section,
        /'\/history\?cursor=' \+\s*encodeURIComponent\(nextCursor\.value\)/,
    );
    assert.match(section, /Show older changes/);
    assert.match(section, /Loading older changes…/);
    assert.match(section, /Could not load older changes\. Try again\./);
    assert.match(section, /All changes shown/);
    assert.match(section, /No changes recorded yet\./);
});

test('REG-4: the section is accessible and has no fixed-width layout', () => {
    assert.match(section, /aria-labelledby="patient-history-heading"/);
    assert.match(section, /<time :datetime="row\.occurredAt">/);
    assert.match(section, /class="sr-only"/);
    assert.match(section, /aria-live="polite" role="status"/);
    assert.match(section, /:aria-disabled="loading"/);
    assert.doesNotMatch(section, /:disabled="loading"/);
    assert.match(section, /signal: controller\.signal/);
    assert.doesNotMatch(section, /\bw-\[\d+px\]|bg-[a-z]+-50\b/);
});
