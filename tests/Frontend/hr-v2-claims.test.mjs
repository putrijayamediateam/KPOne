import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Staff claims: the Version 2 page shares two composables with Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Claims.vue');
const classic = read('resources/js/pages/HR/Claims.vue');
const classicSheet = read('resources/js/components/hr/claims/ClaimSheet.vue');
const page = read('resources/js/composables/hr/useClaimsPage.ts');
const sheet = read('resources/js/composables/hr/useClaimSheet.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template.replace(/\s+/g, ' ');
const tight = flat.replace(/=" /g, '="').replace(/ "/g, '"');

test('the V2 page compiles, is registered, and shares the composables with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-claims',
            source: template,
            filename: 'Claims.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Claims',/);
    assert.match(script, /useClaimsPage\(props\)/);
    assert.match(script, /useClaimSheet\(/);
    assert.match(classic, /useClaimsPage\(props\)/);
    assert.match(classicSheet, /useClaimSheet\(props/);
    // Every request comes from the composables: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(
        script,
        /fetch\(|axios|useForm|crypto\.randomUUID|localStorage|sessionStorage/,
    );

    for (const url of [
        '/hr/claims/${row.id}/withdraw',
        '/hr/claims/${row.id}/${decision.kind}',
        '/hr/claims/${row.id}/pay',
        '/hr/claims/${row.id}/reverse',
        '/hr/claims/settings',
        '/hr/claims/categories',
    ]) {
        assert.ok(page.includes(url), url);
    }

    assert.match(sheet, /'\/hr\/claims'/);
    assert.match(sheet, /forceFormData/);
});

test('a receipt is a file tile: V2 never loads a thumbnail ahead of the person pressing it', () => {
    assert.doesNotMatch(template, /thumbnails|<img/);
    assert.match(template, /<ReceiptList :receipts="row\.receipts" \/>/);
});

test('V2 rules: one filled action per screen, red for reject, withdraw and reverse, reasons start empty', () => {
    // Filled: New claim (My claims), Show (Monthly), Save settings (Settings); everything else is outline or red.
    const filled = flat.match(/:class="primaryButton"/g) ?? [];
    assert.equal(filled.length, 3);
    assert.match(tight, /:class="dangerButton"[^>]*data-test="claim-reject"/);
    assert.match(
        tight,
        /:tone="decision\.kind === 'approve' \? 'default' : 'danger'"/,
    );
    assert.match(
        template,
        /title="Reverse this payment\?"[^>]*confirm-label="Reverse payment"[^>]*tone="danger"/s,
    );
    assert.match(
        template,
        /title="Withdraw this claim\?"[^>]*confirm-label="Withdraw"[^>]*tone="danger"/s,
    );
    assert.match(script, /'Pick a reason to continue\.'/);
    assert.doesNotMatch(source, /OperationalConfirmDialog|<Sheet/);
});

test('the Classic templates did not change', () => {
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
    assert.doesNotMatch(classicSheet, /V2Dialog/);
    assert.match(
        classic,
        /<ReceiptList :receipts="row\.receipts" thumbnails \/>/,
    );
});

test('Record payment: "How it was paid" starts empty and the action waits for a method (display only; same request)', () => {
    assert.match(script, /'Pick how it was paid to continue\.'/);
    assert.match(script, /const methodPicked = ref\(false\)/);
    assert.match(
        flat.replace(/="\s+/g, '="').replace(/\s+"/g, '"'),
        /:model-value="methodPicked \? payment\.method : ''"/,
    );
    // The request is the shared one: the method sent is payment.method, picked by the person.
    assert.match(page, /method: payment\.method,/);
});
