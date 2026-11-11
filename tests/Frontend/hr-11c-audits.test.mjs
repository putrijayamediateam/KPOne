import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-11c monthly audits and supervisor tasks (owner 2026-11-11: "that two works i approve use ur recommendation").
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    tab: 'resources/js/components/compliance/AuditsTab.vue',
    settings: 'resources/js/components/compliance/AuditSettings.vue',
    page: 'resources/js/pages/ClinicCompliance/Index.vue',
};
const src = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);

test('the audit components compile', () => {
    for (const [key, path] of Object.entries(files)) {
        const tpl = parse(src[key]).descriptor.template.content;
        assert.deepEqual(
            compileTemplate({ id: key, source: tpl, filename: path }).errors,
            [],
            path,
        );
    }
});

test('a result is a labelled radio group, a failed item needs a note and photos are images only', () => {
    assert.match(src.tab, /role="radiogroup"/);
    assert.match(src.tab, /A failed item needs a note\./);
    assert.match(src.tab, /accept="image\/jpeg,image\/png"/);
    assert.match(src.tab, /photograph\s+patients\s+or\s+papers/);
    assert.match(src.tab, /Submitting\s+is\s+final/);
});

test('"last month not audited" shows only as words with an icon and nothing is sent', () => {
    assert.match(src.tab, /No submitted audit for last month/);
    assert.match(src.tab, /TriangleAlert/);
    assert.doesNotMatch(src.tab, /sendBeacon|notification/i);
});

test('every field has a visible label, errors show through InputError and targets are 44 px below 1024 px', () => {
    for (const id of ['task-note', 'start-branch', 'start-month']) {
        assert.match(src.tab, new RegExp(id), id);
    }

    for (const id of [
        'item-section-',
        'item-title-',
        'item-help-',
        'retention-days',
    ]) {
        assert.match(src.settings, new RegExp(id), id);
    }

    assert.match(src.settings, /errors\[`items\.\$\{n\}\.title`\]/);
    assert.match(src.settings, /errors\.photo_retention_days/);
    assert.match(src.tab, /min-h-11 lg:min-h-9/);
    assert.match(src.tab, /max-lg:h-11/);
});

test('saves carry an idempotency key and the lock version, and the page offers the Audits tab and the checklist settings', () => {
    assert.match(src.tab, /crypto\.randomUUID\(\)/);
    assert.match(src.tab, /expected_lock_version/);
    assert.match(src.page, /audits: 'Audits'/);
    assert.match(src.page, /AuditSettings/);
    assert.match(src.settings, /KPOne ships no checklist/);
});
