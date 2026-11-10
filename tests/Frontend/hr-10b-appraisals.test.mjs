import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-10b appraisal (HR-PLAN 20.4; owner, 2026-11-10).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    page: 'resources/js/pages/HR/Appraisals.vue',
    appraisal: 'resources/js/pages/HR/Appraisal.vue',
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

test('sending asks to confirm first; drafts save without one', () => {
    const page = template('appraisal');
    assert.match(page, /@submit\.prevent="confirmSend = true"/);
    assert.match(page, /@confirm="submit\(true\)"/);
    assert.match(page, /data-test="appraisal-save-draft"/);
    assert.match(
        script('appraisal'),
        /expected_lock_version: props\.appraisal\.lockVersion/,
    );
    assert.match(script('appraisal'), /idempotency_key: form\.key/);
});

test('sign off and comment follow the server flags, with a confirm for sign off', () => {
    const page = template('appraisal');
    assert.match(page, /@click="confirmSignOff = true"/);
    assert.match(page, /confirm-label="Sign off"/);
    assert.match(page, /appraisal\.can\.signOff/);
    assert.match(page, /appraisal\.can\.comment/);
    assert.match(page, /appraisal\.can\.edit/);
});

test('every rating has an accessible name; nothing is sent outside KPOne', () => {
    assert.match(
        template('appraisal'),
        /:aria-label="`Rating for \$\{c\.title\}, 1 to 5`"/,
    );

    for (const key of Object.keys(files)) {
        assert.doesNotMatch(script(key), /\/hr\/messages|mailto:|wa\.me/);
    }
});

test('closing a cycle confirms; criteria and cycles are manager-only tabs', () => {
    const page = template('page');
    assert.match(page, /title="Close this cycle\?"/);
    assert.match(page, /confirm-label="Close cycle"/);
    assert.match(page, /data-test="criteria-form"/);
    assert.match(page, /data-test="cycle-sheet"/);
    assert.match(page, /data-test="my-appraisals"/);
});
