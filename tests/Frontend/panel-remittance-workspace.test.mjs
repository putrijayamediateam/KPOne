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
    'resources/js/pages/PanelRemittances/Index.vue',
    'utf8',
);
const container = {
    setup:
        (_, { slots }) =>
        () =>
            h('div', slots.default?.()),
};
const props = (overrides = {}) => ({
    branch: { id: 3, name: 'Synthetic branch', today: '2026-11-27' },
    filters: { panel: 'SYN' },
    panels: [{ code: 'SYN', name: 'Synthetic Panel' }],
    maxSplits: 50,
    canReverse: false,
    openClaims: [
        {
            publicId: 'claim-a',
            claimNo: '1',
            invoiceNumber: 'INV-A',
            patientName: 'Synthetic Patient',
            visitDate: '27 Nov 2026',
            acceptedSen: 1000,
            receivedSen: 0,
            outstandingSen: 1000,
        },
        {
            publicId: 'claim-b',
            claimNo: '2',
            invoiceNumber: 'INV-B',
            patientName: 'Synthetic Other Patient',
            visitDate: '27 Nov 2026',
            acceptedSen: 1000,
            receivedSen: 333,
            outstandingSen: 667,
        },
    ],
    remittances: { data: [], total: 0, currentPage: 1, lastPage: 1 },
    ...overrides,
});
function load(inlineTemplate, errors = {}) {
    const calls = [];
    let key = 0;
    const code = ts.transpileModule(
        compileScript(parse(source).descriptor, {
            id: 'remittance-workspace',
            inlineTemplate,
        }).content,
        {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        },
    ).outputText;
    const module = { exports: {} };
    runInNewContext(code, {
        exports: module.exports,
        crypto: {
            randomUUID: () =>
                `00000000-0000-4000-8000-${String(++key).padStart(12, '0')}`,
        },
        document: { getElementById: () => ({ focus: () => {} }) },
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    Head: container,
                    Link: container,
                    usePage: () => ({ props: { errors } }),
                    router: {
                        get: (...args) => calls.push(['get', ...args]),
                        post: (...args) => calls.push(['post', ...args]),
                    },
                };
            }

            if (name === 'vue-sonner') {
                return { toast: { success: () => {} } };
            }

            if (name === '@/types/billing') {
                return { myr: (sen) => `RM ${(sen / 100).toFixed(2)}` };
            }

            if (name === '@/components/ui/button') {
                return {
                    Button: {
                        props: ['disabled'],
                        setup:
                            (p, { slots, attrs }) =>
                            () =>
                                h(
                                    'button',
                                    { ...attrs, disabled: p.disabled },
                                    slots.default?.(),
                                ),
                    },
                };
            }

            if (name === '@/components/ui/input') {
                return {
                    Input: {
                        props: ['modelValue', 'disabled'],
                        setup:
                            (p, { attrs }) =>
                            () =>
                                h('input', {
                                    ...attrs,
                                    value: p.modelValue,
                                    disabled: p.disabled,
                                }),
                    },
                };
            }

            if (name.startsWith('@/')) {
                return new Proxy(container, {
                    get: (object, field) =>
                        field in object ? object[field] : container,
                });
            }

            return require(name);
        },
    });

    return { page: module.exports.default, calls };
}
async function render(overrides = {}, errors = {}) {
    const { page } = load(true, errors);

    return renderToString(
        createSSRApp({ render: () => h(page, props(overrides)) }),
    );
}

test('actual template presents simultaneous semantic groups and disabled no-Panel recording', async () => {
    const html = await render({ filters: { panel: '' }, openClaims: [] });

    for (const title of [
        '1. Payment details',
        '2. Split across accepted claims',
        '3. Review and record',
    ]) {
        assert.ok(html.includes(title));
    }

    assert.match(html, /Choose a Panel to see its accepted claims/);
    assert.match(html, /<button[^>]*disabled[^>]*>\s*Record payment/);
    assert.match(html, /A recorded payment cannot be edited or deleted/);
    assert.doesNotMatch(html, /aria-current="step"|role="tab"/);
});

test('actual template retains truncation and errors outside optional help and reversed history', async () => {
    const html = await render(
        {
            openClaimsTruncated: true,
            canReverse: true,
            remittances: {
                data: [
                    {
                        publicId: 'synthetic-payment',
                        panelName: 'Synthetic Panel',
                        amountSen: 123,
                        receivedDate: '27 Nov 2026',
                        reference: 'SYN-REF',
                        recordedBy: 'Synthetic staff',
                        reversed: true,
                        splits: [
                            {
                                claimNo: '1',
                                invoiceNumber: 'INV-A',
                                patientName: 'Synthetic Patient',
                                amountSen: 123,
                            },
                        ],
                    },
                ],
                total: 1,
                currentPage: 1,
                lastPage: 1,
            },
        },
        { remittance: 'Synthetic refusal: nothing recorded' },
    );
    assert.ok(
        html.indexOf('Only the 200 oldest claims') < html.indexOf('<details'),
    );
    assert.ok(
        html.indexOf('Synthetic refusal: nothing recorded') <
            html.indexOf('<details'),
    );
    assert.match(html, /This list is not a total of everything the Panel owes/);
    assert.match(html, /text-muted-foreground line-through/);
    assert.match(html, /RM 1.23/);
    assert.match(html, /The person who recorded it cannot reverse it/);
    assert.match(html, /Amount of this payment for claim 2/);
});

test('unchanged actual script retains exact integer split, refused/unknown retry key and clears only on success', () => {
    const { page, calls } = load(false);
    const state = page.setup(props(), { expose: () => {} });
    state.form.amount = '12.34';
    state.form.reference = 'SYN-REF';
    state.fillOldestFirst();
    assert.equal(state.splitTotalSen.value, 1234);
    assert.equal(state.parts.value['claim-a'], '10.00');
    assert.equal(state.parts.value['claim-b'], '2.34');
    state.record();
    const first = calls.at(-1);
    first[3].onFinish();
    assert.equal(state.form.amount, '12.34');
    assert.equal(state.form.reference, 'SYN-REF');
    state.record();
    const retry = calls.at(-1);
    retry[3].onFinish();
    assert.equal(first[2].idempotency_key, retry[2].idempotency_key);
    assert.deepEqual(
        JSON.parse(JSON.stringify(first[2])),
        JSON.parse(JSON.stringify(retry[2])),
    );
    state.parts.value['claim-a'] = '10.01';
    assert.equal(state.canRecord.value, false);
    state.fillOldestFirst();
    state.record();
    const success = calls.at(-1);
    success[3].onSuccess();
    success[3].onFinish();
    assert.equal(state.form.amount, '');
    assert.equal(state.form.reference, '');
    assert.equal(Object.keys(state.parts.value).length, 0);
    assert.notEqual(state.idempotencyKey.value, first[2].idempotency_key);
});

test('statement replacement stays pending until confirmed and Cancel preserves typed split', () => {
    const { page } = load(false);
    const state = page.setup(props(), { expose: () => {} });
    state.form.amount = '2.00';
    state.parts.value = { 'claim-a': '2.00' };
    state.useStatement([{ claim: 'claim-b', sen: 200 }]);
    assert.equal(state.pendingStatementParts.value.length, 1);
    assert.equal(state.parts.value['claim-a'], '2.00');
    // Same operation bound to the existing dialog update:open false handler.
    state.pendingStatementParts.value = null;
    assert.equal(state.parts.value['claim-a'], '2.00');
    assert.equal(state.form.amount, '2.00');
    state.clearSplit();
    assert.equal(Object.keys(state.parts.value).length, 0);
});
