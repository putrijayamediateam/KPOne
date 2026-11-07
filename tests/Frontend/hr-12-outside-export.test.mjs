import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-12 export for an outside HR product (HR-PLAN 15.1; the owner's "start the HR-12 build" and answers, 2026-11-07).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    card: 'resources/js/components/hr/records/OutsideExportCard.vue',
    records: 'resources/js/pages/HR/Records.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('the export card and the records page compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('each file downloads through a recorded POST and is never stored', () => {
    assert.match(source.card, /method: 'POST'/);
    assert.match(source.card, /'X-CSRF-TOKEN'/);
    assert.match(source.card, /\/hr\/records\/outside-export\/\$\{kind\}/);
    assert.match(template('card'), /Download staff list/);
    assert.match(template('card'), /Download approved leave/);
    assert.match(template('card'), /Download approved overtime/);
    assert.match(template('card'), /no\s+file is kept in KPOne/);
    assert.match(
        template('card'),
        /never holds identity numbers, bank\s+details or documents/,
    );
});

test('only managers see it, on the Staff files tab', () => {
    assert.match(
        template('records'),
        /<OutsideExportCard\s+v-if="records\.tab === 'files' && records\.canManage"/,
    );
});

test('nothing in HR-12 sends a message', () => {
    assert.doesNotMatch(source.card, /whatsapp|mailto:|sms:/i);
});
