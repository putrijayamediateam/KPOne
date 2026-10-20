import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileTemplate } from '@vue/compiler-sfc';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
test('classification has explicit versioned action and current-report fallback explanation', () => {
    const page = read('resources/js/pages/PaymentMethods/Index.vue');
    const descriptor = parse(page).descriptor;
    assert.deepEqual(
        compileTemplate({
            source: descriptor.template.content,
            filename: 'Index.vue',
            id: 'classification',
        }).errors,
        [],
    );
    assert.match(page, /classificationForm.lock_version = method.lockVersion/);
    assert.match(page, /Not classified/);
    assert.match(page, /including historical periods/);
    assert.match(page.replace(/\s+/g, ' '), /Stored payments and receipts never change/);
    assert.match(page, /\/classification`/);
    assert.doesNotMatch(page, /v-html/);
    const service = read(
        'app/Domain/Visit/Billing/Services/PaymentMethodClassificationService.php',
    );
    assert.match(service, /lockForUpdate/);
    assert.match(service, /DB::transaction/);
    assert.match(service, /changed_fields.*classification/);
});
