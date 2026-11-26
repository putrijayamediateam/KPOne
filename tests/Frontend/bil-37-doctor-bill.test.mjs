import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-37: the Resident Doctor's read-only bill of their own patient's visit.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );

test('BIL-37: the bill page is read only, with the shared banner and no form, button or print', () => {
    const source = read('resources/js/pages/Billing/Review.vue');
    assert.match(source, /<ReadOnlyBanner>/);
    assert.doesNotMatch(
        source,
        /<form|<button|useForm|router\.(post|put|patch|delete)|window\.print|printUrl/,
    );
    assert.match(source, /Not finalised yet\./);
    assert.match(source, /payment\.recordedBy/);
    assert.doesNotMatch(
        source,
        /receiptNumber|billTo|notes|reason|identifier|phone/i,
    );
});

test('BIL-37: "View bill" appears only when the server sends the link', () => {
    for (const [path, guard] of [
        ['resources/js/pages/Clinical/Show.vue', 'v-if="billViewUrl"'],
        [
            'resources/js/pages/Billing/Invoice.vue',
            'v-if="invoice.links.billView"',
        ],
        [
            'resources/js/pages/Visits/History.vue',
            'v-if="history.links.billView"',
        ],
    ]) {
        const source = read(path);
        assert.ok(source.includes(guard), `${path} guards the link`);
        assert.match(source, /View bill/);
    }
});
