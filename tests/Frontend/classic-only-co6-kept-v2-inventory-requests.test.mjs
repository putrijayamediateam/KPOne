// Classic only, CO-6: the Classic half of v2-inventory-requests.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classicPanel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);
const classicReceipt = read(
    'resources/js/components/inventory/TransferReceiptForm.vue',
);
const classicDifferences = read(
    'resources/js/components/inventory/TransferDifferences.vue',
);
const block = (source, name) => {
    const start = source.search(
        new RegExp(`\\n[ \\t]*(?:const|type) ${name}\\b`),
    );
    assert.ok(start >= 0, `${name} not found`);
    const rest = source.slice(start + 1);
    const indent = /^[ \t]*/.exec(rest)[0];
    const next = rest
        .slice(indent.length)
        .search(
            new RegExp(
                `\\n${indent}(?:const |type |watch|watchEffect|function |//|/\\*\\*|return \\{|\\}\\n|<\\/script>)`,
            ),
        );

    return (
        (next < 0 ? rest : rest.slice(0, next + indent.length))
            .replace(/\s+/g, '')
            // Prettier adds a trailing comma when the same code sits one level deeper.
            .replace(/,(?=[)\]}])/g, '')
    );
};

test('2b: Classic Inventory operations keep their requests section', () => {
    for (const source of [classicPanel, classicReceipt, classicDifferences]) {
        assert.ok(!source.includes('inventory-v2'));
        assert.ok(!source.includes('components/v2/'));
    }

    assert.match(classicPanel, /hideRequests\?: boolean;/);
    assert.match(classicPanel, /v-if="!hideRequests"/);
    assert.ok(!/withDefaults[\s\S]*hideRequests/.test(classicPanel));
    assert.ok(
        !read('resources/js/pages/Inventory/Index.vue').includes(
            'hide-requests',
        ),
    );
});

test("2b round 2: Daniel's changes (units, live sum, item names, batch quantities, SKU hint)", () => {
    const words = read('resources/js/lib/inventory-words.ts');
    assert.match(words, /trimQuantity\(String\(value \?\? '0'\)\)/);
    assert.match(
        read('resources/js/lib/inventory-picker.ts'),
        /params\.set\('location', input\.location\);/,
    );
});

test('2b: Confirm receipt is switched off while a line does not add up, and says why', () => {
    const dialog = read('resources/js/components/v2/V2Dialog.vue');
    assert.match(
        block(dialog, 'submit'),
        /if\(props\.confirmDisabled\)\{return;\}/,
    );
});
