import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
const source = readFileSync(
    new URL('../../resources/js/lib/inventory-picker.ts', import.meta.url),
    'utf8',
);
const exports = {};
vm.runInNewContext(
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports, URLSearchParams, Map, String },
);
const plain = (value) => JSON.parse(JSON.stringify(value));

test('inventory lookup URLs stay local and carry the applied branch, fixed page bound and encoded search', () => {
    const path = exports.inventoryPickerUrl({
        kind: 'skus',
        context: 'operations',
        expectedBranchId: 7,
        query: '雪 &q=foreign//host.test',
        sku: 'synthetic-uuid',
    });
    const url = new URL(path, 'https://synthetic.test');
    assert.equal(url.origin, 'https://synthetic.test');
    assert.equal(url.pathname, '/inventory/picker');
    assert.equal(url.searchParams.get('q'), '雪 &q=foreign//host.test');
    assert.equal(url.searchParams.get('expected_branch_id'), '7');
    assert.equal(url.searchParams.get('limit'), '25');
    assert.equal(url.searchParams.get('sku'), 'synthetic-uuid');
    assert.equal([...url.searchParams.keys()].length, 6);
});
test('search choices preserve readable labels and inactive suppliers cannot be newly selected', () => {
    assert.deepEqual(
        plain(
            exports.inventoryPickerOptions(
                [{ publicId: 'sku', skuCode: 'SKU-雪' }],
                'skus',
            ),
        ),
        [{ value: 'sku', label: 'SKU-雪', disabled: false }],
    );
    assert.deepEqual(
        plain(
            exports.inventoryPickerOptions(
                [
                    {
                        publicId: 'supplier',
                        code: 'SUP',
                        name: 'Synthetic supplier',
                        isActive: false,
                    },
                ],
                'suppliers',
            ),
        ),
        [
            {
                value: 'supplier',
                label: 'SUP · Synthetic supplier',
                disabled: true,
            },
        ],
    );
    assert.equal(
        exports.inventoryPickerOptions(
            Array.from({ length: 5000 }, (_, i) => ({
                id: i,
                name: `Synthetic ${i}`,
            })),
            'branches',
        ).length,
        100,
    );
});
test('a chosen value outside the current result page stays visible with no duplicate keys', () => {
    const selected = {
        value: 'old',
        label: 'Previously selected synthetic SKU',
    };
    const empty = { value: '', label: 'Select' };
    const merged = plain(
        exports.mergeInventoryPickerOptions(
            [
                { value: 'next', label: 'Next' },
                { value: 'old', label: 'Current label' },
            ],
            empty,
            selected,
        ),
    );
    assert.deepEqual(merged, [
        empty,
        { value: 'old', label: 'Current label' },
        { value: 'next', label: 'Next' },
    ]);
});
test('picker components invalidate stale requests on branch/SKU changes and existing batches remain SKU-scoped', () => {
    const component = readFileSync(
        new URL(
            '../../resources/js/components/inventory/InventorySearchSelect.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(component, /request !== sequence/);
    assert.match(
        component,
        /watch\([\s\S]*?props.expectedBranchId[\s\S]*?sequence\+\+[\s\S]*?matches.value = null/,
    );
    assert.match(component, /props.sku[\s\S]*?selected.value = undefined/);

    for (const file of [
        'InventoryReferenceDataPanel.vue',
        'InventoryOperationsPanel.vue',
    ]) {
        assert.match(
            readFileSync(
                new URL(
                    `../../resources/js/components/inventory/${file}`,
                    import.meta.url,
                ),
                'utf8',
            ),
            /<InventorySearchSelect/,
        );
    }
});

test('picker search stays above a downward list so mobile results cannot cover the Search button', () => {
    const component = readFileSync(
        new URL(
            '../../resources/js/components/inventory/InventorySearchSelect.vue',
            import.meta.url,
        ),
        'utf8',
    );
    const template = component.slice(component.indexOf('<template>'));
    assert.ok(
        template.indexOf('<Button') < template.indexOf('<OperationalSelect'),
    );
    assert.ok(
        template.indexOf('Up to 25 matches. Search to find more.') <
            template.indexOf('<OperationalSelect'),
    );
    assert.match(template, /:content-avoid-collisions="false"/);
    const select = readFileSync(
        new URL(
            '../../resources/js/components/ui/select/OperationalSelect.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(select, /contentAvoidCollisions: true/);
    assert.match(
        select,
        /<SelectContent[\s\S]*?:avoid-collisions="contentAvoidCollisions"/,
    );
});
