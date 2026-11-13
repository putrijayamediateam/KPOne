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
const composable = read(
    'resources/js/composables/inventory-v2/useRequestsAndTransfers.ts',
);
const section = read(
    'resources/js/components/inventory-v2/RequestsSection.vue',
);
const receive = read(
    'resources/js/components/inventory-v2/TransferReceiveSheet.vue',
);
const differences = read(
    'resources/js/components/inventory-v2/TransferDifferencesSheet.vue',
);
const picker = read(
    'resources/js/components/inventory-v2/InventoryPickerField.vue',
);

/** The source of `const name = …` up to the next top-level `const`, without whitespace (indentation differs). */
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

test('2b: the requests composable is a verbatim copy of the Classic panel logic it replaces', () => {
    for (const name of [
        'PendingConfirmation',
        'requestConfirmation',
        'confirmPendingAction',
        'blankLine',
        'stockRequest',
        'dispatchBatch',
        'canRequest',
        'send',
        'version',
        'createStockRequest',
        'addStockRequestLine',
        'removeStockRequestLine',
        'decideStockRequest',
        'dispatchStockRequest',
        'confirmStockRequestDispatch',
        'receiveStockRequest',
        'confirmStockRequestReceipt',
        'resolveTransferDifference',
        'confirmTransferDifference',
    ]) {
        assert.equal(block(composable, name), block(classicPanel, name), name);
    }

    assert.match(composable, /errorBag: 'inventoryOperations'/);
});

test('2b: the V2 saves send the Classic routes and payloads, closing the sheet only on success', () => {
    // New request: the Classic payload, key for key.
    const request = block(section, 'saveRequest');

    for (const key of [
        "'/inventory/stock-requests'",
        'expected_branch_id:props.operations.expectedBranchId',
        'source_location_public_id:stockRequest.source',
        'destination_location_public_id:stockRequest.destination',
        'sku_public_id:line.sku',
        'quantity:line.quantity',
        'closeSheet();',
    ]) {
        assert.ok(request.includes(key), key);
    }

    // Dispatch: the same body as the Classic dispatch, plus closing on success.
    assert.equal(
        block(section, 'saveDispatch')
            .replace('constsaveDispatch=', 'constdispatchStockRequest=')
            .replace(/,closeSheet\);$/, ');'),
        block(classicPanel, 'dispatchStockRequest'),
    );
    // Receive and resolve: the same bodies as Classic.
    assert.equal(
        block(section, 'saveReceipt')
            .replace('constsaveReceipt=', 'constreceiveStockRequest=')
            .replace(/,closeSheet\);$/, ');'),
        block(classicPanel, 'receiveStockRequest'),
    );
    assert.equal(
        block(section, 'saveResolution').replace(
            'constsaveResolution=',
            'constresolveTransferDifference=',
        ),
        block(classicPanel, 'resolveTransferDifference'),
    );
    // Approve and reject go through the Classic decide, after a plain sentence.
    assert.match(
        section,
        /action: \(\) => decideStockRequest\(row, 'approve'\)/,
    );
    assert.match(
        section,
        /action: \(\) => decideStockRequest\(row, 'reject'\)/,
    );
    assert.match(section, /destructive: true,/);
    // The same location twice is stopped with words before sending (the server answers it with "not found").
    assert.match(section, /@confirm="sendRequest"/);
    assert.match(
        section,
        /Choose a different location from the one you take from\./,
    );
    assert.match(block(section, 'sendRequest'), /saveRequest\(\);/);
});

test('2b: the receive and differences sheets keep the Classic scripts', () => {
    for (const name of [
        'rows',
        'reset',
        'fields',
        'errors',
        'valid',
        'differences',
        'skuFor',
        'batchFor',
        'submit',
    ]) {
        assert.equal(block(receive, name), block(classicReceipt, name), name);
    }

    for (const name of [
        'Choice',
        'choices',
        'blank',
        'choice',
        'needsReference',
        'ready',
        'kindLabel',
        'submit',
    ]) {
        assert.equal(
            block(differences, name),
            block(classicDifferences, name),
            name,
        );
    }
});

test("2b: row menus, sheets and wording follow Daniel's page 2 rules", () => {
    // Groups Open, Move, Check, Danger last; the wrong branch says why instead of hiding the action.
    const menu = block(section, 'requestMenu');
    assert.ok(
        menu.indexOf("label:'Open'") < menu.indexOf("label:'Move'") &&
            menu.indexOf("label:'Move'") < menu.indexOf("label:'Check'") &&
            menu.indexOf("label:'Check'") < menu.indexOf("label:'Danger'"),
    );
    assert.match(section, /Only the sending branch can dispatch this/);
    assert.match(section, /Only the receiving branch can receive this/);
    // Long forms are tall sheets that ask before they close.
    assert.equal((section.match(/size="tall"/g) ?? []).length, 2);
    assert.equal((section.match(/:dirty="dirty"/g) ?? []).length, 2);
    assert.match(receive, /size="tall"/);
    assert.match(receive, /:dirty="dirty"/);
    // Said before the press.
    assert.match(section, /Dispatching takes the stock out of/);
    assert.match(section, /Choose a batch for every line\./);
    assert.match(receive, /Confirming adds only the accepted quantities to/);
    // Remove is a real 44 px button; one filled button per sheet (Resolve is outline).
    assert.match(
        section,
        /class="inline-flex h-11[^"]*"\s*@click="removeStockRequestLine\(index\)"/,
    );
    assert.ok(!/bg-primary/.test(differences));
    // Batches come from the server picker for one SKU, earliest expiry first, held ones left out and counted.
    assert.match(section, /kind="batches"/);
    assert.match(
        picker,
        /sku: props\.kind === 'batches' \? props\.sku : undefined/,
    );
    assert.match(picker, /Earliest expiry first\./);
    // The batch list shows at once as choice buttons (the combobox list opens only after typing).
    assert.match(
        picker,
        /<template v-else-if="kind === 'batches'">\s*<V2OptionGroup/,
    );
    assert.match(picker, /row\.held !== true/);
});

test('2b: Classic stays untouched by V2 and leaves out requests only when the V2 page asks', () => {
    for (const source of [classicPanel, classicReceipt, classicDifferences]) {
        assert.ok(!source.includes('inventory-v2'));
        assert.ok(!source.includes('components/v2/'));
    }

    assert.match(classicPanel, /hideRequests\?: boolean;/);
    assert.match(classicPanel, /v-if="!hideRequests"/);
    // Optional boolean props with no default: false unless the V2 page passes them.
    assert.ok(!/withDefaults[\s\S]*hideRequests/.test(classicPanel));
    assert.ok(
        !read('resources/js/pages/Inventory/Index.vue').includes(
            'hide-requests',
        ),
    );
    const home = read('resources/js/pages/Inventory/V2/Index.vue');
    assert.match(
        home,
        /<V2DisclosureBar\s+title="Stock requests and transfers"\s+keep-mounted\s*>\s*<RequestsSection/,
    );
    // Page 2c: the Classic Operations panel is no longer on the V2 home at all.
    assert.ok(!home.includes('<InventoryOperationsPanel'));
});

test("2b round 2: Daniel's changes (units, live sum, item names, batch quantities, SKU hint)", () => {
    const words = read('resources/js/lib/inventory-v2-words.ts');
    // "5 units", decimals only when there is a fraction.
    assert.match(words, /trimQuantity\(String\(value \?\? '0'\)\)/);
    assert.match(
        section,
        /quantityWithUnit\(line\.requestedQuantity, line\.stockUnit\)/,
    );
    assert.match(
        receive,
        /quantityWithUnit\(receipt\.dispatched, unitFor\(index\)\)/,
    );
    assert.match(
        differences,
        /quantityWithUnit\(difference\.quantity, difference\.unit\)/,
    );
    // The live sum under the four boxes, and what is missing or over, in words.
    assert.match(
        receive,
        /accepted \+ \$\{f\(s\)\} short \+ \$\{f\(g\)\} damaged = /,
    );
    assert.match(receive, /not counted yet\./);
    // The summary never says "everything arrived" while a line does not add up, and a press then sends nothing.
    assert.match(receive, /@confirm="confirm"/);
    assert.match(
        block(receive, 'confirm'),
        /if\(!valid\.value\)\{tried\.value=true;/,
    );
    // The reason line by the switched-off button says it once; the summary box speaks only when every line adds up.
    assert.ok(
        !receive.includes(
            'Confirm receipt sends nothing until every line adds up',
        ),
    );
    assert.match(
        receive,
        /v-if="valid"\s+class="rounded-\[14px\] border bg-muted\/40/,
    );
    assert.match(
        receive,
        /more than dispatched: count anything extra under Extra\./,
    );
    // The item's name first, its SKU code under it.
    assert.match(section, /\{\{ line\.item \?\? line\.sku \}\}/);
    assert.match(receive, /row\.lines\[index\]\?\.item \?\? skuFor\(index\)/);
    // Each batch says how much is at the sending location, and the chosen one stays pressed.
    assert.match(section, /:location="sheetRow\.sourcePublicId"/);
    assert.match(picker, /here`/);
    assert.match(picker, /:model-value="modelValue"/);
    assert.match(picker, /v-if="modelValue && chosen && kind !== 'batches'"/);
    assert.match(
        read('resources/js/lib/inventory-picker.ts'),
        /params\.set\('location', input\.location\);/,
    );
    // Item search matches the name or the SKU code (owner, #724), and the box says so.
    assert.match(picker, /Search by name or SKU code\. Up to 25 matches\./);
});

test('2b: Confirm receipt is switched off while a line does not add up, and says why', () => {
    const dialog = read('resources/js/components/v2/V2Dialog.vue');
    assert.match(receive, /:confirm-disabled="!valid"/);
    assert.match(receive, /:confirm-disabled-reason="disabledReason"/);
    // The reason names the first line that does not add up, then what fixes it; nothing when every line adds up.
    const reason = block(receive, 'disabledReason');
    assert.match(reason, /if\(first<0\)\{returnundefined;\}/);
    assert.match(reason, /doesnotadduptowhatwasdispatched/);
    assert.match(reason, /Finishthecountstocontinue\./);
    // A press while switched off sends nothing, and the sheet has no form, so Enter in a box sends nothing either.
    assert.match(
        block(dialog, 'submit'),
        /if\(props\.confirmDisabled\)\{return;\}/,
    );
    assert.ok(!/<form\b/.test(dialog) && !/<form\b/.test(receive));
});
