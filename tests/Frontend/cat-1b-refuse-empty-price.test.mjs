import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-1b (owner decision 2026-11-05): an empty price amount is refused, never
// published as RM 0.00. A typed 0.00 stays allowed.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const pricing = read('resources/js/pages/Pricing/Index.vue');
const request = read('app/Http/Requests/PricePublishRequest.php');
const service = read(
    'app/Domain/Visit/Billing/Services/PricePublicationService.php',
);
const message = 'Enter an amount. Type 0.00 if this is free.';

test('the Pricing page no longer turns an empty amount box into zero', () => {
    assert.doesNotMatch(
        pricing,
        /Math\.round\(Number\(state\.amount\) \* 100\)/,
    );
    assert.match(pricing, /const amountSen = rmToSen\(state\.amount\);/);
    assert.match(
        pricing,
        /if \(amountSen === null\) \{\s*state\.error = emptyAmountMessage;\s*return;\s*\}/,
    );
    assert.ok(pricing.includes(`const emptyAmountMessage = '${message}';`));
});

test('the empty-amount check runs before anything is sent', () => {
    const check = pricing.indexOf('if (amountSen === null)');
    const post = pricing.indexOf('router.post(');

    assert.ok(check > 0 && post > check);
});

test('the server gives the same clear message on the request and service paths', () => {
    assert.match(
        request,
        /'amount_sen\.required' => PricePublicationService::EMPTY_AMOUNT_MESSAGE/,
    );
    assert.ok(service.includes(`EMPTY_AMOUNT_MESSAGE = '${message}'`));
    // Zero sen keeps passing: the request still only requires a whole number
    // of at least 0.
    assert.match(request, /'amount_sen' => \['required', 'integer', 'min:0'\]/);
});

test('rmToSen keeps the distinction between an empty box and a typed 0.00', async () => {
    const source = read('resources/js/lib/money.ts');

    assert.match(source, /return normalized === '' \? null :/);
});
