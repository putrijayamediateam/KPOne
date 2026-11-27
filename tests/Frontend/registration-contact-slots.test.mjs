import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createRenderer, createSSRApp, h, nextTick, reactive } from 'vue';
const require = createRequire(import.meta.url);
export const path =
    'resources/js/components/patient/RegistrationContactFields.vue';
export function compile(source = readFileSync(path, 'utf8')) {
    const module = { exports: {} };
    runInNewContext(
        ts.transpileModule(
            compileScript(parse(source).descriptor, {
                id: 'contact-slots',
                inlineTemplate: true,
            }).content,
            {
                compilerOptions: {
                    module: ts.ModuleKind.CommonJS,
                    target: ts.ScriptTarget.ES2022,
                },
            },
        ).outputText,
        {
            exports: module.exports,
            require: (name) =>
                name === '@/components/InputError.vue'
                    ? {
                          default: compile(
                              readFileSync(
                                  'resources/js/components/InputError.vue',
                                  'utf8',
                              ),
                          ),
                      }
                    : require(name),
        },
    );

    return module.exports.default;
}
const keys = [
    'email',
    'address_line_1',
    'address_line_2',
    'city',
    'state',
    'postcode',
    'country_code',
];
export const values = () => ({
    email: 'synthetic@example.test',
    address_line_1: 'Synthetic street',
    address_line_2: '',
    city: 'Synthetic city',
    state: 'Synthetic state',
    postcode: '50000',
    country_code: 'MY',
});
export const render = (component, props = {}, slots = {}) =>
    renderToString(
        createSSRApp({
            render: () =>
                h(
                    component,
                    {
                        model: values(),
                        idPrefix: 'fixture',
                        errors: {},
                        ...props,
                    },
                    slots,
                ),
        }),
    );

test('without slots staff/public fields retain order values labels required flags and linked errors', async () => {
    for (const publicForm of [false, true]) {
        const html = await render(compile(), {
            publicForm,
            requireEmail: true,
            requireAddress: true,
            errors: { email: '<Synthetic refusal>' },
        });
        assert.doesNotMatch(html, /<h[1-6]|Contact<|Address<|type="submit"/);
        let previous = -1;

        for (const key of keys) {
            const input = html.match(
                new RegExp(`<input[^>]*id="fixture-${key}"[^>]*>`),
            )?.[0];
            assert.ok(input);
            assert.equal(
                / required(?:[ >])/.test(input),
                key !== 'address_line_2',
            );
            assert.ok(html.indexOf(`id="fixture-${key}"`) > previous);
            previous = html.indexOf(`id="fixture-${key}"`);
        }

        assert.match(html, /synthetic@example.test/);
        assert.match(html, /aria-describedby="fixture-email-error"/);
        assert.match(html, /&lt;Synthetic refusal&gt;/);
        assert.ok(
            html.includes(publicForm ? 'Alamat baris 1' : 'Address line 1'),
        );
        assert.ok(html.includes(publicForm ? 'E-mel' : 'Email'));
    }
});

test('opt-in slots appear once before their unchanged fields without adding duplicate inputs', async () => {
    const html = await render(
        compile(),
        {},
        {
            'before-email': () => h('h3', 'Contact'),
            'before-address': () => h('h3', 'Address'),
        },
    );
    assert.equal((html.match(/<h3>Contact<\/h3>/g) ?? []).length, 1);
    assert.equal((html.match(/<h3>Address<\/h3>/g) ?? []).length, 1);
    assert.ok(html.indexOf('<h3>Contact') < html.indexOf('id="fixture-email"'));
    assert.ok(html.indexOf('id="fixture-email"') < html.indexOf('<h3>Address'));
    assert.ok(
        html.indexOf('<h3>Address') <
            html.indexOf('id="fixture-address_line_1"'),
    );
    assert.equal((html.match(/<input\b/g) ?? []).length, 7);
});

test('actual compiled native input handlers still update the same parent model with or without slots', async () => {
    const previousDocument = globalThis.document;
    globalThis.document = { activeElement: null };

    try {
        for (const slotted of [false, true]) {
            const elements = [];
            const renderer = createRenderer({
                createElement: (tag) => {
                    const el = {
                        tag,
                        tagName: tag.toUpperCase(),
                        style: {},
                        children: [],
                        listeners: {},
                        addEventListener(name, callback) {
                            this.listeners[name] = callback;
                        },
                        removeEventListener() {},
                        getAttribute(name) {
                            return this[name];
                        },
                    };
                    elements.push(el);

                    return el;
                },
                createText: (text) => ({ text }),
                createComment: (text) => ({ text }),
                insert: (el, parent) => {
                    el.parent = parent;
                    parent.children ??= [];
                    parent.children.push(el);
                },
                remove: () => {},
                setText: (el, text) => {
                    el.text = text;
                },
                setElementText: (el, text) => {
                    el.text = text;
                },
                parentNode: (el) => el.parent,
                nextSibling: () => null,
                patchProp: (el, key, previous, next) => {
                    el[key] = next;
                },
            });
            const model = reactive(values());
            const app = renderer.createApp({
                render: () =>
                    h(
                        compile(),
                        { model, idPrefix: 'edit', errors: {} },
                        slotted
                            ? { 'before-email': () => h('h3', 'Contact') }
                            : {},
                    ),
            });
            app.mount({ children: [] });

            for (const key of ['email', 'address_line_1']) {
                const el = elements.find((el) => el.id === `edit-${key}`);
                assert.ok(el?.listeners.input);
                el.value =
                    key === 'email'
                        ? 'updated@example.test'
                        : 'Updated synthetic street';
                el.listeners.input({ target: el });
                await nextTick();
                assert.equal(model[key], el.value);
            }

            app.unmount();
        }
    } finally {
        globalThis.document = previousDocument;
    }
});
