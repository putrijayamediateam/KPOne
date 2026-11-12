import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

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
        /Their need = their maximum \(or reorder level\) − on hand − on order −\s+incoming\./,
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
    assert.match(page, /Tick suggestions with the same pair only\./);
});

test('STK-31: cards show both sides, Maximum or Not set, and 44 px tick targets', () => {
    assert.match(page, /\{\{ row\.to\.branch \}\} needs/);
    assert.match(page, /\{\{ row\.from\.location \}\} can spare/);
    assert.match(
        page,
        /<dd v-else class="text-muted-foreground">\s*Not set\s*<\/dd>/,
    );
    assert.match(page, /size-11 shrink-0/);
});
