import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Attendance/Index.vue');
const page = read('resources/js/pages/Attendance/V2/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');
const flat = (s) => s.replace(/\s+/g, ' ');
const between = (s, from, to) => s.slice(s.indexOf(from), s.indexOf(to));

test('V2 keeps Classic fetch, token and abort logic exactly (request, compose, issue, clear)', () => {
    for (const [from, to] of [
        ['async function request(', 'async function compose('],
        ['async function compose(', 'async function prepare('],
        ['async function issue()', 'const voiding = ref'],
        ['const blank = () => ({', 'const form = reactive(blank());'],
        ['function clear() {', 'watch(() => page.props.branchContext'],
    ]) {
        const a = tight(between(classic, from, to));
        assert.ok(a.length > 50, `classic chunk ${from}`);
        // `clear()` gains one V2-only line (stale flag); every other chunk is identical.
        const b = tight(page);
        assert.ok(
            from === 'function clear() {'
                ? b.includes(
                      a.replace(
                          'preview.value=null;',
                          'preview.value=null;stale.value=false;',
                      ),
                  )
                : b.includes(a),
            `V2 differs from Classic in: ${from}`,
        );
    }
});

test('V2 keeps the three requests, headers and the review-token and generation guards', () => {
    const p = tight(page);

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
        assert.ok(p.includes(piece), `V2 lacks ${piece}`);
    }
});

test('V2 void sends Classic body keys, but starts with no category (deliberately stricter)', () => {
    const p = tight(page);

    for (const piece of [
        "action:'void',",
        'document_id:doc.id,',
        'lock_version:doc.version,',
        'source_token:doc.voidToken,',
        'idempotency_key:form.idempotency_key,',
    ]) {
        assert.ok(tight(classic).includes(piece));
        assert.ok(p.includes(piece), `V2 lacks ${piece}`);
    }

    assert.ok(tight(classic).includes('change_reason:form.change_reason,'));
    assert.ok(p.includes('change_reason:voidReason.value,'));
    assert.ok(p.includes("constvoidReason=ref('');"));
    assert.ok(p.includes("if(value){voidReason.value='';}"));
    assert.ok(
        flat(page).includes(
            'confirm-disabled-reason="Pick a reason to continue."',
        ),
    );
    // Classic's form default stays "Details corrected" (replace keeps it for parity).
    assert.ok(tight(classic).includes("change_reason:'details_corrected',"));
    assert.ok(p.includes("change_reason:'details_corrected',"));
});

test('V2 keeps every Classic wording string on this clinical document screen', () => {
    for (const text of [
        'Attendance confirmation',
        'Own attendance documents only',
        'New attendance confirmation',
        'DRAFT EN/BM wording awaits doctor approval.',
        'Pre-signature evidence only; wet-sign paper and retain a signed duplicate. No charge.',
        'Each attendance has its own chain; voiding closes that chain.',
        'First issue requires Serving, not held, before clinical checkout.',
        'Reopening does not restore first issue; the original issuer may replace or void an existing current attendance.',
        'Branch changed. Reload this page; loaded content and drafts have been cleared.',
        'Access refused or source unavailable. Reload and review.',
        'Replace attendance',
        'Issue attendance',
        'Only SAVED source data is used. Unsaved clinical work is not copied. No medical details are included. This confirms attendance on a date only; it is not a medical certificate.',
        'Reload saved source (clears draft)',
        'English',
        'Bahasa Melayu',
        'Intended recipient (optional, maximum 200 characters)',
        'Do not enter a diagnosis, symptoms or medical details here.',
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
        'Attendance date:',
        'PREVIEW — not issued · Page',
        'Issue replacement',
        'No attendance documents issued.',
        'Open COPY / print',
        'Replace',
        'Void',
        'Previous',
        'Next',
        'Confirm void',
        "This closes this attendance's chain. The retained evidence cannot be deleted.",
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
        assert.ok(tight(page).includes(tight(text)), `V2 lacks: ${text}`);
    }
});

test('V2 print link keeps its href, target and rel; the preview paper stays white; no second filled action', () => {
    const p = tight(page);

    assert.ok(p.includes(tight(':href="`${endpoint}/${doc.id}`"')));
    assert.ok(p.includes('target="_blank"') && p.includes('rel="noopener"'));
    assert.ok(
        /bg-white[^"]*text-zinc-900/.test(page),
        'the paper is white with dark text in every theme',
    );
    // Exactly one filled class per state: header New attendance confirmation (not editing), Preview (no preview yet), Issue (preview + token).
    assert.ok(p.includes('editing?outlineButton:primaryButton'));
    assert.ok(p.includes('preview?outlineButton:primaryButton'));
    assert.ok(
        p.includes('preview&&form.review_token?primaryButton:outlineButton'),
    );
    assert.equal((page.match(/:class="primaryButton"/g) ?? []).length, 0);
});

test('V2 form rules: shared Checkbox, reasons in words, recipient sentence, stale preview, dirty guard', () => {
    const p = flat(page);

    assert.ok(p.includes('v-model="form.consent"'));
    assert.ok(!/v-model:checked/.test(page));

    for (const reason of [
        "Acknowledge the patient's agreement to continue.",
        'Preview the selected content to continue.',
        'Your selection changed. Preview again before issuing.',
    ]) {
        assert.ok(p.includes(reason), `V2 lacks: ${reason}`);
    }

    // The consent row shows only once a recipient is typed, as in Classic.
    assert.ok(p.includes('v-if="form.recipient.trim()"'));
    assert.ok(p.includes('Issues a replacement attendance confirmation'));
    assert.ok(p.includes('<V2LeaveGuard ref="guard" :dirty="dirty" />'));
    assert.ok(
        p.includes('ask(() => compose())') &&
            p.includes('ask(clear)') &&
            p.includes('ask(() => compose(doc))'),
    );
    assert.ok(p.includes('stale.value = preview.value !== null;'));
    assert.ok(p.includes(':disabled="busy || !!missing"'));
});

test('V2 adds no request and no router.post, and keeps the Classic props', () => {
    assert.ok(!/router\.(post|put|patch|delete)|useForm/.test(page));

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
        assert.ok(tight(classic).includes(tight(piece)));
        assert.ok(tight(page).includes(tight(piece)));
    }
});

test('V2 history actions stack on phones, each a full-width 44 px target with its words on one line', () => {
    const t = tight(page);

    assert.ok(t.includes('flexflex-wrapgap-2max-sm:w-fullmax-sm:flex-col'));
    assert.equal(
        (t.match(/'whitespace-nowrapmax-sm:w-full'/g) ?? []).length,
        3,
    );
});
