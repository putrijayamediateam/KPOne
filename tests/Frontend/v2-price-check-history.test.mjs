import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Price list check and Price history: read-only, look only. Same props, addresses and filters as Classic.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const checkClassic = read('resources/js/pages/Pricing/PriceCheck.vue');
const checkV2 = read('resources/js/pages/Pricing/V2/PriceCheck.vue');
const historyClassic = read('resources/js/pages/Pricing/PriceHistory.vue');
const historyV2 = read('resources/js/pages/Pricing/V2/PriceHistory.vue');
const registry = read('config/v2-pages.php');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('both pages are registered and use the V2 blocks', () => {
    assert.match(registry, /'Pricing\/PriceCheck'/);
    assert.match(registry, /'Pricing\/PriceHistory'/);

    for (const page of [checkV2, historyV2]) {
        for (const block of [
            '<V2WorkLayout',
            '<PageFrame',
            '<V2Table',
            '<V2Pager',
        ]) {
            assert.ok(page.includes(block), block);
        }
    }

    assert.ok(checkV2.includes('<V2Stat'));
    assert.ok(checkV2.includes('<V2Toolbar'));
    assert.ok(historyV2.includes('<V2Picker'));
});

test('Price list check keeps the Classic address, filters, history link and empty wording', () => {
    for (const key of [
        "'/pricing/price-check'",
        'search: search.value || undefined',
        'type: type.value || undefined',
        'attention: attention.value ? 1 : undefined',
        '`/pricing/charges/${row.chargePublicId}/history?from=price-check`',
        'No active medicines or services match.',
        'Nothing needs attention.',
        'All active items have the prices billing needs.',
        'Select a branch first.',
        'Branch price',
        'Price unit:',
    ]) {
        assert.ok(squash(checkClassic).includes(squash(key)), `classic ${key}`);
        assert.ok(squash(checkV2).includes(squash(key)), `v2 ${key}`);
    }

    assert.ok(!/<select\b/.test(checkV2));
    assert.ok(checkV2.includes("title: 'Price list check'"));
});

test('Price history keeps the Classic address, book filter, fixed way back and status rules', () => {
    for (const key of [
        '`/pricing/charges/${props.result.charge.publicId}/history`',
        'book: book.value || undefined',
        'from: props.from ?? undefined',
        "row.status === 'in_use' || row.status === 'scheduled'",
        "row.status === 'not_in_use' ? 'Not in use (book inactive)' : 'Replaced'",
        'row.amountSen === 0 && isChip(row)',
        'No prices published for this charge yet.',
        'Billing refuses this charge until it is switched on.',
    ]) {
        const classicKey = key.replace('isChip', 'isBadge');
        assert.ok(
            squash(historyClassic).includes(squash(classicKey)),
            `classic ${classicKey}`,
        );
        assert.ok(squash(historyV2).includes(squash(key)), `v2 ${key}`);
    }

    // The way back is the crumb, from the same fixed targets (price list check or Pricing), never a free address.
    assert.ok(historyV2.includes("href: '/pricing/price-check'"));
    assert.ok(historyV2.includes("href: '/pricing'"));
    assert.ok(!historyV2.includes('Back to Pricing'));
});

test("Daniel's round 1: real Details buttons, a colour key, words in the subtitle, a back link, a time note and a dot on Replaced", () => {
    assert.ok(!/<details\b/.test(checkV2));
    assert.match(checkV2, /:aria-expanded="\s*isOpen\(row/);
    assert.ok(checkV2.includes('min-h-11'));
    assert.ok(checkV2.includes('Red chips need fixing before billing.'));
    assert.ok(checkV2.includes('data-action="back-to-pricing"'));
    assert.ok(historyV2.includes('data-action="back"'));
    assert.ok(historyV2.includes('← {{ parent.title }}'));
    assert.ok(historyV2.includes('Times are in the branch'));
    assert.ok(historyV2.includes('bg-muted-foreground/50'));
    assert.ok(historyV2.includes('const words = (code: string) =>'));
});

test('Price history V2 names the branch time zone when the server sends it, and falls back to words when it does not', () => {
    assert.ok(historyV2.includes('timezone?: string;'));
    assert.ok(historyV2.includes('${city} time.'));
    assert.ok(historyV2.includes("replaceAll('_', ' ')"));
    assert.ok(historyV2.includes("Times are in the branch's time zone."));
    assert.ok(historyV2.includes('{{ timesNote }}'));
});

test('Classic pages import nothing from V2', () => {
    assert.ok(!/\/V2\//.test(checkClassic));
    assert.ok(!/\/V2\//.test(historyClassic));
});
