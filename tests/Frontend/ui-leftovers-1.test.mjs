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
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const transpile = (code) =>
    ts.transpileModule(code, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const stub = {
    render() {
        return h('div', this.$slots.default?.());
    },
};

const load = (path, resolve = require) => {
    const module = { exports: {} };
    runInNewContext(transpile(read(path)), {
        exports: module.exports,
        require: resolve,
    });

    return module.exports;
};

const renderBillingLimits = (panelLimitSen) => {
    const money = load('resources/js/types/billing.ts');
    const component = { exports: {} };
    const code = compileScript(
        parse(read('resources/js/pages/BillingApprovalLimits/Index.vue'))
            .descriptor,
        { id: 'ui-leftovers-billing-limits', inlineTemplate: true },
    ).content;
    runInNewContext(transpile(code), {
        exports: component.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return { router: { post() {} } };
            }

            if (name === '@/types/billing') {
                return money;
            }

            if (name.startsWith('@/components/')) {
                return {
                    default: stub,
                    Button: stub,
                    Input: stub,
                    PageHeader: stub,
                };
            }

            return require(name);
        },
    });

    return renderToString(
        createSSRApp({
            render: () =>
                h(component.exports.default, {
                    branch: { id: 7, name: 'Synthetic branch' },
                    adjustmentThreshold: {
                        configured: false,
                        limitSen: null,
                        lockVersion: 0,
                    },
                    approvers: [
                        {
                            id: 17,
                            name: 'Synthetic approver',
                            email: 'approver@leftovers.test',
                            canPanelApprove: true,
                            canDefermentApprove: false,
                            panelLimitSen,
                            defermentLimitSen: null,
                        },
                    ],
                }),
        }),
    );
};

test('empty or invalid billing limits hide the helper and its description link', async () => {
    for (const value of [null, '', '1.5', '1000000000000']) {
        const html = await renderBillingLimits(value);

        assert.doesNotMatch(html, /<p id="limit-echo-17-panel"/);
        assert.doesNotMatch(html, /aria-describedby="limit-echo-17-panel"/);
        assert.doesNotMatch(html, /= RM/);
    }
});

test('held inventory with no reason reads Held without changing known reason labels', () => {
    const { formatInventoryHoldLabel, inventoryPickerOptions } = load(
        'resources/js/lib/inventory-picker.ts',
    );
    const index = read('resources/js/pages/Inventory/Index.vue');

    assert.equal(formatInventoryHoldLabel(''), 'Held');
    assert.equal(
        formatInventoryHoldLabel('Suspected quality issue'),
        'Held: Suspected quality issue',
    );

    for (const holdReason of [null, undefined]) {
        const [option] = inventoryPickerOptions(
            [
                {
                    publicId: 'synthetic-held',
                    held: true,
                    holdReason,
                    skuCode: 'SYNTHETIC-SKU',
                },
            ],
            'skus',
        );
        assert.equal(option.label, 'Held · SYNTHETIC-SKU');
    }

    assert.equal(
        (index.match(/formatInventoryReason\(row\.holdReason\)/g) ?? []).length,
        2,
    );
    assert.doesNotMatch(index, /String\(row\.holdReason\)/);
    assert.match(index, /availabilityStatus[\s\S]*?===\s*'held'/i);
    assert.match(index, /batchStatus[\s\S]*?===\s*'held'/i);
});

test('invoice and receipt print headings keep visual classes and use ordered levels', () => {
    const source = read('resources/js/pages/Billing/Print.vue');

    assert.match(
        source,
        /<h2 class="my-6 text-center text-lg font-bold underline">[\s\S]*?OFFICIAL (?:RECEIPT|INVOICE)[\s\S]*?<\/h2>/,
    );
    assert.match(source, /<h3 class="mb-1 font-semibold">To<\/h3>/);
    assert.match(source, /<h3 class="mb-2 font-semibold">Bill to<\/h3>/);
    assert.doesNotMatch(source, /<h2 class="mb-2 font-semibold">Bill to<\/h2>/);
});

test('the other labels listed in UI-POLISH-1 target their controls', () => {
    const cases = [
        [
            'resources/js/pages/Staff/Edit.vue',
            [
                ['Full name', 'staff-edit-name'],
                ['Work email', 'staff-edit-email'],
                ['Staff number', 'staff-edit-number'],
                ['Job title', 'staff-edit-job-title'],
                ['Department', 'staff-edit-department'],
            ],
        ],
        [
            'resources/js/pages/Staff/Partials/BranchAssignmentDialog.vue',
            [
                ['Assignment type', 'branch-assignment-type'],
                ['Valid from', 'branch-assignment-valid-from'],
                [
                    'Valid through (inclusive)',
                    'branch-assignment-valid-through',
                ],
            ],
        ],
    ];

    for (const [path, labels] of cases) {
        const source = read(path);

        for (const [label, id] of labels) {
            assert.match(
                source,
                new RegExp(
                    `<label\\b[^>]*\\bfor="${id}"[^>]*>\\s*${escapeRegExp(label)}\\s*</label\\s*>`,
                    's',
                ),
                `${path}: ${label} must label ${id}`,
            );
            assert.match(
                source,
                new RegExp(`<(?:input|select|DateField)\\b[^>]*\\bid="${id}"`),
                `${path}: ${id} must identify its control`,
            );
        }
    }
});
