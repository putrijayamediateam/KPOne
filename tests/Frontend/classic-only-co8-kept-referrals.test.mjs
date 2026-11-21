// Classic only, CO-8: the Classic half of v2-referrals, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Referrals/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');
const between = (s, from, to) => s.slice(s.indexOf(from), s.indexOf(to));

test('Classic Referrals still has its request, compose, issue, blank form and clear functions', () => {
    for (const [from, to] of [
        ['async function request(', 'async function compose('],
        ['async function compose(', 'async function prepare('],
        ['async function issue()', 'const voiding = ref'],
        ['const blank = () => ({', 'const form = reactive(blank());'],
        ['function clear() {', 'watch(() => page.props.branchContext'],
    ]) {
        // Both markers must exist, in this order, with real code between them.
        assert.ok(classic.indexOf(from) >= 0, `classic lacks ${from}`);
        assert.ok(
            classic.indexOf(to) > classic.indexOf(from),
            `classic lacks ${to} after ${from}`,
        );
        assert.ok(
            tight(between(classic, from, to)).length > 50,
            `classic chunk ${from}`,
        );
    }
});

test('Classic Referrals keeps the three requests, headers and the review-token and generation guards', () => {
    for (const piece of [
        '`${endpoint}/source`',
        '`${endpoint}/preview`,{...form}',
        'request(endpoint,{...form})',
        "'X-Requested-With':'XMLHttpRequest'",
        "cache:'no-store'",
        'signal:controller.signal',
        'ownGeneration!==generation||branchChanged.value',
        'if(JSON.stringify(form)!==reviewedInput){return;}',
        '!preview.value||!form.review_token||branchChanged.value',
        'form.idempotency_key=crypto.randomUUID();',
        'router.reload()',
    ]) {
        assert.ok(tight(classic).includes(piece), `Classic lacks ${piece}`);
    }
});

test('Classic Referrals void sends its body keys with the change reason, defaulting to Details corrected', () => {
    for (const piece of [
        "action:'void',",
        'document_id:doc.id,',
        'lock_version:doc.version,',
        'source_token:doc.voidToken,',
        'idempotency_key:form.idempotency_key,',
    ]) {
        assert.ok(tight(classic).includes(piece), `Classic lacks ${piece}`);
    }

    assert.ok(tight(classic).includes('change_reason:form.change_reason,'));
    assert.ok(tight(classic).includes("change_reason:'details_corrected',"));
});

test('Classic Referrals keeps every wording string on this clinical document screen', () => {
    for (const text of [
        'Referral letters',
        'Own referrals only',
        'New referral',
        'DRAFT EN/BM wording awaits doctor approval.',
        'Pre-signature evidence only; wet-sign paper and retain a signed duplicate. No charge.',
        'Each referral has its own chain; voiding closes that chain.',
        'First issue requires Serving, not held, before clinical checkout.',
        'Reopening does not restore first issue; the original issuer may replace or void an existing current referral.',
        'Branch changed. Reload this page; loaded content and drafts have been cleared.',
        'Access refused or source unavailable. Reload and review.',
        'Replace referral',
        'Issue referral',
        'Only SAVED source data is used. Unsaved clinical work is not copied. No diagnosis, medicine, allergy section or Encounter note is selected automatically.',
        'Reload saved source (clears draft)',
        'English',
        'Bahasa Melayu',
        'Routine',
        'Urgent',
        '(maximum 20)',
        'No saved active records.',
        'Allergy information',
        'Saved status:',
        'Review before selecting; unknown is never treated as none.',
        'Include reviewed allergy status',
        "I explicitly acknowledge the patient's agreement to disclosure to the stated recipient.",
        "A tick does not prove the clinic's written-consent or guardian procedure was followed.",
        'Correction category',
        'Details corrected',
        'Entered in error',
        'Patient request',
        'Preview selected content',
        'Cancel',
        'Preview the exact saved selections and wording before issue.',
        'Captured clinic logo',
        'DRAFT ·',
        'PREVIEW — not issued · Page',
        'Issue replacement',
        'No referrals issued.',
        'Open COPY / print',
        'Replace',
        'Void',
        'Previous',
        'Next',
        'Confirm void',
        "This closes this referral's chain. The retained evidence cannot be deleted.",
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
    }
});

test('Classic Referrals labels the text fields required or optional and keeps its props', () => {
    assert.ok(
        tight(classic).includes(tight("'(required)'")) &&
            tight(classic).includes(tight("'(optional)'")),
    );

    for (const piece of [
        'visitNumber: string;',
        'branchId: number;',
        'timezone: string;',
        'canIssue: boolean;',
        'canFirstIssue: boolean;',
        'canPrint: boolean;',
        'documents: Document[];',
        'hasMore: boolean;',
        'voidToken: string;',
    ]) {
        assert.ok(
            tight(classic).includes(tight(piece)),
            `Classic lacks ${piece}`,
        );
    }
});
