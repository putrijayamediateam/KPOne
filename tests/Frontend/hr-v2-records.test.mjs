import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 My HR details and Staff files: the Version 2 page shares composables with Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Records.vue');
const classic = read('resources/js/pages/HR/Records.vue');
const page = read('resources/js/composables/hr/useRecordsPage.ts');
const dir = 'resources/js/components/hr/records/';
const v2Parts = {
    correction: read(`${dir}V2CorrectionDialog.vue`),
    export: read(`${dir}V2OutsideExportCard.vue`),
    docs: read(`${dir}V2DocumentList.vue`),
    own: read(`${dir}V2OwnValue.vue`),
};
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template.replace(/\s+/g, ' ');

test('the V2 page and parts compile, are registered, and share the composables with Classic', () => {
    for (const [key, text] of Object.entries({ page: source, ...v2Parts })) {
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

    assert.match(read('config/v2-pages.php'), /'HR\/Records',/);
    assert.match(script, /useRecordsPage\(props\)/);
    assert.match(classic, /useRecordsPage\(props\)/);
    assert.match(v2Parts.correction, /useCorrectionSheet\(props, open\)/);
    assert.match(v2Parts.export, /useOutsideExport\(\)/);

    // Every request comes from the composables: the V2 page and parts send none of their own.
    for (const text of [script, ...Object.values(v2Parts)]) {
        assert.doesNotMatch(text, /router\.(get|post|put|patch|delete)\(/);
        assert.doesNotMatch(
            text,
            /fetch\(|axios|useForm|localStorage|sessionStorage|crypto\.randomUUID/,
        );
    }

    for (const url of [
        '/hr/records/my/contact',
        '/hr/records/my/corrections/${c.id}/withdraw',
        '/hr/records/settings/retention',
        '/hr/records/settings/checklist-items',
        '/hr/records/files',
        '/hr/records/locums',
    ]) {
        assert.ok(page.includes(url), url);
    }
});

test("sensitive values: your own stay hidden until Show and are never fetched; nobody else's is on this page", () => {
    assert.match(flat, /<V2OwnValue label="Identity number"/);
    assert.match(flat, /<V2OwnValue label="Account number"/);
    assert.doesNotMatch(v2Parts.own, /fetch\(|router\.|axios/);
    assert.match(v2Parts.own, /visibilitychange/);
    assert.match(v2Parts.own, /v-else/);
    // A document opens only through its audited link when pressed; no thumbnail or image is shown.
    assert.match(
        v2Parts.docs,
        /`\/hr\/records\/\$\{workerId\}\/documents\/\$\{d\.id\}`/,
    );
    assert.doesNotMatch(v2Parts.docs, /<img|:src=/);
    // The staff list shows no identity number, bank detail or contact value.
    assert.doesNotMatch(flat, /\.identity\.number\b(?!\.slice)/);
});

test('V2 rules: one filled action, reasons start empty, red for withdraw, dialogs not sheets', () => {
    const filled = flat.match(/:class="primaryButton"/g) ?? [];
    assert.equal(filled.length, 1);
    assert.match(flat, /data-test="open-file"/);
    assert.match(
        template,
        /title="Withdraw this correction\?"[^>]*confirm-label="Withdraw"[^>]*tone="danger"/s,
    );
    assert.match(v2Parts.correction, /'Pick a reason to continue\.'/);
    assert.doesNotMatch(source, /OperationalConfirmDialog|<Sheet/);
});

test('the Classic template did not change', () => {
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
    assert.match(classic, /<OwnValue/);
});
