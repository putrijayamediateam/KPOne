import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/MedicalCertificates/Index.vue');
const page = read('resources/js/pages/MedicalCertificates/V2/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');
const flat = (s) => s.replace(/\s+/g, ' ');

test('V2 keeps the Classic props, form payload keys and request', () => {
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
        assert.ok(tight(page).includes(tight(piece)), `V2 lacks ${piece}`);
    }
});

test('V2 keeps every Classic wording string on this clinical document screen', () => {
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
        assert.ok(tight(page).includes(tight(text)), `V2 lacks: ${text}`);
    }
});

test('V2 print link keeps its href, target and rel, and there is no second filled action', () => {
    const p = tight(page);

    assert.ok(p.includes(tight(':href="`${baseUrl}/${document.id}?print=1`"')));
    assert.ok(p.includes('target="_blank"') && p.includes('rel="noopener"'));
    assert.equal((page.match(/:class="primaryButton"/g) ?? []).length, 1);
    assert.ok(
        !/variant="destructive"|bg-destructive/.test(
            page.split('<V2Dialog')[0],
        ),
        'Replace and Void stay outline',
    );
});

test('V2 dialog: guard, busy, disabled reason, footer note on screen only, void is the danger button', () => {
    const p = flat(page);

    assert.ok(p.includes(':dirty="dirty"'));
    assert.ok(p.includes(':busy="form.processing"'));
    assert.ok(p.includes(':confirm-disabled-reason="missing"'));
    assert.ok(
        /:tone="form\.action === 'void' \? 'danger' : 'default'"/.test(p),
    );
    assert.ok(p.includes('#footer-note'));
    assert.ok(
        p.includes('It is a draft until the doctor approves the wording.'),
    );
    assert.ok(!classic.includes('It is a draft until the doctor approves'));
    assert.ok(
        p.includes('V2DateField') &&
            p.includes(':max="certificates.today"') &&
            p.includes(':min="form.start_date"'),
    );
    assert.ok(p.includes('${form.reason.length}/200 characters'));
    assert.ok(
        !/<style|@media print|print:/.test(page),
        'nothing here is printed',
    );
});

test('V2 adds no request and the history stays the Classic list', () => {
    assert.ok(!/router\.(post|put|patch|delete)|fetch\(/.test(page));
    assert.ok(
        /<article\s+v-for="document in certificates\.documents"/.test(page),
    );
    assert.ok(tight(page).includes('whitespace-nowrap"'));
    assert.ok(tight(page).includes('{{document.number}}'));
});

test('V2 void and replace sheets name the MC, reuse the Classic chain rule and the disabled reasons use Classic words', () => {
    const p = flat(page);

    for (const classicWords of [
        'Voiding it closes this MC chain; another MC cannot be issued for this consultation.',
        'copy is marked VOID.',
        'One first MC may be issued per consultation. The original issuer may replace the current MC.',
        'First issue requires Serving, not held, before clinical checkout.',
    ]) {
        assert.ok(
            tight(classic).includes(tight(classicWords)),
            `Classic lacks ${classicWords}`,
        );
        assert.ok(
            tight(page).includes(tight(classicWords)),
            `V2 lacks ${classicWords}`,
        );
    }

    assert.ok(p.includes('Voids ${selected.value.number}.'));
    assert.ok(p.includes('Replaces ${selected.value.number} with an MC'));
    assert.ok(/:size="form\.action === 'void' \? 'default' : 'tall'"/.test(p));
    // Classic starts the change category on "Dates corrected" too, so V2 keeps that default for parity.
    assert.ok(
        tight(classic).includes(tight("change_reason: 'dates_corrected',")),
    );
    assert.ok(tight(page).includes(tight("change_reason: 'dates_corrected',")));
});

test('V2 hides the day count until both dates are valid', () => {
    const p = tight(page);

    assert.ok(/v-if="datesValid"[^>]*>\{\{days\}\}inclusiveday\(s\)/.test(p));
    assert.ok(p.includes('!startInvalid.value&&!endInvalid.value'));
});
