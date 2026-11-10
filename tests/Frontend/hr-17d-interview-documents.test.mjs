import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-17d interview documents (HR-PLAN 19.7).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    status: 'resources/js/pages/Careers/Status.vue',
    hr: 'resources/js/pages/HR/Applications.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('both pages compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the candidate sees the documents only inside the confirmed interview card, in both languages', () => {
    const t = template('status');
    const card = t.slice(
        t.indexOf('data-test="status-interview"'),
        t.indexOf('data-test="status-slots"'),
    );

    assert.match(card, /data-test="status-documents"/);
    assert.match(card, /Please read before your interview/);
    assert.match(card, /Sila baca sebelum temu duga anda/);
    assert.match(card, /`\/careers\/status\/documents\/\$\{doc\.id\}`/);
    assert.match(card, /min-h-11/);
    assert.doesNotMatch(source.status, /localStorage|sessionStorage/);
});

test('HR adds PDFs per position type with the shared file button, and removes them through a dialog', () => {
    const t = template('hr');

    for (const id of [
        'interview-documents',
        'interview-document-add',
        'interview-document-upload',
        'interview-document-remove',
        'interview-document-full',
        'interview-downloads',
    ]) {
        assert.ok(t.includes(`data-test="${id}"`), id);
    }

    assert.match(t, /<FileChooseButton/);
    assert.match(t, /accept="application\/pdf,\.pdf"/);
    assert.match(t, /confirm-label="Remove document"/);
    assert.match(t, /cancel-label="Keep document"/);
    assert.match(source.hr, /'\/hr\/applications\/interview-documents'/);
    assert.match(source.hr, /forceFormData: true/);
    assert.match(source.hr, /const DOCS_MAX = 10;/);
    assert.match(t, /not downloaded yet/);
    assert.match(t, /Choose a PDF and give it a title first\./);
    assert.match(t, /<h2 id="interview-docs-title"/);
    assert.doesNotMatch(t, /dummy PDFs/);
});
