import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    'resources/js/components/registration/RegistrationReviewSummary.vue',
    'utf8',
);
const module = { exports: {} };
runInNewContext(
    ts.transpileModule(
        compileScript(parse(source).descriptor, {
            id: 'registration-review',
            inlineTemplate: true,
        }).content,
        {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        },
    ).outputText,
    { exports: module.exports, require },
);
const defaults = {
    patientName: 'Synthetic Patient',
    patientNumber: 'KP-TEST-1',
    newPatient: false,
    branch: 'Synthetic Branch',
    visitType: 'consultation',
    doctor: 'No doctor',
    reasons: [],
    priority: 'normal',
    coverage: 'self_pay',
    panel: 'No panel selected',
    memberReference: '',
};
const render = (props = {}) =>
    renderToString(
        createSSRApp({
            render: () => h(module.exports.default, { ...defaults, ...props }),
        }),
    );

test('saved patient and current choices render without a new submission or readiness control', async () => {
    const html = await render();

    for (const value of [
        'Synthetic Patient',
        'KP-TEST-1',
        'Synthetic Branch',
        'Consultation',
        'No doctor',
        'Self-pay',
        'No reasons selected',
    ]) {
        assert.ok(html.includes(value));
    }

    assert.doesNotMatch(html, /<button|<input|<form|Queue number/);
});

test('new patient has no invented identifier and escaped draft values', async () => {
    const html = await render({
        newPatient: true,
        patientName: '<script>draft</script>',
        patientNumber: undefined,
    });
    assert.match(html, /New patient — created with this visit/);
    assert.match(html, /&lt;script&gt;draft&lt;\/script&gt;/);
    assert.doesNotMatch(html, /KP-TEST|<script>/);
    assert.match(
        await render({ newPatient: true, patientName: '' }),
        /Name not entered/,
    );
});

test('ordered reasons retain one primary and OTC optional selections remain visible', async () => {
    const html = await render({
        visitType: 'otc',
        doctor: 'Synthetic Doctor',
        reasons: ['Second chosen first', 'First chosen second'],
        priority: 'urgent',
    });
    assert.ok(
        html.indexOf('Second chosen first') <
            html.indexOf('First chosen second'),
    );
    assert.equal((html.match(/Primary/g) || []).length, 1);

    for (const value of ['OTC', 'Synthetic Doctor', 'Urgent']) {
        assert.ok(html.includes(value));
    }
});

test('coverage changes cannot display a stale hidden member reference', async () => {
    assert.doesNotMatch(
        await render({ memberReference: 'STALE-REF' }),
        /STALE-REF/,
    );
    const html = await render({
        coverage: 'panel',
        panel: 'Synthetic Panel',
        memberReference: 'MEMBER-TEST',
    });
    assert.match(html, /Synthetic Panel/);
    assert.match(html, /MEMBER-TEST/);
    assert.match(await render({ coverage: 'panel' }), /No panel selected/);
});

test('unconfirmed summary describes the retained original command without implying that nothing was saved', async () => {
    const html = await render({ unconfirmed: true });
    assert.match(html, /original details retained for unchanged retry/);
    assert.match(html, /registration result is unconfirmed/);
    assert.doesNotMatch(html, /Nothing is saved/);
    assert.match(html, /Synthetic Patient/);
    assert.match(html, /KP-TEST-1/);
    assert.doesNotMatch(html, /<button|<input|<form/);
    assert.match(await render(), /Nothing is saved until you register the/);
});
