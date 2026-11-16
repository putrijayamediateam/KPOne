import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/pages/MedicalCertificateTemplates/Index.vue',
);
const page = read(
    'resources/js/pages/MedicalCertificateTemplates/V2/Index.vue',
);
const tight = (s) => s.replace(/\s+/g, '');
const script = (s) =>
    s.split('<script setup lang="ts">')[1].split('</script>')[0];

test('V2 keeps the Classic props and the language choice', () => {
    for (const piece of [
        'language: string;',
        'revision: number;',
        'title: string;',
        'wording: string;',
        'mergeFields: string[];',
        'rendererVersion: string;',
        'productionApproved: boolean;',
        "const language = ref('en');",
        'props.revisions.find((revision) => revision.language === language.value)',
    ]) {
        assert.ok(
            tight(script(classic)).includes(tight(piece)),
            `Classic lacks ${piece}`,
        );
        assert.ok(
            tight(script(page)).includes(tight(piece)),
            `V2 lacks ${piece}`,
        );
    }
});

test('V2 keeps every Classic wording string and the paper layout words', () => {
    for (const text of [
        'MC template previews',
        'Draft wording for review. Not production-approved. No patient data, numbering, issuance or printing is available here.',
        'English',
        'Bahasa Melayu',
        'System-controlled layout and plain-text wording. No editor, uploads or price to patient.',
        'Allowlisted fields',
        'Diagnosis is not a merge field. No diagnosis is inserted automatically.',
        'Issuing branch letterhead and optional logo',
        'Preserved when a certificate is issued',
        'Date / Tarikh: [issue_date]',
        'MC: [mc_number]',
        '[doctor_name] · MMC: [mmc_number]',
        'Signature / Tandatangan: ____________________',
        'Template layout preview only. Not an issued Medical Certificate.',
        'MC layout preview',
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
        assert.ok(tight(page).includes(tight(text)), `V2 lacks: ${text}`);
    }
});

test('V2 is read only: no request, no form, no router write; the paper stays white with dark text', () => {
    assert.ok(!/fetch\(|router\.|useForm|<form|<button/.test(page));
    assert.ok(/bg-white[^"]*text-black/.test(page));
    assert.ok(tight(page).includes('role="status"'));
});
