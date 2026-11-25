import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const list = read('resources/js/pages/Inventory/Rfqs.vue');
const round = read('resources/js/pages/Inventory/RfqRound.vue');
const print = read('resources/js/pages/Inventory/RfqPrint.vue');
const lib = read('resources/js/lib/rfq.ts');
const panel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);
const template = (source) => source.slice(source.indexOf('<template>'));
const filledButtons = (source) =>
    [...template(source).matchAll(/<Button\b([^>]*)>/g)].filter(
        ([, attrs]) => !/variant=/.test(attrs) && !/as-child/.test(attrs),
    );

// STK-48 (owner decision 21): requests for quotation before a purchase order.
test('STK-48: the list has one filled button, "Start a quote round", and rows say how many quotes are in', () => {
    assert.match(list, />Start a quote round</);
    assert.match(list, /idempotency_key: start\.key/);
    assert.match(list, /'quote' : 'quotes'\} in/);
    // The start dialog's own submit is the only other filled button, inside the dialog.
    assert.equal(
        filledButtons(list.slice(0, list.indexOf('<Dialog'))).length,
        1,
    );
    assert.match(lib, /chosen: 'success'/);
    assert.doesNotMatch(lib, /waiting: 'success'|quotes_in: 'success'/);
});

test('STK-48: the round page has one filled button, "Make purchase order", and lists the lines left off', () => {
    const page = template(round);
    const header = page.slice(0, page.indexOf('<!-- From 1024 px'));
    assert.equal(filledButtons(header).length, 1);
    assert.match(header, />Make purchase order</);
    assert.match(round, /data-testid="rfq-left-off"/);
    assert.match(round, /Left off \(the supplier can't supply\):/);
});

test('STK-48: a cost-hidden role sees "Prices are hidden for your role." and no quote or choose controls', () => {
    assert.match(round, /const HIDDEN = 'Prices are hidden for your role\.';/);
    assert.match(round, /props\.rfq\.can\.recordQuote &&/);
    assert.match(round, /props\.rfq\.can\.choose &&/);
    assert.match(round, /v-else-if="!rfq\.can\.seePrices"/);
});

test('STK-48: the comparison shows the price per stock unit, the pack price muted under it, and Cheapest with a dot', () => {
    assert.match(round, /\/ pack of/);
    assert.match(round, /tieBreak \? `Cheapest, \$\{tieBreakWords/);
    assert.match(round, /size-1\.5 rounded-full bg-current/);
    assert.match(lib, /faster_delivery: 'faster delivery'/);
    assert.match(round, /label="Waiting for a quote"/);
    assert.match(round, />Can't supply</);
});

test('STK-48: a supplier that cannot supply every line shows "Total for N of M lines"', () => {
    assert.match(
        round,
        /`Total for \$\{r\.quote\.suppliedCount\} of \$\{lineCount\.value\} lines`/,
    );
});

test('STK-48: an expired quote shows Expired in warning tone and Choose needs the checkbox', () => {
    assert.match(round, /label="Expired"\s+tone="warning"/);
    assert.match(round, /Still valid, confirmed with the supplier/);
    assert.match(round, /'Tick to continue\.'/);
    assert.match(round, /v-if="choosing\.quote\?\.expired"/);
});

test('STK-48: Choose shows the gap and the reasons as radios only when not the cheapest', () => {
    assert.match(round, /more than the cheapest/);
    assert.match(round, /<fieldset v-if="needsReason"/);
    assert.match(round, /type="radio"/);
    assert.match(lib, /stock_availability: 'Stock availability'/);
});

test('STK-48: Record quote has one block per line with a live price per stock unit and the KPOne note once', () => {
    assert.match(round, /Stock units in one pack \(\{\{/);
    assert.match(round, /unitHint\(/);
    assert.match(
        lib,
        /return `= \$\{rmFour\(hundredth\)\} per \$\{stockUnit\}`;/,
    );
    assert.match(round, /\{\{ quote\.reference\.length \}\} \/ 60/);
    assert.match(round, />Save quote</);
    assert.equal(
        [...round.matchAll(/KPOne does not send\s+or receive anything\./g)]
            .length,
        1,
    );
});

test('STK-48: only Cancel is red, Mark as sent and Choose are outline', () => {
    assert.match(round, /variant="destructive"/);
    assert.equal([...round.matchAll(/variant="destructive"/g)].length, 1);
    assert.match(round, /variant="outline"[\s\S]{0,200}>Mark as sent</);
});

test('STK-48: phones get one card per item with supplier rows, actions 44 px', () => {
    assert.match(round, /class="space-y-3 lg:hidden"/);
    assert.match(round, /data-testid="rfq-item-card"/);
    assert.match(round, /\[&>\*\]:min-h-11/);
});

test('STK-48: the print is white A4 with page numbers, "Please quote by" and no price, note or cost', () => {
    assert.match(print, /size: A4;/);
    assert.match(print, /counter\(page\)/);
    assert.match(print, /background: #fff;/);
    assert.match(print, /Please quote by \{\{ rfq\.quoteBy \}\}/);
    assert.doesNotMatch(
        template(print).replace(/<style>[\s\S]*$/, '') +
            print
                .slice(0, print.indexOf('<template>'))
                .replace(/\/\/.*$/gm, ''),
        /unitCost|lineSen|totalSen|packPrice|PriceSen|\bnote\b/,
    );
});

test('STK-48: the order form shows estimated cost only with cost view and links to RFQs', () => {
    assert.match(
        panel,
        /<Input\s+v-if="operations\.permissions\.viewCost"\s+v-model="line\.estimatedUnitCostRm"/,
    );
    assert.match(panel, /href="\/inventory\/rfqs"/);
    assert.match(panel, /From quote \{\{ row\.fromRfq \}\}/);
});

test('STK-48: the draft edit form shows and sends back the estimate for cost-view holders only', () => {
    assert.match(
        panel,
        /costs: Object\.fromEntries\([\s\S]{0,120}senToRm\(line\.estimatedUnitCostSen\)/,
    );
    assert.match(
        panel,
        /\.\.\.\(props\.operations\.permissions\.viewCost\s+\? \{ unit_cost_sen: rmToSen\(edit\.costs\[line\.publicId\] \?\? ''\) \}\s+: \{\}\)/,
    );
    assert.match(
        panel,
        /<template v-if="operations\.permissions\.viewCost">\s+<label\s+v-for="line in row\.lines"\s+:key="`edit-cost-/,
    );
});
