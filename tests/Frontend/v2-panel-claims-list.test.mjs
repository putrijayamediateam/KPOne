import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// Version 2 Panel Claims list (PanelClaims/V2/Index.vue): the compare with Classic. Classic stays untouched; the
// Version 2 page uses a copy of its script logic, the same props, routes, rules and wording.

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PanelClaims/Index.vue');
const composable = read(
    'resources/js/composables/panel-claims/usePanelClaimsList.ts',
);
const page = read('resources/js/pages/PanelClaims/V2/Index.vue');
const lib = read('resources/js/lib/panel-claims-v2.ts');
const selection = read(
    'resources/js/components/panel-v2/PanelSelectionSummary.vue',
);
const bar = read('resources/js/components/v2/V2SelectionBar.vue');
const filters = read(
    'resources/js/components/panel-v2/PanelClaimFilterFields.vue',
);
const summary = read('resources/js/components/panel-v2/PanelSummaryBlock.vue');
const config = read('config/v2-pages.php');

// Whitespace, commas and parentheses are formatting (Prettier re-wraps nested code); everything else must match.
const flat = (text) => text.replace(/[\s,()]/g, '');
const classicScript = classic.slice(
    classic.indexOf('const props = defineProps<Props>();') +
        'const props = defineProps<Props>();'.length,
    classic.indexOf('</script>'),
);

// Values made inside the vm context have another prototype: compare their JSON.
const plain = (value) => JSON.parse(JSON.stringify(value));

const compile = (source) => {
    const js = ts.transpileModule(source, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const names = [...js.matchAll(/export const (\w+)/g)].map((m) => m[1]);
    const exports = {};
    runInNewContext(
        `${js.replace(/^import .*$/gm, '').replace(/export const /g, 'const ')}
         Object.assign(exports, { ${names.join(', ')} });`,
        { exports },
    );

    return exports;
};

test('Classic is untouched: no Version 2 imports and no composable', () => {
    assert.ok(!classic.includes('panel-v2'));
    assert.ok(!classic.includes('components/v2'));
    assert.ok(!classic.includes('usePanelClaimsList'));
    assert.ok(!classic.includes('panel-claims-v2'));
});

test('the Version 2 composable carries Classic script logic verbatim', () => {
    // Everything after the props line: filters, query, bulk and pack rules, limits, idempotency keys.
    assert.ok(
        flat(composable).includes(
            flat(classicScript).replace(/^const/, 'const'),
        ),
        'the composable body differs from the Classic script',
    );
    assert.match(composable, /const bulkMax = 20;/);
    assert.match(composable, /const packMax = 50;/);
    assert.match(composable, /crypto\.randomUUID\(\)/);
});

test('same routes, query keys and request payloads as Classic', () => {
    for (const route of [
        "router.get('/panel-claims'",
        "'/panel-claims/bulk'",
        "'/panel-claims/pack'",
        '/panel-claims/allocations/',
    ]) {
        assert.ok(classic.includes(route), `Classic has ${route}`);
        assert.ok(composable.includes(route), `composable has ${route}`);
    }

    for (const key of [
        'claim_no',
        'amount_min',
        'amount_max',
        'invoice_no',
        'visit_type',
        'visit_from',
        'due_to',
        'diagnosis',
        'treatment',
        'expected_branch_id',
        'allocation_lock_version',
        'invoice_lock_version',
        'idempotency_key',
    ]) {
        assert.ok(classic.includes(key), `Classic has ${key}`);
        assert.ok(composable.includes(key), `composable has ${key}`);
    }

    for (const link of ['/panel-claims/follow-up', '/panel-remittances']) {
        assert.ok(classic.includes(link), `Classic links ${link}`);
        assert.ok(page.includes(link), `V2 links ${link}`);
    }
});

test('the Classic wording the Version 2 page keeps', () => {
    const strings = [
        'Panel Claims',
        'Create claims for manual submission and track Panel decisions and remittances.',
        'Follow-up list',
        'Record Panel payment',
        'Claim status',
        'Visit type',
        'Invoice number',
        'Doctor',
        'Diagnosis',
        'Treatment',
        'Claim number',
        'Amount (RM)',
        'Submitted date',
        'Remarks (optional)',
        'The same remark goes on every claim',
        'View invoice',
        'Create claim',
        'View claim',
        'Submit claim (manual)',
        'Clear selection',
        'Print pack',
        'No overdue invoices',
        'No pending invoices',
        'No claims match',
        'Select all ${selectAllPanel} invoices on this page',
        'Filter by one Panel to select all invoices on this page',
        'Select all ${packAllPanel} claims on this page',
        'Filter by one Panel to select all claims on this page',
        'Select invoice ${row.invoiceNumber} for a bulk claim',
        'Select claim ${row.claimNo} for a claim pack',
        'Actions for claim ${row.claimNo}',
        'Only invoices of ${selectedPanel.value} can be added to this batch',
        'Only claims of ${packPanel.value} can be added to this pack',
        'the most for one batch. Create these claims first, then select more.',
        'the most for one pack. Print these first, then select more.',
        'Create claim opens',
        'Prints a cover page and one A4 page per claim',
        'pack-panel-rule',
        'bulk-claim-bar',
        'claim-pack-bar',
        'claim-pack-error',
    ];
    const v2 = flat(
        [page, lib, selection, filters, composable, summary].join('\n'),
    );

    for (const text of strings) {
        assert.ok(
            flat(classic).includes(flat(text.split('${')[0])),
            `Classic has: ${text}`,
        );
        assert.ok(v2.includes(flat(text.split('${')[0])), `V2 has: ${text}`);
    }

    for (const label of ['Submissions', 'Payouts']) {
        assert.ok(classic.includes(`aria-label="${label}"`));
        assert.ok(summary.includes(`aria-label="${label}"`));
    }

    for (const tab of [
        'All',
        'Pending',
        'Overdue',
        'Submitted',
        'Approved',
        'Rejected',
        'Received',
    ]) {
        assert.ok(composable.includes(`'${tab}'`), `tab ${tab}`);
    }
});

test('claim status chips and the row menus', () => {
    const api = compile(lib);
    assert.equal(api.claimChipTone('Prepared'), 'neutral');
    assert.equal(api.claimChipTone('Submitted'), 'info');
    assert.equal(api.claimChipTone('Panel query'), 'warning');
    assert.equal(api.claimChipTone('Accepted'), 'success');
    assert.equal(api.claimChipTone('Partially accepted'), 'warning');
    assert.equal(api.claimChipTone('Rejected'), 'danger');
    assert.equal(api.claimChipTone('Partially paid'), 'warning');
    assert.equal(api.claimChipTone('Paid'), 'success');
    assert.equal(api.claimChipTone('Something else'), 'neutral');
    assert.deepEqual(plain(api.invoiceChip(true)), {
        tone: 'danger',
        label: 'Overdue',
    });
    assert.deepEqual(plain(api.invoiceChip(false)), {
        tone: 'warning',
        label: 'Pending',
    });

    const invoice = api.invoiceMenuGroups('/i/1');
    // Create claim is an outline button on the row, not a menu item; a one-group menu has no group heading.
    assert.deepEqual(plain(invoice[0].items.map((item) => item.label)), [
        'View invoice',
    ]);
    assert.equal(invoice[0].label, '');
    assert.equal(api.claimMenuGroups('/c/1', true)[0].label, '');
    assert.equal(invoice[0].items[0].href, '/i/1');

    // "Submit claim (manual)" is left out, not disabled, as in Classic.
    assert.deepEqual(
        plain(api.claimMenuGroups('/c/1', false)[0].items.map((i) => i.label)),
        ['View claim'],
    );
    assert.deepEqual(
        plain(api.claimMenuGroups('/c/1', true)[0].items.map((i) => i.label)),
        ['View claim', 'Submit claim (manual)'],
    );
    assert.ok(
        classic.includes("status === 'Prepared' || status === 'Panel query'"),
    );
    assert.ok(
        composable.includes(
            "status === 'Prepared' || status === 'Panel query'",
        ),
    );
    assert.ok(
        [...lib.matchAll(/allowed: true/g)].length >= 3 &&
            !lib.includes('allowed: false'),
    );
});

test('the page is registered and the layout rules hold', () => {
    assert.match(config, /'PanelClaims\/Index'/);
    // One filled button at most: only the selection summary has one, and only when something is ticked.
    assert.ok(!/bg-primary(?!\/)/.test(page), 'no filled button in the page');
    assert.match(selection, /bg-primary px-4/);
    assert.match(bar, /bg-primary/);
    assert.match(page, /v-if="selection"/);
    assert.match(page, /cards="xl"/);
    assert.match(page, /rail-from="2xl"/);
    // No native select.
    assert.ok(!/<select\b/.test(page + filters));

    // The rail summary (from 1536 px) and the bar (below it) carry the same props.
    assert.ok(page.includes('<PanelSelectionSummary'));
    assert.ok(page.includes('<V2SelectionBar'));
    assert.match(bar, /V2_RAIL_FROM/);
    assert.ok(!page.includes('variant='));

    // Ids and test ids live on the bar only.
    assert.equal([...page.matchAll(/:id="selection\?\.ruleId"/g)].length, 1);
    assert.equal(
        [...page.matchAll(/:data-testid="selection\?\.testId"/g)].length,
        1,
    );

    // Create claim is an outline row button (36 px, 44 px below 1024), the menu keeps View invoice.
    assert.match(page, /Create claim for invoice/);
    assert.match(page, /Actions for invoice/);
    assert.match(page, /Actions for claim/);
    // Daniel: "Filters (n)" on the button, "n filters applied" beside Reset.
    assert.match(page, /filters? applied|filter\{\{/);
});

test('the selection never hides an error or the Panel rule', () => {
    // The rail summary shows the rule, notices and errors itself.
    assert.match(selection, /role="alert"/);
    assert.match(selection, /role="status"/);
    assert.match(selection, /\{\{ ruleLine \}\}/);
    assert.match(selection, /aria-busy/);
    assert.match(selection, /Loader2/);
    // The shared bar shows the rule (or the maximum note, as a warning) in its hint and keeps the pack rule id.
    assert.match(page, /selection\?\.notices\[0\] \?\? selection\?\.ruleLine/);
    assert.match(page, /:id="selection\?\.ruleId"/);
    assert.match(bar, /hintTone/);
    assert.match(bar, /aria-busy/);
    assert.match(bar, /aria-live="polite"/);
    // "Create N claims" opens a dialog holding the date, remarks, rules and errors; Confirm runs the Classic logic.
    assert.match(page, /bulkDialogOpen/);
    assert.match(page, /@confirm="confirmBulk\(\)"/);
    assert.match(page, /Each invoice gets its own Submitted claim/);
    assert.match(page, /\(across pages\)/);
});
