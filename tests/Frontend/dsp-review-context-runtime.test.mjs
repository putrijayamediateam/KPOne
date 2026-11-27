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
test('readonly context distinguishes case branch, safely renders identity and retains only Back navigation', async () => {
    const html = await render();
    assert.match(html, /Synthetic &lt;Patient&gt;/);
    assert.doesNotMatch(html, /<Patient>/);
    assert.match(html, /Patient number/);
    assert.match(html, /KP-SYNTHETIC/);
    assert.match(html, /KPV-SYNTHETIC/);
    assert.match(html, /Case branch/);
    assert.match(
        html,
        /data-testid="review-case-branch">Synthetic Case Branch/,
    );
    assert.match(html, /Synthetic Active Branch/);
    assert.equal((html.match(/<a /g) ?? []).length, 1);
    assert.match(html, /href="\/visits\/KPV-SYNTHETIC"/);
    assert.doesNotMatch(html, /<(?:button|input|select|textarea|form)\b/);
    assert.match(html, /Allergies not recorded/);
    assert.doesNotMatch(
        html,
        /No known allergies|Doctor:|Received |Completed /,
    );
});
test('OTC and known allergy facts preserve real labels without inferred clinical actions', async () => {
    const html = await render({
        caseType: 'otc',
        status: 'completed',
        completedAt: '27 Nov 2026, 9:00 AM',
        allergies: {
            status: 'has_allergies',
            records: [
                {
                    allergen: 'Synthetic allergen',
                    reaction: 'Synthetic reaction',
                    severity: 'moderate',
                },
            ],
        },
    });
    assert.match(html, /Medicine only \(OTC\)/);
    assert.match(html, /Completed 27 Nov 2026, 9:00 AM/);
    assert.match(html, /Has allergies/);
    assert.match(html, /Synthetic allergen/);
    assert.match(html, /Synthetic reaction/);
    assert.match(html, /moderate/);
    assert.match(html, /No medicines on this case/);
    assert.doesNotMatch(html, /<(?:button|input|form)\b/);
});
