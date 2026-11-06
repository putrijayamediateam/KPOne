import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-12: small Panel leftovers (count wording, a claim reference repeated on
// every printed page of a long claim, and the pack printing in ticked order).

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const index = read('resources/js/pages/PanelClaims/Index.vue');
const pack = read('resources/js/pages/PanelClaims/Pack.vue');

test('Summary counts read "1 claim" or "N claims", and Pending and Overdue count invoices', () => {
    assert.match(
        index,
        /const countLabel = \(count: number, noun: 'claim' \| 'invoice'\) =>\s*`\$\{count\} \$\{noun\}\$\{count === 1 \? '' : 's'\}`;/,
    );
    assert.match(
        index,
        /countLabel\(\s*work\.summary\.pending\.count,\s*'invoice',?\s*\)/,
    );
    assert.match(
        index,
        /countLabel\(\s*work\.summary\.overdue\.count,\s*'invoice',?\s*\)/,
    );
    assert.match(
        index,
        /countLabel\(\s*work\.summary\[key\]\.count,\s*'claim',?\s*\)/,
    );
    assert.doesNotMatch(index, /count \}\} claims/);
});

test('Each printed page of a claim repeats its claim and invoice number, never on screen', () => {
    assert.match(
        pack,
        /<thead>\s*<!--[^>]*-->\s*<tr class="pack-ref" data-testid="pack-ref">\s*<th colspan="5" scope="colgroup">\s*Panel claim \{\{ claim\.claimNo \}\} ·\s*\{\{ claim\.invoiceNumber \}\}/,
    );
    // No patient name on the repeated line.
    const ref = pack.slice(
        pack.indexOf('class="pack-ref"'),
        pack.indexOf('</tr>', pack.indexOf('class="pack-ref"')),
    );
    assert.doesNotMatch(ref, /patientName/);
    assert.match(pack, /\.pack-table \.pack-ref \{\s*display: none;\s*\}/);
    assert.match(
        pack,
        /@media print \{\s*\.pack-table \.pack-ref \{\s*display: table-row;/,
    );
});

test('The pack sends the claims in the order they were ticked', () => {
    // packSelected keeps insertion order, and Print sends that order unchanged.
    assert.match(
        index,
        /const packRows = computed\(\(\) => Object\.values\(packSelected\.value\)\);/,
    );
    assert.match(
        index,
        /claims: packRows\.value\.map\(\(row\) => row\.publicId\)/,
    );
    // The cover numbers rows in the order the server returns them.
    assert.match(
        pack,
        /v-for="\(claim, index\) in pack\.claims"[\s\S]{0,200}\{\{ index \+ 1 \}\}/,
    );
});

test('The totals, remarks and photos stay together and carry the claim reference in print', () => {
    assert.match(
        pack,
        /<div class="pack-avoid" data-testid="pack-closing">\s*<p class="pack-ref-line" data-testid="pack-ref-totals">\s*Panel claim \{\{ claim\.claimNo \}\} ·\s*\{\{ claim\.invoiceNumber \}\}\s*<\/p>\s*<dl class="pack-totals">/,
    );
    const closing = pack.slice(
        pack.indexOf('data-testid="pack-closing"'),
        pack.lastIndexOf('class="pack-footer"'),
    );
    assert.match(closing, /data-testid="pack-photos"/);
    assert.match(closing, /claim\.remarks/);
    // Never a table footer: that would repeat the totals on every page.
    assert.doesNotMatch(pack, /<tfoot/);
    assert.match(pack, /\.pack-ref-line \{\s*display: none;/);
    assert.match(
        pack,
        /@media print \{[\s\S]*?\.pack-ref-line \{\s*display: block;/,
    );
});
