import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Pricing/Index.vue binds InputError to every field that can carry a validation error', () => {
    const source = read('resources/js/pages/Pricing/Index.vue');

    assert.match(source, /InputError :message="chargeForm\.errors\.type"/);
    assert.match(
        source,
        /InputError\s+:message="chargeForm\.errors\.medicine_public_id"/,
    );
    assert.match(
        source,
        /InputError\s+:message="chargeForm\.errors\.service_public_id"/,
    );
    assert.match(source, /InputError :message="bookScopeError\(\)"/);
});

test('bookScopeError reads the synthetic scope-conflict error alongside branch_id', () => {
    const source = read('resources/js/pages/Pricing/Index.vue');

    assert.match(
        source,
        /const bookScopeError = \(\) =>\s*\n\s*\(bookForm\.errors as Record<string, string \| undefined>\)\.scope \?\?\s*\n\s*bookForm\.errors\.branch_id;/,
    );
});

test('chargeForm only posts the id field relevant to the selected charge type', () => {
    const source = read('resources/js/pages/Pricing/Index.vue');

    assert.match(source, /chargeForm\s*\n\s*\.transform\(/);
    assert.match(
        source,
        /type === 'medicine' \? \{ medicine_public_id \} : \{\}/,
    );
    assert.match(
        source,
        /type === 'service' \? \{ service_public_id \} : \{\}/,
    );
});

test('PricingChargeStoreRequest treats the unused id field as nullable', () => {
    const source = read('app/Http/Requests/PricingChargeStoreRequest.php');

    assert.match(
        source,
        /'medicine_public_id' => \['nullable', 'required_if:type,medicine', 'uuid'\]/,
    );
    assert.match(
        source,
        /'service_public_id' => \['nullable', 'required_if:type,service', 'uuid'\]/,
    );
});
