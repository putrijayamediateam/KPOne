import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Billing/V2/Show.vue');
const classic = read('resources/js/components/billing/BillingWorkspace.vue');
const registry = read('config/v2-pages.php');
const lib = read('resources/js/lib/billing-v2.ts');
const testIds = (src) =>
    [...src.matchAll(/data-testid="([^"]+)"/g)].map((m) => m[1]);

test('BV2-1 is registered for Version 2 and uses the BV2-0 copy of the Classic logic', () => {
    assert.match(registry, /'Billing\/Show',/);
    assert.match(
        page,
        /import \{ useBillingWorkspaceV2 \} from '@\/composables\/billing\/useBillingWorkspaceV2';/,
    );
    assert.match(page, /= useBillingWorkspaceV2\(props\);/);
    // The page sends nothing itself: every request goes through the copied actions.
    assert.doesNotMatch(page, /router\.(post|put|patch|delete|visit)\(/);
    assert.doesNotMatch(page, /fetch\(|axios/);
    // Classic stays untouched by Version 2.
    assert.doesNotMatch(classic, /V2|useBillingWorkspaceV2/);
});

test('every Classic test id is kept on the Version 2 page', () => {
    const v2 = new Set(testIds(page));

    for (const id of new Set(testIds(classic))) {
        assert.ok(v2.has(id), `missing data-testid="${id}"`);
    }
});

test('"Billing context cleared" resets after navigating to the Version 2 page name', () => {
    assert.match(
        page,
        /router\.on\('success', \(event\) => \{\s*if \(event\.detail\.page\.component === 'Billing\/V2\/Show'\) \{\s*contextCleared\.value = false;/,
    );
    assert.match(page, /onUnmounted\(stopV2Success\)/);
    assert.match(page, /Return to workspace/);
});

// Compile the pure helper and run it with `myr` stubbed as the real formatter's shape.
const helper = (() => {
    const js = ts.transpileModule(lib, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const names = [...js.matchAll(/export const (\w+)/g)].map((m) => m[1]);
    const exports = {};
    runInNewContext(
        `const myr = (sen) => 'RM ' + (sen / 100).toFixed(2);
         ${js.replace(/^import .*$/gm, '').replace(/export const /g, 'const ')}
         Object.assign(exports, { ${names.join(', ')} });`,
        { exports },
    );

    return exports;
})();
const state = (over = {}) => ({
    total: 4000,
    self_pay: 0,
    panel: 0,
    deferred: 0,
    due_now: 0,
    ...over,
});
const can = (over = {}) => ({
    build: false,
    finalize: false,
    pay: false,
    complete: false,
    ...over,
});

test('one main action per state, from the permission flags and figures only', () => {
    const { billingPrimary: p } = helper;
    const inv = (status, s = {}) => ({ status, state: state(s) });

    assert.equal(
        p({ invoice: null, can: can({ build: true }) }, false),
        'build',
    );
    assert.equal(p({ invoice: null, can: can() }, false), null);
    assert.equal(
        p(
            {
                invoice: inv('draft'),
                can: can({ build: true, finalize: true }),
            },
            false,
        ),
        'finalize',
    );
    // A stale draft cannot be finalized: rebuilding it is the main action.
    assert.equal(
        p({ invoice: inv('draft'), can: can({ build: true }) }, false),
        'build',
    );
    assert.equal(
        p(
            {
                invoice: inv('finalized', { due_now: 4000 }),
                can: can({ pay: true }),
            },
            false,
        ),
        'pay',
    );
    assert.equal(
        p(
            {
                invoice: inv('finalized', { due_now: 0, deferred: 2000 }),
                can: can({ pay: true, complete: true }),
            },
            false,
        ),
        'complete',
    );
    assert.equal(
        p(
            {
                invoice: inv('finalized'),
                can: can({ pay: true, complete: true }),
            },
            false,
        ),
        'complete',
    );
    // Completed with money owed, including approved pay later still to collect.
    assert.equal(
        p(
            {
                invoice: inv('finalized', { due_now: 1500 }),
                can: can({ pay: true }),
            },
            true,
        ),
        'settle',
    );
    assert.equal(
        p(
            {
                invoice: inv('finalized', { deferred: 5000 }),
                can: can({ pay: true }),
            },
            true,
        ),
        'settle',
    );
    assert.equal(
        p({ invoice: inv('finalized'), can: can({ pay: true }) }, true),
        null,
    );
    assert.equal(
        p({ invoice: inv('finalized', { due_now: 1500 }), can: can() }, true),
        null,
    );
    assert.deepEqual(Object.keys(helper.billingPrimaryLabel), [
        'build',
        'finalize',
        'pay',
        'settle',
        'complete',
    ]);
    // Exactly one place each for the header button and the bottom bar.
    assert.equal(page.match(/@click="primary\.run\(\)"/g).length, 2);
    assert.match(page, /billingPrimary\(props\.billing, isCompleted\.value\)/);
    // A stale draft shows one Build button, not two.
    assert.match(page, /billing\.can\.build &&\s*primaryKind !== 'build'/);
});

test('Due now wording: amount, pay later still owed, nothing due', () => {
    const { billingDueLine: d } = helper;
    assert.equal(d(null), null);
    assert.equal(d(state({ due_now: 4000 })), 'Due now RM 40.00');
    assert.equal(
        d(state({ due_now: 4000, deferred: 1000 })),
        'Due now RM 40.00',
    );
    assert.equal(d(state({ deferred: 5000 })), 'Pay later owed RM 50.00');
    assert.equal(d(state()), 'Nothing due');
    const parts = (x) => JSON.parse(JSON.stringify(helper.billingDueParts(x)));
    assert.equal(parts(null), null);
    assert.deepEqual(parts(state({ due_now: 4000 })), {
        label: 'Due now',
        figure: 'RM 40.00',
    });
    assert.deepEqual(parts(state({ deferred: 5000 })), {
        label: 'Pay later owed',
        figure: 'RM 50.00',
    });
    assert.deepEqual(parts(state()), { label: null, figure: 'Nothing due' });
    // The label line and the figure share one element; the figure never wraps in the 1280 px rail.
    assert.match(page, /text-3xl[^"]*whitespace-nowrap tabular-nums/);
    assert.doesNotMatch(page, /text-\[32px\]/);
});

test('the right column starts at 1280 px; the main action is never missing at any width', () => {
    // One layout system: the shared work layout's 1280 px rail, no page-local grid.
    assert.match(page, /<V2WorkLayout[^>]*rail-from="xl"/);
    assert.match(page, /rail-label="Payment summary"/);
    assert.doesNotMatch(page, /xl:grid-cols-/);
    // V2StickyBar reads the layout's rail breakpoint (shown below 1280 px), so the header button shows from 1280 px.
    assert.match(page, /\[primaryButton, 'max-xl:hidden'\]/);
    assert.match(page, /<V2StickyBar\s+v-if="primary"/);
    // The payment summary is rendered once in the rail, stacked above the work below 1280 px (railBelow "stack-top"),
    // so nothing remounts (and no open dialog closes) when the width crosses 1280 px.
    assert.match(page, /rail-below="stack-top"/);
    assert.equal(page.match(/<template #rail>/g).length, 1);
    assert.equal(page.match(/data-testid="billing-financial"/g).length, 1);
    assert.doesNotMatch(
        page,
        /useMediaQuery|createReusableTemplate|#rail-collapsed/,
    );
});

test('the "More billing actions" menu keeps Governed correction last, in the Danger group', () => {
    const block = page.slice(
        page.indexOf('const moreGroups'),
        page.indexOf('const onMore'),
    );
    assert.ok(
        block.indexOf("label: 'Danger'") >
            block.indexOf("label: 'Panel or pay later'"),
    );
    assert.match(block, /allowed: props\.billing\.can\.void,\s*tone: 'danger'/);
    assert.match(block, /allowed: props\.billing\.can\.panelPropose/);
    assert.match(block, /allowed: props\.billing\.can\.deferPropose/);
    // The correction opens in view with focus and a visible Cancel.
    assert.match(
        page,
        /window\.setTimeout\(\(\) => \{\s*voidReason\.value\?\.scrollIntoView\(\{ block: 'center' \}\);\s*voidReason\.value\?\.focus\(\);\s*\}, 50\)/,
    );
    assert.match(page, /@click="closeVoid"\s*>Cancel<\/Button/);
});

test('status words come from the one status map; the shortfall uses warning tokens', () => {
    assert.match(page, /label: formatStatusLabel\(status\)/);
    assert.match(page, /t === 'accent' \? 'info' : t/);
    assert.doesNotMatch(page, /amber-/);
    assert.match(page, /border-warning\/40 bg-warning\/10/);
});

test('the three entry dialogs are V2Dialogs with the Classic calls and disabled conditions', () => {
    assert.equal(page.match(/<V2Dialog\b/g).length, 3);
    // Money dialogs are tall: the footer with the action is pinned and never scrolls away.
    assert.equal(page.match(/size="tall"/g).length, 2);

    for (const [v2, classicCall] of [
        ['@confirm="payment"', '@click="payment"'],
        [
            '@confirm="propose(responsibilityKind)"',
            '@click="propose(\'panel\')"',
        ],
        [
            '@confirm="reversalReceipt && reverse(reversalReceipt)"',
            '@click="reversalReceipt && reverse(reversalReceipt)"',
        ],
    ]) {
        assert.ok(page.includes(v2), v2);
        assert.ok(classic.includes(classicCall), `Classic has ${classicCall}`);
    }

    // Disabled exactly when Classic disables the button (V2Dialog's busy disables Confirm).
    assert.match(
        page,
        /:busy="busy \|\| !form\.method \|\| !billing\.can\.pay"/,
    );
    assert.match(classic, /:disabled="busy \|\| !form\.method"/);
    assert.match(
        page,
        /:busy="\s*busy \|\|\s*!form\.reason \|\|\s*!form\.recording_error_only \|\|\s*!reversalReceipt\s*"/,
    );
    assert.match(page, /tone="danger"/);
    // Closing while busy is still refused by the copied logic.
    assert.equal(page.match(/@update:open="updateDialog\('/g).length, 3);

    for (const model of [
        'form.amount',
        'form.reference',
        'form.responsibility_amount',
        'form.member_reference',
        'form.due_date',
        'form.reason',
        'form.recording_error_only',
    ]) {
        assert.ok(page.includes(`v-model="${model}"`), model);
    }

    assert.equal(page.match(/<V2MoneyInput\b/g).length, 2);
    assert.match(page, /label="Amount received"/);
    // One sentence-case name for the action, and the hint says what is missing (Daniel, round 3).
    assert.match(
        page,
        /isCompleted\s*\? 'Settle Outstanding Balance'\s*: 'Record payment'/,
    );
    assert.doesNotMatch(page, /Add Payment/);
    assert.match(
        page,
        /Choose an amount and a payment method to record the payment\./,
    );
    assert.match(
        page,
        /Enter a reason and tick the box to reverse this receipt\./,
    );
    // Pickers keep the Classic form values, so payloads are unchanged.
    assert.match(page, /@update:model-value="form\.method = \$event \?\? ''"/);
    assert.match(
        page,
        /form\.panel_id = \(match\?\.value \?\? ''\) as string;/,
    );
    assert.doesNotMatch(
        page,
        /OperationalSelect|from '@\/components\/ui\/dialog'/,
    );
    assert.doesNotMatch(page, /variant="secondary"/);
    // The bottom bar never shows through a dialog's scrim.
    assert.match(
        page,
        /body\[data-billing-v2\]:has\(\[role='dialog'\]\) \[data-slot='v2-sticky-bar'\] \{\s*visibility: hidden;/,
    );
});
