import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Pricing: look only. It must send what Classic sends, keep Classic's rules, and add no second filled button.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Pricing/Index.vue');
const v2 = read('resources/js/pages/Pricing/V2/Index.vue');
const publish = read('resources/js/composables/pricing/usePricingPublish.ts');
const registry = read('config/v2-pages.php');

test('Pricing/Index is registered for Version 2 and the V2 file exists', () => {
    assert.match(registry, /'Pricing\/Index'/);
    assert.ok(v2.includes('<V2WorkLayout'));
    assert.ok(v2.includes('<PageFrame'));
});

test('V2 posts to the same routes as Classic', () => {
    for (const route of [
        '/pricing/price-books',
        '/pricing/charges',
        '/pricing/charges/${charge.publicId}/publish',
    ]) {
        assert.ok(classic.includes(route) || publish.includes(route), route);
        assert.ok(v2.includes(route) || publish.includes(route), route);
    }

    assert.ok(
        classic.includes("charge.isActive ? 'deactivate' : 'activate'") &&
            v2.includes("charge.isActive ? 'deactivate' : 'activate'"),
    );
    assert.ok(v2.includes('/pricing/charges/${charge.publicId}/history'));
});

test('the publish rules and payload match Classic (CAT-1b)', () => {
    const message = "'Enter an amount. Type 0.00 if this is free.'";

    assert.ok(classic.includes(`const emptyAmountMessage = ${message};`));
    assert.match(
        publish,
        new RegExp(
            `EMPTY_AMOUNT_MESSAGE =\\s*${message.replace(/[.]/g, '\\.')}`,
        ),
    );
    assert.match(publish, /const amountSen = rmToSen\(form\.amount\);/);
    assert.match(
        publish,
        /if \(amountSen === null\) \{\s*form\.error = EMPTY_AMOUNT_MESSAGE;\s*return;\s*\}/,
    );

    for (const key of [
        'price_book_public_id',
        'amount_sen',
        'expected_version',
        'expected_branch_id',
    ]) {
        assert.ok(classic.includes(key) && publish.includes(key), key);
    }

    assert.match(
        publish,
        /expected_version: charge\.currentPrice\?\.version \?\? 0/,
    );
    // The check runs before anything is sent.
    assert.ok(
        publish.indexOf('amountSen === null') < publish.indexOf('router.post('),
    );
});

test('the new charge and price book forms send the same fields as Classic', () => {
    for (const key of [
        'medicine_public_id',
        'service_public_id',
        'display_name',
        "currency: 'MYR'",
    ]) {
        assert.ok(classic.includes(key) && v2.includes(key), key);
    }

    assert.ok(v2.includes('maxlength="500"'));
    assert.ok(v2.includes('maxlength="150"'));
});

test('V2 keeps the publish permission and the scheduled-price line', () => {
    assert.match(v2, /v-if="canPublish"/);
    assert.match(v2, /From \$\{charge\.scheduledPrice\.effectiveLabel\}/);
    assert.ok(v2.includes('Publish price'));
});

test('V2 has one filled page button, native controls are not used, and dialogs are V2Dialog', () => {
    assert.equal((v2.match(/filledButton,/g) ?? []).length, 1);
    assert.doesNotMatch(v2, /<select[\s>]/);
    assert.doesNotMatch(v2, /type="file"/);
    assert.equal((v2.match(/<V2Dialog/g) ?? []).length, 4);
    assert.ok(v2.includes('tone="danger"'));
    assert.ok(v2.includes('cards="xl"'));
    assert.ok(v2.includes('data-actions'));
});

test('Classic keeps its own page and does not import V2 pieces', () => {
    assert.doesNotMatch(classic, /components\/v2|composables\/pricing/);
});
