import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classicPanel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);
const classicReceiving = read(
    'resources/js/components/inventory/VerifiedReceivingForm.vue',
);
const classicReviews = read(
    'resources/js/components/inventory/ReceivingReviews.vue',
);
const composable = read(
    'resources/js/composables/inventory-v2/useOrdersAndReceiving.ts',
);
const section = read('resources/js/components/inventory-v2/OrdersSection.vue');
const receive = read('resources/js/components/inventory-v2/ReceiveSheet.vue');
const reviews = read(
    'resources/js/components/inventory-v2/ReceivingReviewsSection.vue',
);
const picker = read(
    'resources/js/components/inventory-v2/InventoryPickerField.vue',
);

/** The source of `const name = …` up to the next top-level `const`, without whitespace (indentation differs). */
const block = (source, name) => {
    const start = source.search(new RegExp(`\\n\\s*const ${name}\\b`));
    assert.ok(start >= 0, `${name} not found`);
    const rest = source.slice(start + 1);
    const indent = /^\s*/.exec(rest)[0];
    const next = rest
        .slice(indent.length)
        .search(
            new RegExp(
                `\\n${indent}(?:const |type |watch|watchEffect|function |// ----|\\}\\n)`,
            ),
        );

    return (
        (next < 0 ? rest : rest.slice(0, next + indent.length))
            .replace(/\s+/g, '')
            // Prettier adds a trailing comma when the same code sits one level deeper.
            .replace(/,(?=[)\]}])/g, '')
    );
};

test('2a: the orders composable is a verbatim copy of the Classic panel logic it replaces', () => {
    for (const name of [
        'rmToSen',
        'requestConfirmation',
        'confirmPendingAction',
        'canPurchase',
        'needsAnotherApprover',
        'shortCloseNeedsAnother',
        'submitShortClose',
        'lineProgress',
        'purchaseOrderStatusLabel',
        'send',
        'version',
        'createSupplier',
        'setSupplierActive',
        'confirmSupplierStatus',
        'createPurchaseOrder',
        'updatePurchaseOrder',
        'transitionPurchaseOrder',
        'confirmPurchaseOrderTransition',
        'receiptPayload',
        'receivePurchaseOrder',
        'persistRememberedReceipts',
    ]) {
        assert.equal(block(composable, name), block(classicPanel, name), name);
    }

    // Same error bag and remembered receipt key.
    assert.match(composable, /errorBag: 'inventoryOperations'/);
    assert.match(composable, /'InventoryOperations:goods-receipts:v2'/);
});

test('2a: the V2 sheets send the Classic routes and payload keys, closing only on success', () => {
    // Add supplier.
    assert.match(
        section,
        /send\('post', '\/inventory\/suppliers', supplier, \(\) => \{/,
    );
    // New purchase order: the Classic payload, key for key.
    const create = block(section, 'savePurchaseOrder');

    for (const key of [
        "'/inventory/purchase-orders'",
        'expected_branch_id:props.operations.expectedBranchId',
        'supplier_public_id:purchaseOrder.supplier',
        'destination_location_public_id:purchaseOrder.destination',
        'sku_public_id:line.sku',
        'quantity:line.quantity',
        'unit_cost_sen:rmToSen(line.estimatedUnitCostRm)',
    ]) {
        assert.ok(create.includes(key), key);
    }

    // Edit draft: the Classic PATCH with the order's lock version.
    const draft = block(section, 'saveDraft');

    for (const key of [
        '`/inventory/purchase-orders/${row.publicId}`',
        '...version(row)',
        'supplier_public_id:edit.supplier',
        'destination_location_public_id:edit.destination',
        'sku_public_id:line.skuPublicId',
        'quantity:edit.quantities[line.publicId]',
    ]) {
        assert.ok(draft.includes(key), key);
    }

    assert.ok(
        draft.includes(',closeSheet)'),
        'the draft sheet closes only on success',
    );
});

test('2a: the receive sheet keeps the Classic verified receiving request', () => {
    for (const name of [
        'lot',
        'reasons',
        'costError',
        'scannedLine',
        'boxRead',
        'boxHint',
    ]) {
        assert.equal(block(receive, name), block(classicReceiving, name), name);
    }

    // The form: same fields, lock version, idempotency and retry keys, session nonce.
    assert.equal(block(receive, 'form'), block(classicReceiving, 'form'));
    // The submit differs only by closing on success and focusing the summary after a refusal.
    const v2 = block(receive, 'submit')
        .replace(/\/\/V2only:[^,]*?refusal\./, '')
        .replace(
            /,onSuccess:\(\)=>emit\('close'\),onError:\(\)=>voidnextTick\(\(\)=>summary\.value\?\.focus\(\)\)/,
            '',
        );
    assert.equal(v2, block(classicReceiving, 'submit'));
    // Fresh keys whenever the delivery changes, as in Classic.
    assert.match(receive, /form\.idempotency_key = crypto\.randomUUID\(\);/);
    assert.match(receive, /form\.retry_key = crypto\.randomUUID\(\);/);
    // Said before the press (Daniel): what enters stock, and that an exception needs another approver.
    // It follows the counted quantity and the lots, with the server's rules: accepted and quarantined enter stock,
    // quarantined is held aside, rejected never enters, and the lots must equal the counted quantity.
    assert.match(receive, /enter the counted quantity\./);
    assert.match(
        receive,
        /Enter the counted quantity for \$\{uncounted\.length\} lines/,
    );
    assert.match(receive, /const entering = accepted \+ quarantined;/);
    assert.match(
        receive,
        /held aside \(quarantined\) and can't be dispensed\./,
    );
    assert.match(receive, /rejected \$\{plural\(rejected \/ 1000, unit\)\}/);
    assert.match(receive, /They must match before it can post\./);
    // The expiry box says the chosen date in words (it can read month-first), against the branch's today.
    assert.match(receive, /Expires \$\{words\}/);
    assert.match(receive, /timeZone: props\.timezone/);
    assert.match(receive, /Expired stock can't be received\./);
    // A missing or past expiry is refused with a plain line before sending; the send itself stays Classic's.
    assert.match(receive, /@confirm="post\(false\)"/);
    assert.match(receive, /@click="post\(true\)"/);
    assert.match(block(receive, 'post'), /submit\(review\);\};/);
    // "Remove lot N" is a real 44 px button.
    assert.match(
        receive,
        /class="inline-flex h-11[^"]*"\s*@click="line\.lots\.splice\(number, 1\)"\s*>\s*Remove lot \{\{ number \+ 1 \}\}/,
    );
    assert.match(
        receive,
        /needs a different CA\s+Supervisor, Finance Officer or Director/,
    );
});

test('2a: receiving reviews keep the Classic requests', () => {
    for (const name of [
        'requestCorrection',
        'load',
        'act',
        'resetCorrectionKey',
    ]) {
        assert.equal(block(reviews, name), block(classicReviews, name), name);
    }
});

test("2a: row menus, confirms, discard guard and cost wording follow Daniel's page 2 rules", () => {
    // Groups Open, Move, Check, Danger last; a disabled item says why.
    const menu = block(section, 'orderMenu');
    assert.ok(
        menu.indexOf("label:'Open'") < menu.indexOf("label:'Move'") &&
            menu.indexOf("label:'Move'") < menu.indexOf("label:'Check'") &&
            menu.indexOf("label:'Check'") < menu.indexOf("label:'Danger'"),
    );
    assert.match(section, /Needs a different Finance Officer or Director/);
    assert.match(
        reviews,
        /Needs a different CA Supervisor, Finance Officer or Director/,
    );
    // Closing a sheet with typed values asks first (the shared V2Dialog guard), and the long forms are tall sheets.
    assert.equal((section.match(/:dirty="dirty"/g) ?? []).length, 3);
    assert.match(receive, /:dirty="form\.isDirty"/);
    assert.equal((section.match(/size="tall"/g) ?? []).length, 2);
    assert.match(receive, /size="tall"/);
    assert.ok(!/Discard what you typed/.test(section + receive));
    assert.match(section, /@update:open="requestClose"/);
    // The estimated cost stays an input with its Classic meaning; no cost is displayed.
    assert.match(
        section,
        /For one stock unit \(for example one tablet\), not one\s+box\./,
    );
    assert.match(section, /Estimated cost per stock unit, RM \(optional\)/);
    assert.ok(!/cost_total_sen|unitCostSen|costSen \}\}/.test(section));
    // The picker keeps the server search and its 25-match limit, in words.
    assert.match(picker, /context: 'operations'/);
    assert.match(picker, /Up to 25 matches\. Type to narrow\./);
    assert.match(picker, /'No match\.'/);
    assert.match(picker, /Patient returns \(not for dispensing\)/);
});

test('2a: Classic stays untouched by V2', () => {
    for (const source of [classicPanel, classicReceiving, classicReviews]) {
        assert.ok(!source.includes('inventory-v2'));
        assert.ok(!source.includes('components/v2/'));
    }
});

test('2a: the Classic panel leaves out suppliers and purchase orders only when the V2 page asks', () => {
    assert.match(classicPanel, /hideOrders\?: boolean;/);
    assert.match(
        classicPanel,
        /v-if="operations\.permissions\.manageSuppliers && !hideOrders"/,
    );
    assert.match(classicPanel, /v-if="!hideOrders"/);
    // It defaults to false: an optional boolean prop with no default, so Classic shows orders unless told otherwise.
    assert.ok(!/withDefaults[\s\S]*hideOrders/.test(classicPanel));
    // Classic Inventory never passes it.
    assert.ok(
        !read('resources/js/pages/Inventory/Index.vue').includes('hide-orders'),
    );
});

test('2a: the V2 home shows the orders section in a bar that keeps its forms, and the Classic panel without orders', () => {
    const home = read('resources/js/pages/Inventory/V2/Index.vue');
    assert.match(
        home,
        /<V2DisclosureBar title="Orders and receiving" keep-mounted>\s*<OrdersSection/,
    );
    // Page 2c: the Classic Operations panel is no longer on the V2 home, so orders appear only in the V2 section.
    assert.ok(!home.includes('<InventoryOperationsPanel'));
});

test('Receive: the expiry uses the day-first V2 date field, limited to the branch today', () => {
    assert.match(receive, /<V2DateField\s+v-model="entry\.expiry_date"/);
    assert.match(receive, /subject="Expiry"/);
    assert.match(receive, /:min="branchToday\(\)"/);
    // The native month-first box is gone; the missing-expiry refusal before sending stays.
    assert.ok(!/type="date"/.test(receive));
    assert.match(receive, /'Enter the expiry date for every lot\.'/);
});
