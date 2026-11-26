import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as Vue from 'vue';

const read = (path) => readFileSync(path, 'utf8');
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const button = {
    setup:
        (_, { slots }) =>
        () =>
            Vue.h('button', slots.default?.()),
};
const render = async (path, props, extra = {}) => {
    const source = compileScript(parse(read(path)).descriptor, {
        id: 'treatment-ui',
        inlineTemplate: true,
    }).content;
    const module = { exports: {} };
    Function(
        'require',
        'exports',
        transpile(source),
    )(
        (name) =>
            ({
                vue: Vue,
                '@/components/ui/button': { Button: button },
                '@/lib/presentation': { formatDateTime: (value) => value },
                ...extra,
            })[name],
        module.exports,
    );

    return renderToString(
        Vue.createSSRApp({
            render: () => Vue.h(module.exports.default, props),
        }),
    );
};
const record = {
    id: 1,
    procedureCode: 'urine_sample',
    procedureLabel: 'Urine sample',
    outcomeCode: 'not_successful',
    outcomeLabel: 'Not successful',
    reasonCode: 'patient_declined',
    reasonLabel: 'Patient declined',
    startedAt: '2026-11-26T01:00:00Z',
    finishedAt: '2026-11-26T01:05:00Z',
    recordedAt: '2026-11-26T01:05:00Z',
    recordedBy: 'Synthetic MA',
    status: 'current',
    supersedesRecordId: null,
    correctionCode: null,
    voidReason: null,
    voidedAt: null,
    voidedBy: null,
};
const list =
    'resources/js/components/treatment-room/TreatmentRoomRecordList.vue';

test('read-only treatment records keep unsuccessful reason visible without mutation controls', async () => {
    const html = await render(list, {
        records: { current: [record], history: [record] },
        timezone: 'Asia/Kuala_Lumpur',
    });
    assert.match(html, /Not successful.*Patient declined/s);
    assert.match(html, /Synthetic MA/);
    assert.doesNotMatch(html, /Correct record|Void: wrong patient|<button/);
});

test('superseded history identifies the successor correction and exposes actions only on current evidence', async () => {
    const original = { ...record, status: 'superseded' };
    const successor = {
        ...record,
        id: 2,
        supersedesRecordId: 1,
        correctionCode: 'wrong_outcome',
        outcomeCode: 'done',
        outcomeLabel: 'Done',
        reasonCode: null,
        reasonLabel: null,
    };
    const html = await render(list, {
        records: { current: [successor], history: [successor, original] },
        timezone: 'Asia/Kuala_Lumpur',
        canCorrect: true,
    });
    assert.match(html, /Superseded: Wrong outcome/);
    assert.match(html, /line-through/);
    assert.equal((html.match(/Correct record/g) ?? []).length, 1);
    assert.equal((html.match(/Void: wrong patient/g) ?? []).length, 1);
});

test('wrong-patient void is not presented as a new failed treatment or a cross-patient link', async () => {
    const voided = {
        ...record,
        outcomeCode: 'done',
        outcomeLabel: 'Done',
        reasonCode: null,
        reasonLabel: null,
        status: 'voided',
        voidReason: 'Wrong patient',
        voidedAt: record.recordedAt,
        voidedBy: 'Synthetic Supervisor',
    };
    const html = await render(list, {
        records: { current: [], history: [voided] },
        timezone: 'Asia/Kuala_Lumpur',
        canCorrect: true,
    });
    assert.match(html, /No current treatment-room record/);
    assert.match(html, /Entered for the wrong patient/);
    assert.match(html, /original evidence is retained/);
    assert.doesNotMatch(
        html,
        /Not successful|<a\b|Correct record|Void: wrong patient/,
    );
});

const alertHarness = (details) => {
    const messages = [];
    const module = { exports: {} };
    Function(
        'require',
        'exports',
        'localStorage',
        transpile(read('resources/js/composables/useTreatmentDoneAlerts.ts')),
    )(
        (name) =>
            ({
                vue: {
                    ref: (value) => ({ value }),
                    onBeforeUnmount: () => {},
                    onMounted: () => {},
                },
                'vue-sonner': {
                    toast: {
                        success: (text) => messages.push(['success', text]),
                        warning: (text) => messages.push(['warning', text]),
                    },
                },
                '@/lib/json-client': { requestJson: async () => null },
            })[name],
        module.exports,
        { getItem: () => 'false' },
    );

    return { alerts: module.exports.useTreatmentDoneAlerts(details), messages };
};
test('authorized doctor alert explicitly includes unsuccessful outcome and reason with warning tone', () => {
    const h = alertHarness(true);
    h.alerts.announce({
        cursor: 1,
        ready: true,
        doneCount: 1,
        numbers: ['A-001 Urine sample'],
        outcomes: [
            {
                number: 'A-001',
                procedureLabel: 'Urine sample',
                outcomeLabel: 'Not successful',
                reasonLabel: 'Patient declined',
            },
        ],
    });
    assert.equal(h.messages[0][0], 'warning');
    assert.match(
        h.messages[0][1],
        /Urine sample: Not successful: Patient declined/,
    );
});
test('summary-only alert never renders supplied outcome details', () => {
    const h = alertHarness(false);
    h.alerts.announce({
        cursor: 1,
        ready: true,
        doneCount: 1,
        outcomes: [
            {
                number: 'A-001',
                procedureLabel: 'Urine sample',
                outcomeLabel: 'Not successful',
                reasonLabel: 'Patient declined',
            },
        ],
    });
    assert.doesNotMatch(h.messages[0][1], /Urine|declined|A-001/);
});
