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

        if (name === '@/components/InputError.vue') {
            return {
                default: compile('resources/js/components/InputError.vue'),
            };
        }

        if (name === '@/lib/patient-registration') {
            return {
                phoneError: () => '',
                identityError: () => '',
                focusInvalidField: () => {},
            };
        }

        if (name === '@/lib/guardian-contact') {
            return {
                knownMinor: (dob) => dob === '2015-01-01',
                blankGuardian: () => ({
                    full_name: '',
                    relationship: '',
                    phone: '',
                    phone_country: 'MY',
                    email: '',
                    lock_version: 0,
                    retry_key: 'synthetic-guardian',
                }),
            };
        }

        if (
            name.endsWith('RegistrationContactFields.vue') ||
            name.endsWith('GuardianFields.vue')
        ) {
            return {
                default: compile(
                    'resources/js/components/patient/' + name.split('/').at(-1),
                ),
            };
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
                    querySelector: () => ({
                        content: 'synthetic-csrf',
                        focus: () => {},
                    }),
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
            state.startQuickCreate();
            state.quick.value.full_name = 'Synthetic quick patient';
            state.quick.value.date_of_birth = scenario.minor
                ? '2015-01-01'
                : '';
            state.quickIdentifierType.value = scenario.passport
                ? 'passport'
                : 'nric';
            Object.assign(state.form.errors, scenario.errors ?? {});

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
                        requirements: scenario.requirements ?? {},
                        guardianToday: '2026-11-26',
                        presentation: scenario.presentation ?? 'dialog',
                    },
                ),
        }),
    );

test('quick patient renders ordered labelled local groups, unchanged conditional Guardian and one owner', async () => {
    const html = await render({ minor: true });
    assert.match(
        html,
        /^<!--\[--><section class="shrink-0 overflow-hidden rounded-lg border bg-card shadow-card">/,
    );
    const headings = [...html.matchAll(/<h3\b[^>]*>([\s\S]*?)<\/h3>/g)].map(
        (m) => m[1].trim(),
    );
    assert.deepEqual(headings, [
        'Identity',
        'Contact and address',
        'Identifiers',
    ]);

    for (const name of ['identity', 'contact', 'identifiers']) {
        assert.equal(
            (html.match(new RegExp(`id="quick-${name}-heading"`, 'g')) ?? [])
                .length,
            1,
        );
        assert.match(
            html,
            new RegExp(`aria-labelledby="quick-${name}-heading"`),
        );
    }

    assert.ok(
        html.indexOf('quick-identity-heading') <
            html.indexOf('data-guardian-fields'),
    );
    assert.ok(
        html.indexOf('data-guardian-fields') <
            html.indexOf('quick-contact-heading'),
    );
    assert.ok(
        html.indexOf('quick-contact-heading') <
            html.indexOf('quick-identifiers-heading'),
    );
    assert.equal((html.match(/<form\b/g) ?? []).length, 1);
    assert.equal((html.match(/type="submit"/g) ?? []).length, 1);
    assert.doesNotMatch(await render(), /data-guardian-fields/);
});

test('whole contact fields retain IDs required semantics and escaped refusal while identifiers keep conditional issuer', async () => {
    const html = await render({
        passport: true,
        requirements: { requireEmail: true, requireAddress: true },
        errors: {
            'quick_patient.email': '<refused email>',
            'quick_patient.duplicate_override': 'Review duplicate first',
        },
    });

    for (const name of [
        'email',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postcode',
        'country_code',
    ]) {
        assert.equal(
            (html.match(new RegExp(`id="quick-contact-${name}"`, 'g')) ?? [])
                .length,
            1,
        );
        const input = html.match(
            new RegExp(`<input[^>]*id="quick-contact-${name}"[^>]*>`),
        )?.[0];
        assert.ok(input);
        assert.equal(
            / required(?:[ >])/.test(input),
            name !== 'address_line_2',
        );
    }

    assert.match(
        html,
        /aria-labelledby="quick-contact-heading" class="[^"]*max-lg:\[&amp;_input\]:min-h-11[^"]*max-lg:\[&amp;_select\]:min-h-11/,
    );
    assert.match(html, /Passport issuer/);
    assert.doesNotMatch(await render(), /Passport issuer/);
    assert.match(html, /&lt;refused email&gt;/);
    assert.ok(
        html.indexOf('Review duplicate first') >
            html.indexOf('quick-identifiers-heading'),
    );
    assert.match(html, /Confirm only after review/);
});

test('all regrouped controls remain under the original disabled fieldset on uncertain dialog and retry remains enabled', async () => {
    const html = await render({ uncertain: true, minor: true, passport: true });
    const first = html.slice(
        html.indexOf('<fieldset disabled class="contents"'),
        html.indexOf('</fieldset>'),
    );

    for (const value of [
        'quick-identity-heading',
        'data-guardian-fields',
        'quick-contact-heading',
        'quick-identifiers-heading',
        'quick-patient-name',
    ]) {
        assert.ok(first.includes(value));
    }

    assert.match(html, /Retry original registration/);
    assert.doesNotMatch(
        html.match(/<button\b[^>]*type="submit"[^>]*>/)?.[0] ?? '',
        /disabled/,
    );
});
