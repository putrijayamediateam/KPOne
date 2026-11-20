// Classic only, CO-6: the Classic half of v2-inventory-lists-10b.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
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
    test(`page 10b: the Classic ${pages[key]} script keeps its named blocks`, () => {
        for (const name of names) {
            block(classic[key], name);
        }
    });
}

test('page 10b: Classic list pages import no V2 code', () => {
    for (const source of Object.values(classic)) {
        assert.ok(!source.includes('components/v2'));
    }
});
