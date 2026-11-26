import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { useForm } from '@inertiajs/vue3';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, defineComponent, h } from 'vue';
const require = createRequire(import.meta.url);
const passthrough = defineComponent({
    setup:
        (_, { attrs, slots }) =>
        () =>
            h('button', attrs, slots.default?.()),
});
function compile(file, scenario = null) {
    const descriptor = parse(readFileSync(file, 'utf8')).descriptor;
    const script = compileScript(descriptor, { id: 'four-section' });
    const template = compileTemplate({
        source: descriptor.template.content,
        filename: file,
        id: 'four-section',
        ssr: true,
        ssrCssVars: [],
        compilerOptions: { bindingMetadata: script.bindings },
    });
    assert.deepEqual(template.errors, []);
    const module = { exports: {} };
    const localRequire = (name) => {
        if (name === 'vue' || name === 'vue/server-renderer') {
            return require(name);
        }

        if (name === '@inertiajs/vue3') {
            return { useForm };
        }

        if (name === '@/lib/patient-registration') {
            return {
                phoneError: () => '',
                identityError: () => '',
                focusInvalidField: () => {},
            };
        }

        if (name === '@/lib/guardian-contact') {
            return { knownMinor: () => false };
        }

        if (name.endsWith('RegistrationReviewSummary.vue')) {
            return {
                default: compile(
                    'resources/js/components/registration/RegistrationReviewSummary.vue',
                ),
            };
        }

        return new Proxy({}, { get: () => passthrough });
    };

    for (const code of [script.content, template.code]) {
        runInNewContext(
            ts.transpileModule(code, {
                compilerOptions: {
                    module: ts.ModuleKind.CommonJS,
                    target: ts.ScriptTarget.ES2022,
                },
            }).outputText,
            {
                exports: module.exports,
                require: localRequire,
                crypto: webcrypto,
                AbortController,
                setTimeout,
                clearTimeout,
                document: {
                    querySelector: () => ({ content: 'synthetic-csrf' }),
                },
                fetch: async () => ({
                    ok: true,
                    json: async () => ({ doctor: null }),
                }),
            },
        );
    }

    const component = module.exports.default;
    component.ssrRender = module.exports.ssrRender;

    if (scenario) {
        const setup = component.setup;
        component.setup = (props, context) => {
            const state = setup(props, context);
            state.selectPatient({
                patientNumber: 'KP-SYNTHETIC',
                fullName: 'Synthetic patient',
            });
            state.form.visit_type = scenario.visitType ?? 'consultation';
            state.form.coverage_type = scenario.coverage ?? 'self_pay';
            state.form.panel_id = scenario.coverage === 'panel' ? 7 : '';

            if (scenario.uncertain) {
                state.submissionNotice.value = 'Unconfirmed original command';
            }

            return state;
        };
    }

    return component;
}
const render = (scenario = {}) =>
    renderToString(
        createSSRApp({
            render: () =>
                h(
                    compile(
                        'resources/js/components/registration/RegistrationCreateForm.vue',
                        scenario,
                    ),
                    {
                        options: {
                            idempotencyKey: 'synthetic-key',
                            branch: { id: 1, name: 'Synthetic branch' },
                            doctors: [],
                            panels: [{ id: 7, name: 'Synthetic panel' }],
                            autoAssign: { available: false },
                        },
                        recentPatients: [],
                        requirements: {},
                        guardianToday: '2026-11-26',
                        presentation: scenario.presentation ?? 'dialog',
                    },
                ),
        }),
    );

test('actual saved-patient form renders four simultaneous ordered headings with one submit owner', async () => {
    const html = await render();
    const headings = [...html.matchAll(/<h2\b[^>]*>([\s\S]*?)<\/h2>/g)].map(
        (match) => match[1].trim(),
    );
    assert.deepEqual(headings, [
        '1. Patient',
        '2. Visit',
        '3. Who pays',
        '4. Check and register',
    ]);
    assert.equal((html.match(/<form\b/g) ?? []).length, 1);
    assert.equal((html.match(/type="submit"/g) ?? []).length, 1);
    assert.ok(html.indexOf('Urgency') < html.indexOf('3. Who pays'));
    assert.equal((html.match(/id="visit-reasons-label"/g) ?? []).length, 1);
    assert.match(html, /role="group" aria-labelledby="visit-reasons-label"/);
    assert.doesNotMatch(
        html,
        />Next<|>Previous<|aria-hidden="true"[^>]*id="registration-/,
    );
});

test('Who pays owns the unchanged panel fields and OTC keeps optional doctor/reason wording in both presentations', async () => {
    for (const presentation of ['page', 'dialog']) {
        const html = await render({
            presentation,
            coverage: 'panel',
            visitType: 'otc',
        });
        const coverage = html.slice(
            html.indexOf('id="registration-coverage-heading"'),
            html.indexOf('id="registration-review-heading"'),
        );

        for (const id of [
            'panel-id',
            'coverage-member-reference',
            'coverage-panel-note',
        ]) {
            assert.ok(coverage.includes(`id="${id}"`));
        }

        assert.match(html, /Doctor[\s\S]*?\(optional\)/);
        assert.match(html, /Visit Reason[\s\S]*?\(optional\)/);
    }
});

test('uncertain dialog keeps all three editable sections locked while the unchanged retry action remains available', async () => {
    const html = await render({ uncertain: true, coverage: 'panel' });
    assert.equal(
        (html.match(/<fieldset disabled class="contents"/g) ?? []).length,
        3,
    );
    assert.match(html, /Retry original registration/);
    assert.doesNotMatch(
        html.match(/<button\b[^>]*type="submit"[^>]*>/)?.[0] ?? '',
        /disabled/,
    );
});
