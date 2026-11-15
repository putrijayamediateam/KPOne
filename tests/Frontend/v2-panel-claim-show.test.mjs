import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Panel claim page (PanelClaims/V2/Show.vue): the compare with Classic. Classic stays untouched; the
// Version 2 page uses a copy of its script logic, the same requests, payload keys, rules and wording.

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PanelClaims/Show.vue');
const composable = read(
    'resources/js/composables/panel-claims/usePanelClaimShow.ts',
);
const page = read('resources/js/pages/PanelClaims/V2/Show.vue');
const config = read('config/v2-pages.php');

// Whitespace, commas and parentheses are formatting (Prettier re-wraps nested code); everything else must match.
const flat = (text) => text.replace(/[\s,()]/g, '');
const classicScript = classic.slice(
    classic.indexOf('const page = usePage();'),
    classic.indexOf('</script>'),
);
const classicTemplate = classic.slice(classic.indexOf('<template>'));

test('Classic is untouched: no Version 2 imports and no composable', () => {
    assert.ok(!classic.includes('components/v2'));
    assert.ok(!classic.includes('usePanelClaimShow'));
    assert.ok(!classic.includes('panel-claims-v2'));
});

test('the composable is a verbatim copy of the Classic script', () => {
    assert.ok(
        flat(composable).includes(flat(classicScript)),
        'the Classic script (from `const page = usePage();`) is inside the composable',
    );
});

test('the same requests, payload keys and rules as Classic', () => {
    for (const text of [
        '/amount',
        '/submit',
        '/query',
        '/decision',
        '/payments',
        '/notes',
        '/remarks',
        '/documents',
        'expected_branch_id',
        'lock_version',
        'amount_sen',
        'reason',
        'confirm_over_invoice',
        'external_reference',
        'accepted_amount_sen',
        'idempotency_key',
        'received_date',
        'crypto.randomUUID()',
        'Enter a positive MYR claim amount with at most two decimals.',
        'Enter a positive MYR remittance with at most two decimals.',
    ]) {
        assert.ok(classic.includes(text), `Classic has ${text}`);
        assert.ok(composable.includes(text), `composable has ${text}`);
    }

    // The page never posts by itself: every write goes through the copied functions.
    assert.ok(!/router\.(post|patch|put|delete)/.test(page));
    assert.ok(!/fetch\(/.test(page));
});

test('the Classic wording the Version 2 page keeps', () => {
    const strings = [
        'Summary',
        'Invoice items',
        'Documents',
        'Payments',
        'Remarks',
        'Logs',
        'Billed to',
        'Patient details',
        'Member reference:',
        'Panel claim number',
        'Invoice number',
        'Created date',
        'Panel claim submission due date',
        'Received date',
        'Verification link',
        'View invoice',
        'Original subtotal (snapshot)',
        'Invoice adjustment (snapshot)',
        'Adjusted invoice total (snapshot)',
        'Claim-only adjustment (above invoice)',
        'Claimed amount',
        'Panel accepted',
        'Amount due',
        'No document added. JPG or PNG, up to 5 MB each.',
        'No payment added. Payments are recorded after the Panel accepts the claim.',
        'Copy link',
        'Copy message',
        'Download PDF',
        'Print claim pack',
        'Record portal submission',
        'Record a Panel query',
        'Record Panel decision',
        'Record Panel remittance',
        'Reason for changing the approved amount',
        'Panel portal reference (optional)',
        'Query details',
        'Decision details',
        'Date received',
        'Panel payment reference (optional)',
        'Save claim amount',
        'Save query',
        'Save Panel decision',
        'Save remarks',
        'Save note',
        'Add note',
        'recorded by mistake, not counted as received.',
        'claim-pack-error',
        'print-claim-pack',
    ];

    for (const text of strings) {
        const wanted = flat(text);
        assert.ok(
            flat(classicTemplate).includes(wanted) ||
                flat(classicScript).includes(wanted),
            `Classic has: ${text}`,
        );
        assert.ok(flat(page + composable).includes(wanted), `V2 has: ${text}`);
    }
});

test('the page is registered and the layout rules hold', () => {
    assert.match(config, /'PanelClaims\/Show'/);
    // One filled button: the next step; it is hidden below 640 px where the sticky bar carries the same action.
    assert.equal([...page.matchAll(/bg-primary(?!\/)/g)].length, 2);
    assert.match(page, /max-sm:hidden/);
    assert.match(page, /<V2StickyBar v-if="nextStep" hide-from="sm">/);
    assert.match(page, /rail-from="2xl"/);
    assert.ok(!/<select\b/.test(page));
    assert.ok(!/type="file"/.test(page));
    assert.match(page, /FileChooseButton/);
    assert.match(page, /V2MoneyInput/);
    assert.match(page, /V2DateField/);
    assert.match(page, /V2Dialog/);
    assert.match(page, /V2Stat/);
});

test('the next step and the actions follow the server flags, not the status text', () => {
    for (const flag of [
        'canSubmit',
        'canRecordQuery',
        'canRecordDecision',
        'canRecordPayment',
        'canEditAmount',
        'canEditRemarks',
    ]) {
        assert.ok(classic.includes(flag), `Classic uses ${flag}`);
        assert.ok(
            page.includes(`claim.value.${flag}`) ||
                page.includes(`claim.${flag}`),
            `V2 uses ${flag}`,
        );
    }

    assert.ok(!/status === '/.test(page));
    assert.ok(!page.includes('statusKey'));
});

test('the Version 2 wording the Lead UI/UX Designer approved', () => {
    assert.match(page, /Change claim amount only: the invoice stays the same/);
    assert.match(page, /I confirm the claim is above the invoice amount\./);
    assert.match(page, /Records the Panel's decision: /);
    assert.match(page, /will still be outstanding\./);
    assert.match(page, /That is more than the/);
    // Accepted-amount box only for Partially accepted; the other two show the amount in words.
    assert.match(page, /v-if="decision === 'partially_accepted'"/);
    assert.match(page, /confirm-disabled/);
    assert.match(page, /:max="props\.branch\.today"/);
    // Copy falls back to selectable text.
    assert.match(page, /Copying was blocked\./);
    // Classic keeps its own wording.
    assert.ok(classic.includes('Edit claim amount'));
});

test('invoice items are ten to a page, the figures show at every width, the crumb is Panel > Panel claims', () => {
    assert.match(page, /LINE_PAGE = 10/);
    assert.match(page, /<V2Pager/);
    assert.match(page, /v-for="line in pagedLines"/);
    assert.ok(!page.includes('V2DisclosureBar'));
    assert.match(page, /#rail-collapsed/);
    assert.match(page, /title: 'Panel claims', href: '\/panel-claims'/);
    assert.match(page, /usePageCrumbs/);
    // The prefix is RM: no "(MYR)" in the V2 labels.
    assert.ok(!/\(MYR/.test(page));
    // Partially accepted needs 0 < accepted < claimed, otherwise the save stays off.
    assert.match(page, /partialInvalid/);
    assert.match(
        page,
        /decisionAmountSen\.value >= claim\.value\.claimAmountSen/,
    );
    // Phone and IC are shown exactly as Classic shows them (no masking in Classic, so none here).
    assert.ok(!classic.includes('Masked'));
    assert.ok(!page.includes('V2MaskedValue'));
});

test('a Prepared claim says created on, and the submission dialog says nothing is sent', () => {
    assert.match(page, /claim\.submittedOn && claim\.status !== 'Prepared'/);
    assert.match(page, /Marks this claim as Submitted\. KPOne does not send/);
    assert.match(page, /anything to the Panel\./);
});

test('a dialog closes only after a request finished without an error, and checks what Classic required', () => {
    assert.match(
        page,
        /wasBusy && !isBusy && open\.value !== null && error\.value === ''/,
    );
    assert.match(page, /Enter the reason for changing the approved amount\./);
    assert.match(page, /Enter the query details\./);
    assert.match(page, /Enter the decision details\./);
    assert.match(
        page,
        /Tick the box to confirm the claim is above the invoice amount\./,
    );

    // Classic's `required` attributes on these fields.
    for (const label of [
        'amountReason',
        'queryNote',
        'decisionNote',
        'confirmAboveInvoice',
    ]) {
        assert.ok(classic.includes(`v-model="${label}"`));
    }
});
