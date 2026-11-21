// Classic only, CO-8: the Classic half of v2-medical-certificates, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/MedicalCertificates/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');

test('Classic Medical certificates keeps its props, form payload keys and request', () => {
    for (const piece of [
        'visitNumber: string;',
        'canFirstIssue: boolean;',
        'branchId: number;',
        'branchTimezone: string;',
        'lockVersion: number;',
        'canIssue: boolean;',
        'credentialVerified: boolean;',
        'canPrint: boolean;',
        'today: string;',
        'documents: Document[];',
        'hasMore: boolean;',
        'expected_branch_id: props.certificates.branchId,',
        'lock_version: props.certificates.lockVersion,',
        'idempotency_key: crypto.randomUUID(),',
        "action: 'issue',",
        'document_id: null as number | null,',
        "language: 'en',",
        'start_date: props.certificates.today,',
        'end_date: props.certificates.today,',
        "reason: '',",
        'reason_consent: false,',
        "change_reason: 'dates_corrected',",
        'form.post(baseUrl.value, {',
        'preserveScroll: true,',
        'if (form.processing || branchChanged.value) {',
        'form.idempotency_key = crypto.randomUUID();',
    ]) {
        assert.ok(
            tight(classic).includes(tight(piece)),
            `Classic lacks ${piece}`,
        );
    }
});

test('Classic Medical certificates keeps every wording string on this clinical document screen', () => {
    for (const text of [
        'Medical certificates',
        'Issue MC',
        'Draft wording — awaiting doctor approval.',
        'Fixed EN/BM revision 2.',
        'Issuance retains pre-signature content only.',
        'Wet-sign the paper and retain a signed duplicate.',
        'No signed original or final PDF is archived.',
        'Verified professional name and MMC registration are required.',
        'MC evidence saved. Review the document before printing.',
        'Branch changed. Reload this visit before viewing MC history.',
        'No MC has been issued for this visit. First issue requires Serving, not held, before clinical checkout.',
        'One first MC may be issued per consultation.',
        'Voiding it closes this MC chain; another MC cannot be issued for this consultation.',
        'View / print copy',
        'Replace',
        'Void',
        'Refresh history',
        'Void MC',
        'Replace MC',
        'Only the original issuer may correct or void. Old content stays immutable. Wording remains draft.',
        'English · revision 2',
        'Bahasa Melayu · revision 2',
        'inclusive day(s) · maximum 30.',
        'Examination and issue dates are recorded by the server.',
        'Reason to print (optional, 200 characters)',
        'No diagnosis is copied automatically. Leave blank unless the patient explicitly consents.',
        'The patient explicitly consents to this reason appearing on the MC.',
        'Dates corrected',
        'Details corrected',
        'Entered in error',
        'Patient request',
        'This permanently voids the selected MC. Its original snapshot stays readable and every copy is marked VOID.',
        'Confirm void',
        'Save pre-signature MC',
        'Reload history',
        'MC only · no charge',
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
    }
});

test('Classic Medical certificates keeps the chain rule and the replace sheet default category', () => {
    for (const classicWords of [
        'Voiding it closes this MC chain; another MC cannot be issued for this consultation.',
        'copy is marked VOID.',
        'One first MC may be issued per consultation. The original issuer may replace the current MC.',
        'First issue requires Serving, not held, before clinical checkout.',
        "change_reason: 'dates_corrected',",
    ]) {
        assert.ok(
            tight(classic).includes(tight(classicWords)),
            `Classic lacks ${classicWords}`,
        );
    }
});
