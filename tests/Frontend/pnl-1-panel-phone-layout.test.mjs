import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-1 (SMOKE-1 S1-002 and S1-003): the Panel Claims pages and the Panel
// invoices filter row must not scroll sideways on a 390 px phone.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const claimsIndex = read('resources/js/pages/PanelClaims/Index.vue');
const claimShow = read('resources/js/pages/PanelClaims/Show.vue');
const invoices = read('resources/js/pages/PanelInvoices/Index.vue');

test('Panel Claims list keeps the hidden Actions label inside the table scroll box', () => {
    // An absolutely positioned sr-only label with no positioned ancestor escapes
    // the overflow-x-auto table wrapper and widened the page to about 1,000 px.
    const actionHeaders = [
        ...claimsIndex.matchAll(
            /<th([^>]*)>\s*<span class="sr-only">Actions<\/span>/g,
        ),
    ];
    assert.equal(actionHeaders.length, 2);

    for (const [, attributes] of actionHeaders) {
        assert.match(attributes, /class="[^"]*\brelative\b[^"]*"/);
    }
});

test('Panel claim page wraps long references, document names and notes', () => {
    const classesBefore = (pattern) => {
        const match = claimShow.match(pattern);
        assert.ok(match, `missing ${pattern}`);

        return match[1];
    };

    for (const classes of [
        classesBefore(/<span class="([^"]*)">\{\{\s*payment\.reference/),
        classesBefore(/class="([^"]*)"\s*>\s*\{\{ document\.name \}\}/),
    ]) {
        assert.match(classes, /\bmin-w-0\b/);
        assert.match(classes, /\bwrap-anywhere\b/);
    }

    assert.match(
        claimShow,
        /v-if="event\.externalReference"\s*class="[^"]*\bwrap-anywhere\b/,
    );
    assert.match(claimShow, /v-if="event\.note" class="[^"]*\bwrap-anywhere\b/);
    assert.match(
        claimShow,
        /class="[^"]*\bwrap-anywhere\b[^"]*"\s*>\s*Member reference:/,
    );
});

test('Panel Claims date and amount filter pairs wrap on a narrow phone', () => {
    const pairs = [
        ...claimsIndex.matchAll(/<span class="mt-1 ([^"]*)">\s*<input/g),
    ].map(([, classes]) => classes);
    assert.equal(pairs.length, 3);

    for (const classes of pairs) {
        assert.match(classes, /\bflex-wrap\b/);
    }
});

test('Panel invoices filter row shrinks to a phone width', () => {
    assert.match(
        invoices,
        /<form\s+class="grid grid-cols-1 gap-3 [^"]*md:grid-cols-4"/,
    );

    const labels = [
        ...invoices.matchAll(/<label\b[^>]*\sclass="([^"]*)"/g),
    ].map(([, classes]) => classes);
    assert.equal(labels.length, 8);

    for (const classes of labels) {
        assert.match(classes, /\bmin-w-0\b/);
    }

    const selects = [...invoices.matchAll(/<select[^>]*class="([^"]*)"/g)];
    assert.equal(selects.length, 2);

    for (const [, classes] of selects) {
        assert.match(classes, /\bw-full\b/);
        assert.match(classes, /\bmin-w-0\b/);
    }

    assert.match(
        invoices,
        /<div class="flex flex-wrap items-end gap-2 md:col-span-4">/,
    );
});
