import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h, reactive } from 'vue';

const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
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
const ui = new Proxy(
    { __esModule: true, default: stub },
    { get: (value, key) => value[key] ?? stub },
);
const loadLibrary = (path) => {
    const module = { exports: {} };
    runInNewContext(transpile(read(path)), {
        exports: module.exports,
        require,
    });

    return module.exports;
};
const render = (path, props, password = false) => {
    const module = { exports: {} };
    const code = compileScript(parse(read(path)).descriptor, {
        id: path,
        inlineTemplate: true,
    }).content;
    runInNewContext(transpile(code), {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    Head: { render: () => null },
                    Link: stub,
                    router: {},
                    usePage: () => ({
                        props: { branchContext: { active: { id: 7 } } },
                    }),
                    useForm: (values) =>
                        reactive({
                            ...values,
                            ...(password
                                ? { credential_strategy: 'password' }
                                : {}),
                            ...(values.assignments
                                ? {
                                      assignments: [
                                          values.assignments[0],
                                          {
                                              ...values.assignments[0],
                                              is_primary: false,
                                          },
                                      ],
                                  }
                                : {}),
                            errors: {},
                            processing: false,
                            clearErrors() {},
                            reset() {},
                            post() {},
                        }),
                };
            }

            if (name === '@lucide/vue' || name.startsWith('@/components/')) {
                return ui;
            }

            if (name.startsWith('@/lib/')) {
                return loadLibrary(`resources/js/lib/${name.slice(6)}.ts`);
            }

            return require(name);
        },
    });

    return renderToString(
        createSSRApp({ render: () => h(module.exports.default, props) }),
    );
};

test('D001 every Staff Create label owns a control, including passwords and repeated assignments', async () => {
    for (const password of [false, true]) {
        const html = await render(
            'resources/js/pages/Staff/Create.vue',
            {
                today: '2026-10-30',
                options: {
                    roles: ['ca'],
                    departments: [{ id: 1, name: 'Synthetic department' }],
                    branches: [{ id: 7, name: 'Synthetic branch' }],
                },
            },
            password,
        );
        const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map((m) => m[1]);
        assert.equal(
            new Set(ids).size,
            ids.length,
            'control IDs must be unique across assignments',
        );

        for (const [, attributes, contents] of html.matchAll(
            /<label\b([^>]*)>([\s\S]*?)<\/label>/g,
        )) {
            const target = attributes.match(/\bfor="([^"]+)"/)?.[1];
            assert.ok(
                target
                    ? ids.includes(target)
                    : /<(?:input|select|textarea)\b/.test(contents),
                `Unassociated label: ${contents.replace(/<[^>]*>/g, '')}`,
            );
        }

        assert.match(
            html,
            /role="radiogroup"[^>]*aria-labelledby="staff-sign-in-method"/,
        );
        assert.match(html, /id="staff-assignment-1-branch"/);
        assert.match(html, /for="staff-name"/);

        if (password) {
            assert.match(html, /for="staff-password-confirmation"/);
        }
    }
});

test('D003 search-first instruction is visible beside the unchanged duplicate-guarded Quick create conditions', () => {
    const source = read('resources/js/pages/Registration/Create.vue').replace(
        /\s+/g,
        ' ',
    );
    assert.match(
        source,
        /<p[^>]*id="patient-search-help"[^>]*> Search for the patient first\. Quick create appears when no match is found\. <\/p>/,
    );
    assert.match(source, /aria-describedby="patient-search-help"/);
    assert.match(
        source,
        /v-if=" !searching && patientResults.length === 0 && patientQuery.trim\(\).length > 0 "/,
    );
    assert.match(source, /v-else-if="showingRecent && !patientResults.length"/);
});

test('D010 held batch reason renders as English while the original payload remains unchanged', async () => {
    const props = {
        holds: {
            branchId: 7,
            branchName: 'Synthetic branch',
            q: '',
            page: 1,
            lastPage: 1,
            total: 1,
            rows: [
                {
                    public_id: 'synthetic-batch',
                    batch_number: 'SYNTHETIC',
                    expiry_date: '2027-10-30',
                    sku_code: 'SYNTHETIC-SKU',
                    status: 'available',
                    reason: 'suspected_quality_issue',
                    lock_version: 1,
                    canPlace: false,
                    canRelease: false,
                },
            ],
        },
    };
    const before = structuredClone(props);
    const html = await render(
        'resources/js/pages/Inventory/BatchHolds.vue',
        props,
    );
    assert.match(html, /Held: Suspected quality issue/);
    assert.doesNotMatch(
        html.replace(/<[^>]*>/g, ''),
        /suspected_quality_issue/,
    );
    assert.match(html, /value="suspected_quality_issue"/);
    assert.deepEqual(props, before);
});

test('D010 all hold, receiving and correction reason codes have readable labels and safe fallback', () => {
    const { formatInventoryReason } = loadLibrary(
        'resources/js/lib/inventory-picker.ts',
    );
    const expected = {
        suspected_quality_issue: 'Suspected quality issue',
        recall: 'Recall',
        damaged: 'Damaged',
        other_safety: 'Other safety concern',
        cleared_by_supplier: 'Cleared by supplier',
        inspection_passed: 'Inspection passed',
        other_approved: 'Other approved reason',
        wrong_item: 'Wrong item',
        short_expiry: 'Short expiry',
        documentation_missing: 'Missing documentation',
        overage: 'Over delivery',
        short: 'Short delivery',
        expiry_problem: 'Expiry problem',
        documentation: 'Documentation issue',
        correction: 'Receipt correction',
        duplicate_delivery: 'Duplicate delivery',
        incorrect_quantity: 'Incorrect quantity',
        incorrect_batch: 'Incorrect batch',
    };

    for (const [code, label] of Object.entries(expected)) {
        assert.equal(formatInventoryReason(code), label);
    }

    assert.equal(formatInventoryReason(null), '');
    assert.equal(formatInventoryReason(undefined), '');
    assert.equal(formatInventoryReason('future_reason'), 'Future reason');

    for (const path of [
        'resources/js/pages/Inventory/Index.vue',
        'resources/js/components/inventory/InventoryReferenceDataPanel.vue',
        'resources/js/pages/Dispensary/Show.vue',
    ]) {
        assert.match(read(path), /formatInventoryReason\(/);
    }

    const { inventoryPickerOptions } = loadLibrary(
        'resources/js/lib/inventory-picker.ts',
    );
    const options = inventoryPickerOptions(
        [
            {
                publicId: 'synthetic-held',
                held: true,
                holdReason: 'suspected_quality_issue',
                batchNumber: 'SYNTHETIC',
                expiryDate: '2028-12-31',
            },
        ],
        'batches',
    );
    assert.equal(
        options[0].label,
        'Held: Suspected quality issue · SYNTHETIC · 2028-12-31',
    );
    assert.equal(options[0].value, 'synthetic-held');
    assert.equal(options[0].disabled, true);

    const reviews = read(
        'resources/js/components/inventory/ReceivingReviews.vue',
    );
    assert.match(
        reviews,
        /row\.reason_codes\.map\(formatInventoryReason\)\.join\(', '\)/,
    );
    assert.match(reviews, /formatInventoryReason\(lot\.reason\)/);
    assert.match(reviews, /formatInventoryReason\(row\.payload\.reason\)/);
    assert.match(
        reviews,
        /Receiver: staff #\{\{\s*receipt\.received_by_user_id/,
    );
    assert.match(reviews, /Receiver: staff #\{\{ row\.receiver_user_id/);

    for (const source of [
        reviews,
        read('resources/js/components/inventory/VerifiedReceivingForm.vue'),
        read('resources/js/pages/Inventory/BatchHolds.vue'),
    ]) {
        assert.match(source, /formatInventoryReason\(reason\)/);
        assert.match(source, /:value="reason"/);
    }
});

test('D011 both governed modes keep a responsive minimum search width and unchanged submit behavior', () => {
    const source = read('resources/js/pages/Inventory/GovernedStock.vue');
    const label = source.match(
        /<label class="([^"]+)"\s*>Search SKU, batch or location/,
    )[1];
    assert.match(label, /min-w-\[min\(100%,18rem\)\]/);
    assert.match(label, /basis-72/);
    assert.match(
        source,
        /props.stock.mode === 'count' \? '\/inventory\/counts' : '\/inventory\/writeoffs'/,
    );
    assert.match(source, /@submit.prevent="search\(\)"/);
});
