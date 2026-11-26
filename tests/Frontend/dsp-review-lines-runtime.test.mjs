import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';

const source = readFileSync('resources/js/pages/Dispensary/Review.vue', 'utf8');
const compiled = compileScript(parse(source).descriptor, {
    id: 'review-context',
    inlineTemplate: true,
});
const module = { exports: {} };
const blank = { render: () => null };
const imports = {
    vue,
    '@inertiajs/vue3': { Head: blank },
    '@lucide/vue': { ArrowLeft: blank, UserRound: blank },
    '@/components/ReadOnlyBanner.vue': {
        default: {
            setup:
                (_, { slots }) =>
                () =>
                    vue.h('aside', [
                        'Read only. Every view is recorded.',
                        slots.default?.(),
                    ]),
        },
    },
    '@/components/ui/page-header': {
        PageHeader: {
            props: ['title', 'description'],
            setup: (props) => () =>
                vue.h('header', [
                    props.title,
                    props.description,
                    vue.h(
                        'p',
                        { 'data-test': 'page-branch' },
                        'Synthetic Active Branch',
                    ),
                ]),
        },
    },
};
vm.runInNewContext(
    ts.transpileModule(compiled.content, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    {
        exports: module.exports,
        require: (name) => {
            assert.ok(name in imports, name);

            return imports[name];
        },
    },
);
const defaults = {
    status: 'pending',
    caseType: 'consultation',
    branch: 'Synthetic Case Branch',
    patient: { name: 'Synthetic <Patient>', patientNumber: 'KP-SYNTHETIC' },
    visit: { visitNumber: 'KPV-SYNTHETIC' },
    doctor: null,
    receivedAt: null,
    completedAt: null,
    allergies: { status: 'unknown', records: [] },
    medicines: [],
    services: [],
    returns: [],
    returnsTotal: 0,
};
const render = (review) =>
    renderToString(
        vue.createSSRApp(module.exports.default, {
            review: { ...defaults, ...review },
        }),
    );
const side = {
    quantity: '2.000',
    dosage: 'Synthetic dose',
    frequency: null,
    duration: null,
    instruction: null,
};
test('readonly line cards preserve doctor and CA distinctions, pack precedence and missing batch facts', async () => {
    const html = await render({
        medicines: [
            {
                name: 'Synthetic <Medicine>',
                strength: null,
                unit: 'bottle',
                source: 'ca',
                changeState: null,
                status: 'active',
                ordered: side,
                dispensed: { ...side, packText: '2 bottles (120 ml)' },
                batches: [
                    { batchNumber: null, expiryDate: null, quantity: '2.000' },
                ],
            },
        ],
    });
    assert.match(html, /Synthetic &lt;Medicine&gt;/);
    assert.match(html, /Added by CA/);
    assert.match(html, /Not ordered by the doctor/);
    assert.match(html, /2 bottles \(120 ml\)/);
    assert.match(html, /Batch — · exp — · 2 bottle/);
    assert.match(html, /<dt[^>]*>\s*Doctor ordered\s*<\/dt><dd/);
    assert.match(html, /<dt[^>]*>\s*Dispensed\s*<\/dt><dd/);
    assert.doesNotMatch(html, /<(?:button|input|select|form)\b/);
});
test('removed and edited records retain their quantities; returns stay pending unless outcome supplied', async () => {
    const medicines = ['removed', 'edited'].map((changeState) => ({
        name: 'Synthetic medicine',
        strength: '10mg',
        unit: 'tablet',
        source: 'doctor',
        changeState,
        status: changeState,
        ordered: side,
        dispensed: side,
        batches: [],
    }));
    const html = await render({
        medicines,
        services: [
            {
                name: 'Synthetic Service',
                unit: null,
                source: 'doctor',
                changeState: null,
                quantityOrdered: '3.000',
                quantityPerformed: '2.000',
            },
        ],
        returns: [
            {
                medicine: null,
                unit: 'tablet',
                quantity: '1.000',
                reason: 'not_used',
                condition: 'sealed',
                outcome: null,
                recordedAt: null,
            },
            {
                medicine: 'Synthetic Medicine',
                unit: 'tablet',
                quantity: '2.000',
                reason: 'other',
                condition: 'opened',
                outcome: 'refuse',
                recordedAt: '27 Nov 2026, 9:00 AM',
            },
        ],
        returnsTotal: 12,
    });
    assert.match(html, /Removed by CA/);
    assert.match(html, /Edited by CA/);
    assert.match(html, /2 tablet · Synthetic dose/);
    assert.match(html, /Ordered 3 · Performed 2/);
    assert.match(html, /Waiting for a check/);
    assert.match(html, /Refused/);
    assert.match(html, /Patient did not use it/);
    assert.match(html, /Showing the latest 2 of 12/);
    assert.doesNotMatch(html, /<(?:button|input|select|form)\b/);
});
