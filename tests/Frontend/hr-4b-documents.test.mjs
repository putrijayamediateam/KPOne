import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-4b documents and checklists (owner 2026-11-06; HR-PLAN 11.3 rules 10 and 11, Daniel's early look in 11.6).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const dir = 'resources/js/components/hr/records/';
const files = {
    list: `${dir}DocumentList.vue`,
    checklist: `${dir}ChecklistTab.vue`,
    file: 'resources/js/pages/HR/StaffFile.vue',
    records: 'resources/js/pages/HR/Records.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('the new components and the changed pages compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('a document shows its type, dates and an expiry chip in words, warning tone, and opening says it is recorded', () => {
    const list = template('list');
    assert.match(list, /d\.categoryLabel/);
    assert.match(list, /v-if="d\.expiry"[\s\S]*?tone="warning"/);
    assert.doesNotMatch(list, /tone="danger"/);
    assert.match(list, /Opening a document is recorded\./);
    assert.match(list, /\/hr\/records\/\$\{workerId\}\/documents\/\$\{d\.id\}/);
    // No original file name is shown.
    assert.doesNotMatch(source.list, /filename|originalName|\.name\b/);
});

test('the staff file gains Documents and Checklist tabs; upload is a sheet with visible labels and remove asks for a reason', () => {
    const file = template('file');
    assert.match(source.file, /value: 'documents', label: 'Documents'/);
    assert.match(source.file, /value: 'checklist', label: 'Checklist'/);
    assert.match(
        file,
        /<Label for="document-category"[\s\S]{0,40}<span id="document-category-label"\s*>Type \*<\/span/,
    );
    assert.match(file, /labelledby="document-category-label"/);
    assert.match(file, /<Label for="document-file">File \*<\/Label>/);
    assert.match(file, /accept="application\/pdf,image\/jpeg,image\/png"/);
    assert.match(file, /PDF, JPG or PNG, up to 10 MB/);
    assert.match(
        file,
        /<Label for="document-remove-reason"[\s\S]{0,40}<span id="document-remove-reason-label"\s*>Why it is removed \*<\/span/,
    );
    assert.match(source.file, /forceFormData: true/);

    for (const b of file.match(/<Button[^>]*data-test="add-document"[^>]*>/g) ??
        []) {
        assert.match(b, /variant="outline"/);
    }
});

test('checklists: onboarding always, offboarding only for an ended period, account items ask the administrator', () => {
    const checklist = template('checklist');
    assert.match(checklist, /p\.offboarding/);
    assert.match(
        checklist,
        /Ask the account administrator\. HR does not\s+change accounts\./,
    );
    assert.match(checklist, /'Mark as asked'\s*: 'Mark done'/);
    // A tick is final, so it is confirmed with the item and period named (Suresh, S3).
    assert.match(
        checklist,
        /<OperationalConfirmDialog[\s\S]*@confirm="confirmTick"/,
    );
    assert.match(source.checklist, /It cannot be undone\./);
    assert.doesNotMatch(checklist, /@click="tick\(/);
    assert.match(
        checklist,
        /The offboarding list appears once this period has an end date\./,
    );
    assert.match(
        checklist,
        /\{\{ done\(list\.items\) \}\} of\s+\{\{ list\.items\.length \}\} done/,
    );
});

test('My HR details lists your own documents; settings sit under Staff files with no shipped retention', () => {
    const records = template('records');
    assert.match(records, /data-test="my-documents"/);
    assert.match(records, /data-test="records-settings"/);
    assert.match(records, /KPOne ships no number/);
    assert.match(records, /placeholder="Not set"/);
    // Saving a retention is confirmed, and a first or shorter one waits (Suresh, S1).
    assert.match(records, /title="Save this retention\?"/);
    assert.match(source.records, /This starts in \$\{grace\} days/);
    assert.match(
        source.records,
        /Changes to \$\{dayCount\(r\.pendingDays\)\} on/,
    );
    assert.match(source.records, /toLocaleString\('en-US'\)/);
    assert.match(records, /\{\{ r\.label \}\} \(days\)/);
});
