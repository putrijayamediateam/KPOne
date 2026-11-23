// Classic only, CO-8: the Classic half of v2-price-check-history, kept when its V2 pages were removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const checkClassic = read('resources/js/pages/Pricing/PriceCheck.vue');
const historyClassic = read('resources/js/pages/Pricing/PriceHistory.vue');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('Classic Price list check keeps its address, filters, history link and empty wording', () => {
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
    }
});

test('Classic Price history keeps its address, book filter, fixed way back and status rules', () => {
    for (const key of [
        '`/pricing/charges/${props.result.charge.publicId}/history`',
        'book: book.value || undefined',
        'from: props.from ?? undefined',
        "row.status === 'in_use' || row.status === 'scheduled'",
        "row.status === 'not_in_use' ? 'Not in use (book inactive)' : 'Replaced'",
        'row.amountSen === 0 && isBadge(row)',
        'No prices published for this charge yet.',
        'Billing refuses this charge until it is switched on.',
    ]) {
        assert.ok(
            squash(historyClassic).includes(squash(key)),
            `classic ${key}`,
        );
    }
});
