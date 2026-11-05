import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

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

test('rmToSen keeps the distinction between an empty box and a typed 0.00', () => {
    const javascript = ts.transpileModule(
        read('resources/js/lib/money.ts').replaceAll('export ', ''),
        { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
    ).outputText;
    const rmToSen = runInNewContext(`${javascript}\nrmToSen`, {});

    assert.equal(rmToSen(''), null);
    assert.equal(rmToSen('   '), null);
    assert.equal(rmToSen('0'), 0);
    assert.equal(rmToSen('0.00'), 0);
    assert.equal(rmToSen(' 1.50 '), 150);
    assert.ok(Number.isNaN(rmToSen('abc')));
});

test('the amount box does not look as if it already holds 0.00', () => {
    assert.doesNotMatch(pricing, /placeholder="0\.00"/);
    assert.equal(
        (pricing.match(/placeholder="e\.g\. 25\.00"/g) ?? []).length,
        2,
    );
    // Each error is linked to its own field.
    assert.equal(
        (pricing.match(/amount-error-\$\{charge\.publicId\}`/g) ?? []).length,
        4,
    );
});

// Daniel's follow-ups on the CAT-1 screens, carried in CAT-1b.
test('catalogue dialog titles are in sentence case', () => {
    const medicine = read('resources/js/pages/Medicine/Index.vue');
    const service = read('resources/js/pages/ClinicalService/Index.vue');

    assert.doesNotMatch(medicine, /'(Add|Edit) Medicine'/);
    assert.match(medicine, /editing \? 'Edit medicine' : 'Add medicine'/);
    assert.doesNotMatch(service, /'(Add|Edit) Clinical Service'/);
    assert.match(service, /'Edit clinical service'/);
});

test('on a Pricing card "Publish price" is the clear main action', () => {
    const cards = pricing.slice(
        pricing.indexOf('data-testid="charge-card"'),
        pricing.indexOf('<div class="hidden min-w-0 md:block">'),
    );

    assert.match(cards, /:variant="charge\.isActive \? 'ghost' : 'secondary'"/);
    assert.match(
        cards,
        /variant="outline"\s+:disabled="publishForm\(charge\)\.busy"/,
    );
});
