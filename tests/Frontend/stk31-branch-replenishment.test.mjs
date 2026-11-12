import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const page = readFileSync(
    new URL(
        '../../resources/js/pages/Inventory/BranchReplenishment.vue',
        import.meta.url,
    ),
    'utf8',
);

test('STK-31: the page explains its method and sends nothing by itself', () => {
    assert.match(page, /Nothing is sent from this page\./);
    assert.match(
        page,
        /Their need is what brings them up to their maximum \(or reorder\s+level\)\./,
    );
    // Read only until a person checks the dialog and presses Send request.
    assert.doesNotMatch(page, /useForm|router\.(put|patch|delete)/);
    assert.equal((page.match(/router\.post\(/g) ?? []).length, 1);
});

test('STK-31: "Request these" sends one ordinary stock request for one pair of locations', () => {
    assert.match(page, /'\/inventory\/stock-requests'/);
    assert.match(page, /source_location_public_id: draft\.from,/);
    assert.match(page, /destination_location_public_id: draft\.to,/);
    assert.match(page, /from: 'branch-replenishment',/);
    assert.match(page, /:disabled="!ticked\.length \|\| !onePair"/);
    assert.match(page, /Tick suggestions for one branch at a time\./);
    // The dialog shows each line's cap and its own message under the box.
    assert.match(page, /This branch can spare \{\{ line\.spare \}\}\./);
    assert.match(page, /'Enter a quantity above 0\.'/);
    assert.match(page, /:message="lineError\(index\)"/);
});

test('STK-31: cards show both sides, Maximum or Not set, and 44 px tick targets', () => {
    assert.match(page, /\{\{ row\.to\.branch \}\} needs/);
    assert.match(page, /\{\{ row\.from\.location \}\} can spare/);
    assert.match(
        page,
        /<dd v-else class="text-muted-foreground">\s*Not set\s*<\/dd>/,
    );
    // Below 1024 px the whole card header is the tick target, with a 24 px box.
    assert.match(page, /:is="suggestions\.canRequest \? 'label' : 'div'"/);
    assert.match(page, /min-h-11 min-w-0 cursor-pointer/);
    assert.match(page, /size-6 shrink-0 lg:size-4/);
    assert.match(page, /Already suggested\s*<\/dt>/);
});

test('STK-31: a cross-branch request offers each step only at the branch that may take it', () => {
    const source = readFileSync(
        new URL(
            '../../resources/js/lib/inventory-operation-transitions.ts',
            import.meta.url,
        ),
        'utf8',
    );
    const module = { exports: {} };
    runInNewContext(
        ts.transpileModule(source, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        { exports: module.exports, module },
    );
    const allowed = module.exports.stockRequestActionAllowed;
    const all = {
        approveRequests: true,
        dispatchTransfers: true,
        receiveTransfers: true,
    };
    const sender = { atRequesting: true, atSource: true, atDestination: false };
    const receiver = {
        atRequesting: false,
        atSource: false,
        atDestination: true,
    };

    assert.equal(allowed('requested', 'decide', all, sender), true);
    assert.equal(allowed('requested', 'decide', all, receiver), false);
    assert.equal(allowed('approved', 'dispatch', all, sender), true);
    assert.equal(allowed('approved', 'dispatch', all, receiver), false);
    assert.equal(allowed('dispatched', 'receive', all, sender), false);
    assert.equal(allowed('dispatched', 'receive', all, receiver), true);
    // Older callers without flags keep the status-and-permission rule.
    assert.equal(allowed('dispatched', 'receive', all), true);

    const panel = readFileSync(
        new URL(
            '../../resources/js/components/inventory/InventoryOperationsPanel.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(panel, /atDestination: row\.atDestination,/);
});
