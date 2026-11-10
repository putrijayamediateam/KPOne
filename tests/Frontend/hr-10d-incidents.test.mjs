import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-10d incident reports (HR-PLAN 20.6; owner, 2026-11-10).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    list: 'resources/js/pages/HR/Incidents.vue',
    report: 'resources/js/pages/HR/Incident.vue',
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

test('the report form warns against patient details and uses the shared photo button', () => {
    const page = template('list');
    assert.match(page, /Do not write a patient's name, IC or diagnosis\./);
    assert.match(page, /<FileChooseButton/);
    assert.doesNotMatch(page, /<input[^>]*type="file"/);
    assert.match(page, /accept="image\/jpeg,image\/png"/);
    assert.match(page, /label="Needs clinical review"/);
    assert.match(script('list'), /forceFormData: true/);
});

test('the reporter sees the status only; notes and actions are manager-only', () => {
    const page = template('report');
    assert.match(
        page,
        /<section\s+v-if="!r\.manager"[\s\S]{0,200}data-test="incident-status"/,
    );
    assert.match(
        page,
        /<template v-if="r\.manager">[\s\S]*data-test="incident-notes"/,
    );
    assert.match(page, /Not shown to the person who reported it\./);
});

test('closing is a destructive confirm, blocked while an action is open; nothing is sent outside KPOne', () => {
    const page = template('report');
    assert.match(page, /title="Close this report\?"[\s\S]{0,300}destructive/);
    assert.match(page, /:disabled="openActions > 0"/);
    assert.match(page, /Mark every action done first\./);

    for (const key of Object.keys(files)) {
        assert.doesNotMatch(script(key), /\/hr\/messages|mailto:|wa\.me/);
    }
});
