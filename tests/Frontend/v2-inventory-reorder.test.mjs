import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classicPanel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);
const composable = read(
    'resources/js/composables/inventory-v2/useReorderLevel.ts',
);
const section = read('resources/js/components/inventory-v2/ReorderSection.vue');
const older = read(
    'resources/js/components/inventory-v2/OlderRecordsSection.vue',
);
const home = read('resources/js/pages/Inventory/V2/Index.vue');

/** The source of `const name = …` up to the next top-level `const`, without whitespace (indentation differs). */
const block = (source, name) => {
    const start = source.search(
        new RegExp(`\\n[ \\t]*(?:const|type|let) ${name}\\b`),
    );
    assert.ok(start >= 0, `${name} not found`);
    const rest = source.slice(start + 1);
    const indent = /^[ \t]*/.exec(rest)[0];
    const next = rest
        .slice(indent.length)
        .search(
            new RegExp(
                `\\n${indent}(?:const |type |let |watch|watchEffect|function |//|/\\*\\*|return \\{|\\}\\n|<\\/script>)`,
            ),
        );

    return (
        (next < 0 ? rest : rest.slice(0, next + indent.length))
            .replace(/\s+/g, '')
            // Prettier adds a trailing comma when the same code sits one level deeper.
            .replace(/,(?=[)\]}])/g, '')
    );
};

test('2c: the reorder composable is a verbatim copy of the Classic panel logic it replaces', () => {
    for (const name of [
        'send',
        'reorder',
        'CurrentReorderLevel',
        'reorderLookup',
        'loadReorderLevel',
        'reorderErrors',
        'saveReorder',
    ]) {
        assert.equal(block(composable, name), block(classicPanel, name), name);
    }

    assert.match(
        composable,
        /watch\(\(\) => \[reorder\.location, reorder\.sku\], loadReorderLevel\);/,
    );
});

test('2c: the V2 save is the Classic save, except that success closes the sheet', () => {
    const v2 = block(section, 'saveReorder')
        // V2 only: nothing is sent until the saved level has loaded.
        .replace(
            /^constsaveReorder=\(\)=>\{if\(!ready\.value\)\{return;\}/,
            'constsaveReorder=()=>',
        )
        .replace(/\);\};$/, ');')
        .replace('closeSheet();', 'voidloadReorderLevel();');
    assert.equal(v2, block(classicPanel, 'saveReorder'));
    // Said in words: what is saved for the chosen pair, and the Classic maximum help.
    assert.match(section, /Loading the saved level…/);
    assert.match(section, /No level saved yet for this item here\./);
    assert.match(
        section,
        /With a maximum, reorder suggestions fill up to it\. Leave\s+blank to remove any maximum\./,
    );
    assert.match(section, /:dirty="dirty"/);
    assert.match(section, /v-if="operations\.permissions\.manageReorder"/);
});

test('2c: the older records are read only and point to the governed pages', () => {
    assert.ok(!/router\.|send\(|useForm|<form/.test(older));
    assert.match(older, /href="\/inventory\/counts"/);
    assert.match(older, /href="\/inventory\/writeoffs"/);
    assert.match(older, /row\.direction === 'out'/);
});

test('2c: the V2 home shows the reorder and older-records sections', () => {
    assert.match(
        home,
        /<V2DisclosureBar\s+title="Reorder levels and alerts"\s+keep-mounted\s*>\s*<ReorderSection/,
    );
    assert.match(
        home,
        /<V2DisclosureBar title="Older records \(read only\)">\s*<OlderRecordsSection/,
    );
    assert.ok(!home.includes('<InventoryOperationsPanel'));
});

test("2c: Daniel's changes (units, item names, days left, whole numbers in the boxes)", () => {
    // Every quantity carries its unit; the chip and the line say what it means.
    assert.match(
        section,
        /units\(row\.availableQuantity, row\.stockUnit\)\} left`/,
    );
    assert.match(section, /Below reorder level of/);
    assert.match(section, /units\(row\.quantity, row\.stockUnit\)/);
    // Expiring rows name the item, with the SKU code and batch under it, and how long is left.
    assert.match(section, /row\.item \?\? row\.sku/);
    assert.match(section, /daysLeft\(row\.expiryDate\)/);
    assert.match(section, /`in \$\{days\} days`/);
    assert.match(section, /timeZone: props\.timezone/);
    assert.match(home, /:timezone="inventory\.branchTimezone"/);
    // "100", not "100.000", once the copied loader has filled the boxes; the loader itself is unchanged.
    assert.match(
        section,
        /reorder\.level = reorder\.level === '' \? '' : n\(reorder\.level\);/,
    );
    assert.equal(
        block(composable, 'loadReorderLevel'),
        block(classicPanel, 'loadReorderLevel'),
    );
});
