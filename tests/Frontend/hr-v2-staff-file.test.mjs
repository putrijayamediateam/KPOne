import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 staff file: the Version 2 page shares its composable with Classic, so both send the same requests, and every
// masked value is released only by the same audited Show.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/StaffFile.vue');
const checklist = read('resources/js/components/hr/records/V2ChecklistTab.vue');
const classic = read('resources/js/pages/HR/StaffFile.vue');
const page = read('resources/js/composables/hr/useStaffFilePage.ts');
const show = read('resources/js/lib/hr-show-value.ts');
const classicMasked = read(
    'resources/js/components/hr/records/MaskedValue.vue',
);
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template
    .replace(/\s+/g, ' ')
    .replace(/="\s+/g, '="')
    .replace(/\s+"/g, '"');

test('the V2 page compiles, is registered, and shares the composable with Classic', () => {
    for (const [key, text] of Object.entries({ page: source, checklist })) {
        assert.deepEqual(
            compileTemplate({
                id: key,
                source: parse(text).descriptor.template.content,
                filename: `${key}.vue`,
            }).errors,
            [],
            key,
        );
    }

    assert.match(read('config/v2-pages.php'), /'HR\/StaffFile',/);
    assert.match(script, /useStaffFilePage\(props\)/);
    assert.match(classic, /useStaffFilePage\(props\)/);
    assert.match(checklist, /useChecklistTab\(props\)/);

    // Every write comes from the composable: the V2 page sends none of its own.
    for (const text of [script, checklist]) {
        assert.doesNotMatch(text, /router\.(get|post|put|patch|delete)\(/);
        assert.doesNotMatch(
            text,
            /fetch\(|axios|useForm|localStorage|sessionStorage|crypto\.randomUUID/,
        );
    }

    for (const url of [
        '/hr/records/${w.value.id}/periods',
        '/hr/records/${w.value.id}/personal',
        '/hr/records/${w.value.id}/sign-in',
    ]) {
        assert.ok(page.includes(url), url);
    }
});

test('masked values: the same audited Show as Classic, never kept or prefetched, and Confirm still waits for Show', () => {
    // One shared request for Classic and V2.
    assert.match(classicMasked, /requestShowValue\(/);
    assert.match(show, /method: 'POST'/);
    assert.match(show, /cache: 'no-store'/);
    assert.match(show, /JSON\.stringify\(\{ field, \.\.\.extra \}\)/);
    assert.match(script, /revealValue\(shownUrl\.value, field, extra\)/);
    assert.match(script, /`\/hr\/records\/\$\{w\.value\.id\}\/show-value`/);
    // Every sensitive field goes through V2MaskedValue with a reveal; no raw value is in the template.
    const masked = flat.match(/<V2MaskedValue/g) ?? [];
    assert.ok(masked.length >= 10);

    for (const field of [
        'legal_name',
        'identity',
        'date_of_birth',
        'address',
        'personal_phone',
        'emergency_contact',
        'locum_phone',
        'bank_account',
        'correction',
    ]) {
        assert.ok(flat.includes(`reveal('${field}'`), field);
    }

    assert.doesNotMatch(
        flat,
        /file\.personal\.(legalName|dateOfBirth|address|personalPhone|emergencyContact)\s*\}\}/,
    );
    // The new account cannot be confirmed until the checker has shown it.
    assert.match(flat, /\(\) => \(waitingShown = true\)/);
    assert.match(flat, /data-test="bank-confirm" :disabled="!waitingShown"/);
    assert.match(
        flat,
        /Show the new account number to check it before you confirm\./,
    );
    // A document opens only through its audited link; no thumbnail.
    assert.doesNotMatch(template, /<img|:src=/);
});

test('V2 rules: one filled action per tab, reasons start empty, red for reject, withdraw, remove and cancel', () => {
    const filled = flat.match(/:class="primaryButton"/g) ?? [];
    assert.equal(filled.length, 4);
    assert.match(script, /'Pick a reason to continue\.'/);

    for (const title of [
        'Remove this document\\?',
        'Cancel the sign-in request\\?',
    ]) {
        assert.match(
            template,
            new RegExp(`title="${title}"[^>]*[\\s\\S]*?tone="danger"`),
        );
    }

    assert.match(
        flat,
        /:tone="bankDecision\.decision === 'confirm' \? 'default' : 'danger'"/,
    );
    assert.doesNotMatch(source, /OperationalConfirmDialog|<Sheet/);
});

test('the Classic template did not change', () => {
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog|V2MaskedValue/);
    assert.match(classic, /<MaskedValue/);
});
