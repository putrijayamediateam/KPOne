import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/components/billing/BillingWorkspace.vue');
const copy = read('resources/js/composables/billing/useBillingWorkspaceV2.ts');

// The Classic workspace logic: from `const responsibilityTotals` to the end of the script.
const classicLogic = classic.slice(
    classic.indexOf('const responsibilityTotals'),
    classic.indexOf('</script>'),
);
// The Version 2 copy: the composable body, from the same line to its return.
const copyLogic = copy.slice(
    copy.indexOf('const responsibilityTotals'),
    copy.indexOf('    return {'),
);
// Formatting differs only by indentation and Prettier line breaks: compare without whitespace and trailing commas.
const normalise = (code) =>
    code.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('BV2-0: the Version 2 workspace logic is the Classic logic, unchanged', () => {
    assert.ok(classicLogic.length > 5000, 'found the Classic logic');
    assert.equal(normalise(copyLogic), normalise(classicLogic));
});

// Every string and template literal, in order (catches a change inside a message or URL that whitespace-stripping hides).
const literals = (code) =>
    [
        ...code.matchAll(
            /'(?:[^'\\\n]|\\.)*'|`(?:[^`\\]|\\.)*`|"(?:[^"\\\n]|\\.)*"/g,
        ),
    ].map((m) => m[0]);

test('the copy keeps every string and template literal of the Classic logic, in order', () => {
    const classicLiterals = literals(classicLogic);
    assert.ok(classicLiterals.length > 50, 'found the Classic literals');
    assert.deepEqual(literals(copyLogic), classicLiterals);
});

test('the composable holds only the copied logic and a shorthand return', () => {
    const signature = copy.slice(
        copy.indexOf('export function useBillingWorkspaceV2('),
        copy.indexOf('const responsibilityTotals'),
    );
    assert.equal(
        signature.replace(/\s+/g, ''),
        'exportfunctionuseBillingWorkspaceV2(props:{readonlybilling:BillingPage;readonlymanage:boolean;}){',
    );
    const tail = copy.slice(copy.indexOf('    return {'));
    assert.match(tail, /^ {4}return \{\n(?: {8}[A-Za-z]+,\n)+ {4}\};\n\}\n$/);
    assert.equal(
        copy
            .slice(0, copy.indexOf('export function'))
            .match(
                /^(?!import|\s*\*|\/\*\*| \*\/|\s*$|} from|    [A-Za-z]+,$).+$/gm,
            ),
        null,
        'only imports and the doc comment before the function',
    );
});

test('Classic does not use the Version 2 copy', () => {
    assert.ok(!classic.includes('useBillingWorkspaceV2'));
    assert.ok(!classic.includes('composables/billing'));
});

test('both copies keep every money route, key, lock version and guard', () => {
    const fragments = [
        'expected_branch_id: page.props.branchContext?.active?.id ?? 0,',
        'lock_version: props.billing.invoice?.lockVersion ?? null,',
        "return_to: props.manage ? 'payments' : 'billing',",
        'if (busy.value || contextCleared.value) {',
        '`${invoiceBase.value}/payments`',
        'amount_sen: sen,',
        'method: form.method,',
        'reference: form.reference || null,',
        'idempotency_key: paymentKey,',
        'let paymentKey = crypto.randomUUID();',
        'paymentKey = crypto.randomUUID();',
        '`${invoiceBase.value}/responsibility/${kind}`',
        'due_date: form.due_date || null,',
        'panel_id: form.panel_id || null,',
        'member_reference: form.member_reference || null,',
        '`${invoiceBase.value}/responsibility/${kind}/${proposal.publicId}/approve`',
        'proposal_lock_version: proposal.lockVersion',
        '`${invoiceBase.value}/payments/${receipt.publicId}/reverse`',
        'payment_lock_version: receipt.lockVersion,',
        'recording_error_only: form.recording_error_only,',
        "event.detail.visit.url.pathname === '/branch-context'",
        "['Billing/Show', 'Billing/Payments'].includes(",
        '!props.billing.can.pay',
        '!props.billing.can.panelPropose',
        '!props.billing.can.deferPropose',
        "receipt.status !== 'posted'",
    ];

    for (const [name, source] of [
        ['Classic', classicLogic],
        ['Version 2 copy', copyLogic],
    ]) {
        for (const fragment of fragments) {
            assert.ok(source.includes(fragment), `${name} keeps: ${fragment}`);
        }
    }
});
