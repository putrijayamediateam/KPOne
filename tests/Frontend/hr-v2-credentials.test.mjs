import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Credentials: the Version 2 page shares two composables with Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Credentials.vue');
const classic = read('resources/js/pages/HR/Credentials.vue');
const classicSheet = read(
    'resources/js/components/hr/credentials/RecordSheet.vue',
);
const page = read('resources/js/composables/hr/useCredentialsPage.ts');
const sheet = read('resources/js/composables/hr/useCredentialRecordSheet.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const tight = (s) => s.replace(/=" /g, '="').replace(/ "/g, '"');

test('the V2 page compiles, is registered, and shares the composables with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-credentials',
            source: template,
            filename: 'Credentials.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Credentials',/);
    assert.match(script, /useCredentialsPage\(props\)/);
    assert.match(script, /useCredentialRecordSheet\(/);
    assert.match(classic, /useCredentialsPage\(props\)/);
    assert.match(classicSheet, /useCredentialRecordSheet\(props/);
    // Every request comes from the composables: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(
        script,
        /fetch\(|axios|useForm|localStorage|sessionStorage/,
    );
    assert.match(page, /\/hr\/credentials\/records\//);
    assert.match(page, /'\/hr\/credentials\/types'/);
    assert.match(sheet, /forceFormData/);
});

test('a reference stays masked and a certificate opens only through its audited link', () => {
    const t = tight(template);
    assert.match(t, /<MaskedValue/);
    assert.match(t, /field="reference"/);
    assert.match(
        t,
        /\/hr\/credentials\/records\/\$\{card\.inForce\.id\}\/show-reference/,
    );
    assert.match(
        t,
        /\/hr\/records\/\$\{credentials\.my\.workerId\}\/documents\//,
    );
    // No raw reference value and no thumbnail or image of a certificate.
    assert.doesNotMatch(t, /<img|inForce\.reference\b|waiting\.reference\b/);
});

test('V2 rules: dialogs for confirmations, red only for reject and archive, reasons start empty', () => {
    assert.match(template, /<V2Dialog/);
    assert.match(
        tight(template),
        /:tone="decision\.kind === 'accept' \? 'default' : 'danger'"/,
    );
    assert.match(template, /tone="danger"/);
    assert.doesNotMatch(template, /window\.confirm|confirm\(/);
    // Expiring and Expired are warnings, never red.
    assert.doesNotMatch(script, /destructive'?\)?\s*;?\s*$|tone\(.*danger/m);
    assert.match(
        read('resources/js/components/hr/credentials/types.ts'),
        /statusTone/,
    );
});

test('the Classic templates did not change', () => {
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
    assert.doesNotMatch(classicSheet, /V2Dialog/);
});
