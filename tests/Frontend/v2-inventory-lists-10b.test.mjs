import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const registry = read('config/v2-pages.php');
const parts = (page) => {
    const source = read(page);

    return {
        source,
        script: source.slice(0, source.indexOf('<template>')),
        template: source.slice(source.indexOf('<template>')),
        flat: source.slice(source.indexOf('<template>')).replace(/\s+/g, ' '),
    };
};
const pages = {
    terms: 'SupplierTerms',
    skus: 'Skus',
    requests: 'PurchaseRequests',
};
const classic = Object.fromEntries(
    Object.entries(pages).map(([k, f]) => [
        k,
        read(`resources/js/pages/Inventory/${f}.vue`),
    ]),
);
const v2 = Object.fromEntries(
    Object.entries(pages).map(([k, f]) => [
        k,
        parts(`resources/js/pages/Inventory/V2/${f}.vue`),
    ]),
);
/** The source of `const name = …` up to the next top-level statement, without whitespace. */
const block = (source, name) => {
    const start = source.search(
        new RegExp(`\\n[ \\t]*(?:const|type|function) ${name}\\b`),
    );
    assert.ok(start >= 0, `${name} not found`);
    const rest = source.slice(start + 1);
    const indent = /^[ \t]*/.exec(rest)[0];
    const next = rest
        .slice(indent.length)
        .search(
            new RegExp(
                `\\n${indent}(?:const |type |let |watch|function |//|/\\*\\*|return \\{|\\}\\n|<\\/script>)`,
            ),
        );

    return (next < 0 ? rest : rest.slice(0, next + indent.length))
        .replace(/\s+/g, '')
        .replace(/,(?=[)\]}])/g, '');
};
/** Every `watch(` block of a source, without whitespace, in order. */
const watches = (source) =>
    source
        .slice(0, source.indexOf('</script>'))
        .split(/\nwatch\(/)
        .slice(1)
        .map((chunk) =>
            chunk.slice(0, chunk.indexOf('\n);')).replace(/\s+/g, ''),
        );

const verbatim = {
    terms: [
        'Row',
        'props',
        'q',
        'status',
        'visit',
        'blank',
        'create',
        'priceError',
        'payload',
        'priceIsValid',
        'submitCreate',
        'editing',
        'edit',
        'editPriceError',
        'startEdit',
        'submitEdit',
    ],
    skus: [
        'Sku',
        'props',
        'q',
        'status',
        'visit',
        'storageTypes',
        'storageOptions',
        'storageLabel',
        'editing',
        'form',
        'startEdit',
        'confirming',
        'submit',
        'save',
        'lockedRows',
        'errorFor',
    ],
    requests: [
        'Line',
        'PurchaseRequest',
        'PrefillLine',
        'Tab',
        'props',
        'rejectReasons',
        'statusLabel',
        'statusTone',
        'visit',
        'busy',
        'generalError',
        'DraftLine',
        'newLine',
        'sendOpen',
        'send',
        'sendErrors',
        'locationOptions',
        'openSend',
        'removeLine',
        'filledLines',
        'sendSummary',
        'submitSend',
        'sendGeneral',
        'converting',
        'convert',
        'convertErrors',
        'openConvert',
        'supplierPin',
        'orderedLines',
        'submitConvert',
        'convertGeneral',
        'rejecting',
        'rejectReason',
        'rejectErrors',
        'openReject',
        'submitReject',
        'rejectGeneral',
        'withdrawing',
        'withdrawError',
        'withdraw',
        'tabs',
        'emptyText',
        'lineWord',
    ],
};

for (const [key, names] of Object.entries(verbatim)) {
    test(`page 10b: the ${pages[key]} Classic script is copied verbatim`, () => {
        for (const name of names) {
            assert.equal(
                block(v2[key].script, name),
                block(classic[key], name),
                name,
            );
        }

        // Only the copied section: a page may add its own V2 watch below the marker.
        const copied = v2[key].script.slice(
            0,
            v2[key].script.indexOf('// ---- V2 only below this line ----'),
        );
        assert.deepEqual(watches(`${copied}</script>`), watches(classic[key]));
    });
}

test('page 10b: Purchase requests keeps the onMounted prefill open and every server path', () => {
    const mounted = (source) =>
        source
            .slice(
                source.indexOf('onMounted('),
                source.indexOf('});', source.indexOf('onMounted(')),
            )
            .replace(/\s+/g, '');
    assert.equal(mounted(v2.requests.script), mounted(classic.requests));

    for (const path of [
        "'/inventory/purchase-requests'",
        '`/inventory/purchase-requests/${request.publicId}/convert`',
        '`/inventory/purchase-requests/${request.publicId}/reject`',
        '`/inventory/purchase-requests/${request.publicId}/withdraw`',
    ]) {
        assert.ok(
            classic.requests.includes(path) &&
                v2.requests.source.includes(path),
            path,
        );
    }
});

test('page 10b: registered, V2 blocks, no native select, radio or checkbox; Classic untouched', () => {
    for (const file of Object.values(pages)) {
        assert.match(registry, new RegExp(`'Inventory/${file}',`));
    }

    for (const page of Object.values(v2)) {
        assert.ok(!page.template.includes('<select'));
        assert.ok(!page.template.includes('type="radio"'));
        assert.ok(!page.template.includes('type="checkbox"'));
        assert.match(page.template, /<V2Table/);
        assert.match(page.template, /<V2Dialog/);
        assert.equal((page.template.match(/<V2Pager/g) ?? []).length, 1);
        assert.ok(!page.source.includes('CompactPagination'));
        assert.ok(!page.source.includes('OperationalConfirmDialog'));
        assert.ok(!page.source.includes('InventorySearchSelect'));
    }

    for (const source of Object.values(classic)) {
        assert.ok(!source.includes('components/v2'));
    }
});

test('page 10b: Supplier price list sheets call Classic submits; prices for Director and Finance only', () => {
    const page = v2.terms;
    assert.match(page.flat, /<template v-if="terms\.canManage" #actions>/);
    assert.match(page.flat, /@confirm="submitCreate"/);
    assert.match(
        page.flat,
        /@confirm="editingRow && submitEdit\(editingRow\)"/,
    );
    assert.match(page.flat, /@click="openEdit\(row\)"/);
    assert.match(page.script, /startEdit\(row\);\s*edit\.defaults\(\);/);
    assert.match(page.flat, /v-if="terms\.canSeeCost"/);
    assert.match(page.flat, /data-testid="price-hidden-note"/);
    assert.match(page.flat, /:disabled="!edit\.is_active"/);
});

test('page 10b: SKUs edit sheet, locked fields and the danger sheet for stock on hand', () => {
    const page = v2.skus;
    assert.match(page.flat, /@confirm="editingSku && submit\(editingSku\)"/);
    assert.match(page.flat, /@confirm="confirming && save\(confirming\)"/);
    assert.match(page.flat, /title="Turn this off while it has stock\?"/);
    assert.match(page.flat, /tone="danger"/);
    assert.match(page.flat, /data-testid="units-locked"/);
    assert.match(page.flat, /lockedRows\(editingSku\)/);
    assert.match(
        page.flat,
        /:options="storageOptions\(editingSku\.storageType\)"/,
    );
    assert.match(page.flat, /data-testid="view-only-note"/);
    assert.match(page.script, /startEdit\(sku\);\s*form\.defaults\(\);/);
});

test('page 10b: Purchase requests tabs, sheets and the own-request rule', () => {
    const page = v2.requests;
    assert.match(page.flat, /<V2Tabs :model-value="requests\.tab"/);
    assert.match(page.flat, /@confirm="submitSend"/);
    assert.match(page.flat, /@confirm="submitConvert"/);
    assert.match(page.flat, /@confirm="submitReject"/);
    assert.match(page.flat, /@confirm="withdraw"/);
    assert.match(page.flat, /data-testid="purchase-request-own"/);
    assert.match(page.flat, /'Pick a reason to continue\.'/);
    assert.match(page.flat, /:selected-label="line\.pinned\?\.label \?\? ''"/);
    assert.match(page.flat, /data-testid="purchase-request-summary"/);
    assert.match(
        page.flat,
        /<V2Picker :model-value="send\.location \? String\(send\.location\) : null" label="Deliver to"/,
    );
    assert.match(page.source, /quantityWithUnit\(/);
});

test("page 10b: Daniel's changes, send off with a reason, room under the last field, named actions", () => {
    const requests = v2.requests;
    // Deliver to tells same-named locations apart by code and type.
    assert.match(
        requests.script,
        /location\?\.code \? unbroken\(location\.code\)/,
    );
    assert.match(requests.script, /medical_stock: 'Medical stock'/);
    // Send for review is off, with the reason in words, until there is a location and a line with a SKU and a quantity.
    assert.match(
        requests.flat,
        /:confirm-disabled="sendBlockedReason !== undefined" :confirm-disabled-reason="sendBlockedReason"/,
    );
    assert.match(requests.script, /'Add a SKU and a quantity to continue\.'/);
    assert.match(
        requests.script,
        /'Choose where the stock should go to continue\.'/,
    );
    // A specific name for Withdraw, an "Actions" heading for screen readers, and a request number kept whole.
    assert.match(requests.flat, /`Withdraw request \$\{request\.number\}`/);
    assert.match(requests.flat, /<span class="sr-only">Actions<\/span>/);
    assert.match(
        requests.flat,
        /font-mono text-sm font-medium whitespace-nowrap" >\{\{ unbroken\(request\.number\) \}\}/,
    );
    // The long sheets leave room so the last field and its help scroll clear of the footer.
    assert.equal(
        (v2.terms.template.match(/flex flex-col gap-4 pb-8/g) ?? []).length,
        2,
    );
    assert.match(
        v2.skus.flat,
        /<div v-if="editingSku" class="flex flex-col gap-6 pb-8">/,
    );
    assert.match(
        requests.flat,
        /<div v-if="converting" class="flex flex-col gap-4 pb-8">/,
    );
});
