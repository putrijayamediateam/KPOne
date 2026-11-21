// Classic only, CO-8: the Classic half of v2-payment-methods, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PaymentMethods/Index.vue');

test('Classic Payment Methods calls its list, classification, update and publish routes', () => {
    for (const route of [
        "'/payment-methods'",
        '`/payment-methods/${classifying.value.id}/classification`',
        '`/payment-methods/${editingId.value}`',
        "`/payment-methods/${method.id}/${method.isActive ? 'deactivate' : 'publish'}`",
    ]) {
        assert.ok(classic.includes(route), `classic ${route}`);
    }
});

test('Classic Payment Methods sends its fields with the lock version, the empty-description rule and the limits', () => {
    for (const key of [
        'requires_reference',
        'sort_order',
        'lock_version',
        'classification',
        'description: data.description.trim() || null',
        'maxlength="40"',
        'maxlength="100"',
        'maxlength="500"',
    ]) {
        assert.ok(classic.includes(key), key);
    }

    // The deactivate and publish routes carry no lock version.
    assert.doesNotMatch(classic, /lock_version: method/);
});

test('Classic Payment Methods keeps its classification and status wording', () => {
    for (const text of [
        'Not classified',
        'Non-cash',
        'Requires a transaction reference',
        'New methods are inactive until published.',
    ]) {
        assert.ok(classic.includes(text), text);
    }
});

// Bug fix: reka-ui 2.10.3's Checkbox uses `modelValue`, not `checked`, so `v-model:checked` never updated the form and a ticked
// "Requires a transaction reference" always saved false. Classic binds `modelValue` and writes a real boolean back.
test('Classic requires-reference checkbox binds modelValue (ticking sends true, unticking sends false)', () => {
    assert.ok(!classic.includes('v-model:checked'), 'no v-model:checked');

    for (const form of ['createForm', 'editForm']) {
        assert.match(
            classic,
            new RegExp(
                `:model-value="${form}\\.requires_reference"\\s+@update:model-value="\\s*${form}\\.requires_reference = \\$event === true\\s*"`,
            ),
            `${form} binds modelValue and writes a boolean`,
        );
    }
});
