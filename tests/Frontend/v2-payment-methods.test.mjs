import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Payment Methods: look only. Same routes, same payloads, same lock version on a classification change.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PaymentMethods/Index.vue');
const v2 = read('resources/js/pages/PaymentMethods/V2/Index.vue');
const registry = read('config/v2-pages.php');

test('PaymentMethods/Index is registered for Version 2 and the V2 file exists', () => {
    assert.match(registry, /'PaymentMethods\/Index'/);
    assert.ok(v2.includes('<V2WorkLayout'));
    assert.ok(v2.includes('<PageFrame'));
});

test('V2 calls the same routes as Classic', () => {
    for (const route of [
        "'/payment-methods'",
        '`/payment-methods/${classifying.value.id}/classification`',
        '`/payment-methods/${editingId.value}`',
        "`/payment-methods/${method.id}/${method.isActive ? 'deactivate' : 'publish'}`",
    ]) {
        assert.ok(classic.includes(route), `classic ${route}`);
        assert.ok(v2.includes(route), `v2 ${route}`);
    }
});

test('V2 sends the same fields and keeps the lock version and the empty-description rule', () => {
    for (const key of [
        'requires_reference',
        'sort_order',
        'lock_version',
        'classification',
        'description: data.description.trim() || null',
    ]) {
        assert.ok(classic.includes(key) && v2.includes(key), key);
    }

    for (const limit of [
        'maxlength="40"',
        'maxlength="100"',
        'maxlength="500"',
    ]) {
        assert.ok(classic.includes(limit) && v2.includes(limit), limit);
    }

    assert.match(v2, /classificationForm\.lock_version = method\.lockVersion/);
    assert.match(
        v2,
        /value === 'cash' \|\| value === 'non_cash' \? value : null/,
    );
});

test('V2 keeps Classic wording for classification and status', () => {
    for (const text of [
        'Not classified',
        'Non-cash',
        'Requires a transaction reference',
        'New methods are inactive until published.',
        'Classification changes apply',
    ]) {
        assert.ok(classic.includes(text) && v2.includes(text), text);
    }
});

test('V2 has one filled page button, no native select or file input, V2Dialogs and the changed deactivate path', () => {
    assert.equal((v2.match(/filledButton/g) ?? []).length, 2);
    assert.doesNotMatch(v2, /<select[\s>]/);
    assert.doesNotMatch(v2, /type="file"/);
    assert.equal((v2.match(/<V2Dialog/g) ?? []).length, 4);
    assert.ok(v2.includes('tone="danger"'));
    assert.ok(v2.includes('cards="xl"'));
    assert.ok(
        v2.includes(
            'It will not be offered at checkout. Payments already recorded are not changed. You can publish it again.',
        ),
    );
});

test('Classic keeps its own page and does not import V2 pieces', () => {
    assert.doesNotMatch(classic, /components\/v2/);
});
