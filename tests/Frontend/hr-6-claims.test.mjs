import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-6 staff claims (owner 2026-11-06: "approve the HR-6 note with the defaults"; HR-PLAN 13.2 and 13.6, Daniel's
// early look folded in).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    page: 'resources/js/pages/HR/Claims.vue',
    sheet: 'resources/js/components/hr/claims/ClaimSheet.vue',
    receipts: 'resources/js/components/hr/claims/ReceiptList.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const types = read('resources/js/components/hr/claims/types.ts');
const template = (key) => parse(source[key]).descriptor.template.content;
const script = (key) => parse(source[key]).descriptor.scriptSetup.content;

test('the new page and components compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('Approved and Paid are success, told apart by their words, and nothing is red', () => {
    assert.match(
        types,
        /status === 'approved' \|\| status === 'paid' \? 'success' : 'neutral'/,
    );
    assert.match(types, /`Paid \$\{row\.paid\.paidOn\}`/);
    assert.match(types, /`Ref\\u00a0••••\\u00a0\$\{row\.paid\.last4\}`/);

    for (const key of Object.keys(files)) {
        assert.doesNotMatch(
            source[key],
            /tone="danger"|'danger'|text-red|bg-red/,
            files[key],
        );
    }

    assert.match(
        template('page'),
        /`Payment reversed: \$\{row\.reversed\.reason\}`[\s\S]{0,40}tone="neutral"/,
    );
});

test('the New claim sheet: RM prefix with decimal input, help text, receipt rules and one submit', () => {
    const sheet = template('sheet');
    assert.match(sheet, /aria-hidden="true"\s*>RM<\/span/);
    assert.match(sheet, /inputmode="decimal"/);
    assert.match(sheet, /In RM\. For example 45\.60/);
    assert.match(sheet, /Do not write medical details here\./);
    assert.match(
        sheet,
        /Up to \{\{ maxReceipts \}\} receipts\. JPG, PNG or PDF, 5 MB\s+each\./,
    );
    assert.match(sheet, /'Uploading…'/);
    assert.match(script('sheet'), /forceFormData: true/);
    assert.match(script('sheet'), /copy_from: props\.from\.id/);

    for (const field of ['category_id', 'spent_on', 'amount', 'purpose']) {
        assert.match(sheet, new RegExp(`errors\\.${field}`), field);
    }
});

test('Approvals: the claimant never gets Approve or Reject, warnings are worded chips, receipts have alt text and PDFs a tile', () => {
    const page = template('page');
    assert.match(
        page,
        /v-if="row\.refusal"[\s\S]{0,200}\{\{ row\.refusal \}\}/,
    );
    assert.match(
        page,
        /<div v-else class="flex flex-wrap gap-2">[\s\S]{0,200}variant="outline"[\s\S]{0,200}askDecision\(row, 'approve'\)/,
    );
    assert.match(page, /tone="warning"/);
    const receipts = template('receipts');
    assert.match(receipts, /v-if="thumbnails && r\.kind !== 'pdf'"/);
    assert.match(receipts, /:alt="r\.label"/);
    assert.match(receipts, /<FileText/);
    assert.match(page, /Send a new claim/);
});

test('Pay: a table from 1024 px and cards below, outline actions, and every write sends an idempotency key', () => {
    const page = template('page');
    assert.match(page, /max-lg:hidden/);
    assert.match(page, /lg:hidden/);
    assert.match(page, /KPOne pays nothing\./);
    assert.match(page, /Not linked to daily cash closing\./);
    assert.match(page, /On screen only: there is no download\./);
    const s = script('page');
    assert.equal((s.match(/idempotency_key:/g) ?? []).length, 6);
    assert.equal((s.match(/expected_lock_version:/g) ?? []).length, 5);
    assert.match(script('sheet'), /idempotency_key: form\.key/);
});
