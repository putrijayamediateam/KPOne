// Classic only, CO-8: the Classic half of v2-pricing, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Pricing/Index.vue');

test('Classic Pricing posts to the price book, charge, publish, activation and history routes', () => {
    for (const route of [
        '/pricing/price-books',
        '/pricing/charges',
        '/pricing/charges/${charge.publicId}/publish',
        "charge.isActive ? 'deactivate' : 'activate'",
        '/pricing/charges/${charge.publicId}/history',
    ]) {
        assert.ok(classic.includes(route), route);
    }
});

test('Classic Pricing refuses an empty amount before sending and sends the versioned publish payload (CAT-1b)', () => {
    assert.ok(
        classic.includes(
            "const emptyAmountMessage = 'Enter an amount. Type 0.00 if this is free.';",
        ),
    );
    assert.match(
        classic,
        /if \(amountSen === null\) \{\s*state\.error = emptyAmountMessage;/,
    );

    for (const key of [
        'price_book_public_id',
        'amount_sen',
        'expected_version',
        'expected_branch_id',
    ]) {
        assert.ok(classic.includes(key), key);
    }

    assert.match(
        classic,
        /expected_version: charge\.currentPrice\?\.version \?\? 0/,
    );
});

test('Classic Pricing new charge and price book forms send their fields', () => {
    for (const key of [
        'medicine_public_id',
        'service_public_id',
        'display_name',
        "currency: 'MYR'",
    ]) {
        assert.ok(classic.includes(key), key);
    }
});

test('the server refuses a changed price with a price error key', () => {
    assert.ok(
        read(
            'app/Domain/Visit/Billing/Services/PricePublicationService.php',
        ).includes("withMessages(['price' =>"),
    );
});
