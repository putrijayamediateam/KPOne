import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Inventory/GovernedStock.vue');
const page = read('resources/js/pages/Inventory/V2/GovernedStock.vue');
const template = page.slice(page.indexOf('<template>'));

/** The source of `const name = …` up to the next top-level statement, without whitespace (indentation differs). */
const block = (source, name) => {
    const start = source.search(
        new RegExp(`\\n[ \\t]*(?:const|type|function) ${name}\\b`),
    );
    assert.ok(start >= 0, `${name} not found`);
    const rest = source.slice(start + 1);
    const indent = /^[ \t]*/.exec(rest)[0];
    const next = rest
        .slice(indent.length)
        .search(
            new RegExp(
                `\\n${indent}(?:const |type |let |watch|function |//|/\\*\\*|return \\{|\\}\\n|<\\/script>)`,
            ),
        );

    return (next < 0 ? rest : rest.slice(0, next + indent.length))
        .replace(/\s+/g, '')
        .replace(/,(?=[)\]}])/g, '');
};

/** A sheet's markup: from its comment to the next sheet comment. */
const section = (marker, end) => {
    const from = template.indexOf(marker);
    assert.ok(from >= 0, marker);
    const to = template.indexOf(end, from + marker.length);

    return template.slice(from, to < 0 ? undefined : to);
};

test('page 3: the Classic script is copied verbatim (types, search, counts due, sheets and submit)', () => {
    for (const name of [
        'Target',
        'Action',
        'Case',
        'CycleRow',
        'Page',
        'path',
        'states',
        'q',
        'state',
        'open',
        'history',
        'form',
        'scanned',
        'search',
        'starting',
        'startCount',
        'cycleError',
        'dueCap',
        'showAllDue',
        'visibleCycleRows',
        'dueLabel',
        'showTarget',
        'showCase',
        'submit',
        'caseKindLabel',
    ]) {
        assert.equal(block(page, name), block(classic, name), name);
    }

    // Registered as the V2 page of Inventory/GovernedStock (same routes and props), Classic untouched.
    assert.match(read('config/v2-pages.php'), /'Inventory\/GovernedStock',/);
    assert.ok(!classic.includes('components/v2/'));
});

test('page 3: blind counts hold on the V2 page too (Daniel, change b)', () => {
    // Start a count, enter a count and recount show where, never a quantity, snapshot or first count.
    const counting = section(
        '<!-- Start a count, enter a count, recount',
        '<!-- Write off. -->',
    );

    for (const forbidden of [
        'expected_quantity',
        'last_quantity',
        '.quantity',
        'history',
        'countedOf',
        'differenceOf',
        'approveRows',
    ]) {
        assert.ok(!counting.includes(forbidden), forbidden);
    }

    assert.match(counting, /<V2KeyValueList :items="whereRows" \/>/);
    assert.match(
        counting,
        /Someone else counted first\. Count again yourself;/,
    );
    assert.ok(!/quantity/.test(block(page, 'whereRows')));
    // The Count and Recount boxes start empty: the form starts with no quantity and every sheet resets it on opening,
    // and the box is bound only to what the person types (Daniel, page 3 review item 1).
    assert.match(block(page, 'form'), /quantity:'',/);
    assert.match(
        block(page, 'showCase'),
        /^constshowCase=|functionshowCase\(row:Case,action:string\)\{form\.reset\(\);/,
    );
    assert.match(
        page,
        /function showCase\(row: Case, action: string\) \{\n\s+form\.reset\(\);/,
    );
    assert.equal((template.match(/v-model="form\.quantity"/g) ?? []).length, 1);
    assert.ok(!/:value="[^"]*quantity/.test(template));
    // The approve summary is never built for a blind case, and the targets in count mode show no on-hand column.
    assert.match(
        block(page, 'approveRows'),
        /if\(!row\|\|row\.blind\)\{return\[\];\}/,
    );
    assert.match(
        template,
        /v-if="stock\.mode === 'writeoff'"\s+data-label="On hand"/,
    );
    assert.match(template, /v-if="stock\.mode === 'writeoff'"\s+scope="col"/);
});

test("page 3: Daniel's plan and changes a to f", () => {
    // c) Cases come before the long list of stock, which sits behind a bar on a phone (d).
    assert.ok(
        template.indexOf('Cases and next action') <
            template.indexOf('Find stock to count'),
    );
    // Multi-line cells stack on phone cards: label on its own line, value full width.
    assert.equal(
        (template.match(/<td data-stack data-label=/g) ?? []).length,
        3,
    );
    assert.match(page, /:deep\(\.v2-cards-xl td\[data-stack\]\)/);
    // Counts due and the stock list both sit behind bars that are closed on a phone.
    assert.equal((template.match(/:default-open="wide"/g) ?? []).length, 2);
    assert.match(
        template,
        /:title="`Counts due \(\$\{stock\.cycle\.total\}\)`"/,
    );
    assert.match(page, /window\.matchMedia\('\(min-width: 640px\)'\)/);
    assert.match(
        template,
        /v-if="!showAllDue && stock\.cycle\.total > dueCap"/,
    );
    // 2) The five states as chips in words.
    assert.match(template, /label="Case status"\s+:options="stateChips"/);

    for (const label of [
        'Waiting for count',
        'Waiting for recount',
        'Waiting for approval',
        'Closed, no difference',
        'Posted',
    ]) {
        assert.ok(page.includes(`label: '${label}'`), label);
    }

    // a) Approve shows what is approved: the counted figure and the difference only from data the approver gets.
    const approve = block(page, 'approveRows');

    for (const label of [
        'Item',
        'SKU',
        'Batch',
        'Location',
        'Reason',
        'Snapshot',
        'Counted',
        'Difference',
        'Quantity',
    ]) {
        assert.ok(approve.includes(`label:'${label}'`), label);
    }

    assert.match(page, /fewer than the snapshot/);
    // e) Write-off: more than on hand keeps the action off, with the line under the box; the sentence names the item.
    assert.match(template, /:confirm-disabled="overOnHand !== null"/);
    assert.match(block(page, 'overOnHand'), /Only\$\{quantityWithUnit/);
    assert.match(page, /Proposes writing off \$\{quantityWithUnit/);
    // f) Reverse is destructive and names the case.
    assert.match(
        template,
        /:tone="operation === 'reversal' \? 'danger' : 'default'"/,
    );
    assert.match(
        template,
        /Proposes reversing \{\{ caseKindLabel\(activeCase\.kind\) \}\} #\{\{/,
    );
    // The row menu: Open, then Act, then Danger last; staff stay "Staff #id" (no names, Daniel answer 1).
    const menu = block(page, 'caseMenu');
    assert.ok(
        menu.indexOf("label:'Open'") < menu.indexOf("label:'Act'") &&
            menu.indexOf("label:'Act'") < menu.indexOf("label:'Danger'"),
    );
    assert.match(template, /Staff #\{\{/);
    // One filled action per sheet; it asks before closing with input typed.
    assert.equal((template.match(/<V2Dialog/g) ?? []).length, 2);
    assert.match(template, /:dirty="hasInput && form\.isDirty"/);
    assert.match(template, /single-action/);
});
