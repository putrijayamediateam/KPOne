// Classic only, CO-8: the Classic half of v2-mc-templates, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/pages/MedicalCertificateTemplates/Index.vue',
);
const tight = (s) => s.replace(/\s+/g, '');
const script = (s) =>
    s.split('<script setup lang="ts">')[1].split('</script>')[0];

test('Classic MC template previews keep the props and the language choice', () => {
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
    }
});

test('Classic MC template previews keep every wording string and the paper layout words', () => {
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
    }
});
