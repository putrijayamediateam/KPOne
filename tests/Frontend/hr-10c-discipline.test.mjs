import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-10c discipline (HR-PLAN 20.5; owner, 2026-11-10).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    list: 'resources/js/pages/HR/Discipline.vue',
    case: 'resources/js/pages/HR/DisciplineCase.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;
const script = (key) => parse(source[key]).descriptor.scriptSetup.content;

test('the new pages compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the list shows no allegation or note text, only names, dates and status', () => {
    const page = template('list');
    assert.doesNotMatch(page, /allegation\s*\}\}/);
    assert.match(page, /data-test="case-row"/);
    assert.match(
        page,
        /Cases about you, or about someone only a Director may handle, are not listed here\./,
    );
    assert.match(page, /no patient name, IC or diagnosis/);
});

test('the outcome closes the case through a destructive confirm; warnings need a letter', () => {
    const page = template('case');
    assert.match(page, /title="Record the outcome\?"[\s\S]{0,400}destructive/);
    assert.match(page, /KPOne does not end employment or change the account\./);
    assert.match(page, /v-if="chosen\?\.needsLetter"/);
    assert.match(
        script('case'),
        /expected_lock_version: props\.case\.lockVersion/,
    );
});

test('entries are added, never edited; actions show only while the case is open', () => {
    const page = template('case');
    assert.match(page, /<template v-if="isOpen">/);

    for (const path of ['notes', 'replies', 'show-cause', 'files', 'close']) {
        assert.match(script('case'), new RegExp(`'${path}'`));
    }

    assert.doesNotMatch(script('case'), /router\.(put|patch|delete)\(/);
    assert.doesNotMatch(script('case'), /\/hr\/messages|mailto:|wa\.me/);
});
