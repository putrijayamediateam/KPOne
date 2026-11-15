import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const registry = read('config/v2-pages.php');
const parts = (page) => {
    const source = read(page);

    return {
        source,
        script: source.slice(0, source.indexOf('<template>')),
        template: source.slice(source.indexOf('<template>')),
        flat: source.slice(source.indexOf('<template>')).replace(/\s+/g, ' '),
    };
};
const pages = {
    health: 'StockHealth',
    reorder: 'ReorderSuggestions',
    review: 'StockReview',
    replenish: 'BranchReplenishment',
};
const classic = Object.fromEntries(
    Object.entries(pages).map(([k, f]) => [
        k,
        read(`resources/js/pages/Inventory/${f}.vue`),
    ]),
);
const v2 = Object.fromEntries(
    Object.entries(pages).map(([k, f]) => [
        k,
        parts(`resources/js/pages/Inventory/V2/${f}.vue`),
    ]),
);

/** The source of `const name = …` up to the next top-level statement, without whitespace. */
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
/** Every `watch(` block of a source, without whitespace, in order. */
const watches = (source) =>
    source
        .slice(0, source.indexOf('</script>'))
        .split(/\nwatch\(/)
        .slice(1)
        .map((chunk) =>
            chunk.slice(0, chunk.indexOf('\n);')).replace(/\s+/g, ''),
        );

const verbatim = {
    health: ['Row', 'props', 'q', 'days', 'visit', 'plural'],
    reorder: [
        'Row',
        'props',
        'q',
        'need',
        'visit',
        'needsOrder',
        'ticked',
        'tickedRows',
        'oneLocation',
        'requestThese',
    ],
    review: [
        'Review',
        'Row',
        'Kind',
        'List',
        'props',
        'headings',
        'kinds',
        'outcomes',
        'q',
        'selected',
        'kind',
        'dialog',
        'history',
        'form',
        'search',
        'show',
        'submit',
        'page',
    ],
    replenish: [
        'Row',
        'props',
        'q',
        'visit',
        'ticked',
        'tickedRows',
        'oneDestination',
        'onePair',
        'sendOpen',
        'busy',
        'sendErrors',
        'draft',
        'requestThese',
        'localErrors',
        'sendKey',
        'checkLines',
        'lineError',
        'sendRequest',
        'generalError',
    ],
};

for (const [key, names] of Object.entries(verbatim)) {
    test(`page 10a: the ${pages[key]} Classic script is copied verbatim`, () => {
        for (const name of names) {
            assert.equal(
                block(v2[key].script, name),
                block(classic[key], name),
                name,
            );
        }

        assert.deepEqual(watches(v2[key].script), watches(classic[key]));
    });
}

test('page 10a: registered, V2 blocks, no native select, radio or checkbox; Classic untouched', () => {
    for (const file of Object.values(pages)) {
        assert.match(registry, new RegExp(`'Inventory/${file}',`));
    }

    for (const page of Object.values(v2)) {
        assert.ok(!page.template.includes('<select'));
        assert.ok(!page.template.includes('type="radio"'));
        assert.ok(!page.template.includes('type="checkbox"'));
        assert.match(page.template, /<V2Table/);
        assert.equal((page.template.match(/<V2Pager/g) ?? []).length, 1);
        assert.ok(!page.source.includes('CompactPagination'));
    }

    for (const source of Object.values(classic)) {
        assert.ok(!source.includes('components/v2'));
    }
});

test('page 10a: quantities read with plural unit words', () => {
    for (const page of Object.values(v2)) {
        assert.match(page.source, /quantityWithUnit\(/);
        assert.ok(!page.template.includes('quantity(row.'));
    }
});

test('page 10a: Stock health stats, period buttons and the method note', () => {
    const page = v2.health;
    assert.equal((page.template.match(/<V2Stat/g) ?? []).length, 3);
    assert.match(
        page.flat,
        /<V2OptionGroup :model-value="String\(days\)" label="Period"/,
    );
    assert.match(page.flat, /data-testid="stock-health-method"/);
    assert.match(page.flat, /label="No use in this period"/);
    assert.match(page.flat, /More than 1 year/);
});

test('page 10a: Reorder suggestions tick into the selection bar; cost stays Director and Finance only', () => {
    const page = v2.reorder;
    assert.match(page.flat, /v-if="needsOrder\(row\)"/);
    assert.match(page.flat, /<V2SelectionBar/);
    assert.match(page.flat, /action-label="Request these"/);
    assert.match(page.flat, /@action="requestThese"/);
    assert.match(
        page.flat,
        /'A request is for one location\. Tick suggestions of one location only\.'/,
    );
    assert.match(
        page.flat,
        /suggestions\.canSeeCost && row\.supplier && row\.suggestedPacks !== null/,
    );
    assert.match(page.flat, /data-testid="price-hidden-note"/);
});

test('page 10a: Stock review tabs, an empty outcome to start, and the history sheet', () => {
    const page = v2.review;
    assert.match(page.flat, /<V2Tabs :model-value="active"/);
    assert.match(page.script, /form\.outcome = '';/);
    assert.match(page.flat, /'Pick an outcome to continue\.'/);
    assert.match(page.flat, /@confirm="submit"/);
    assert.match(page.flat, /v-if="review\.canManage"/);
    assert.match(page.flat, /single-action-style="outline"/);
    assert.match(page.flat, /an acknowledgement does not prove disposal\./);
});

test('page 10a: Branch replenishment ticks into the selection bar and the sheet calls sendRequest', () => {
    const page = v2.replenish;
    assert.match(page.flat, /<V2SelectionBar/);
    assert.match(page.flat, /@action="requestThese"/);
    assert.match(page.flat, /@confirm="sendRequest"/);
    assert.match(page.script, /'Tick suggestions for one branch at a time\.'/);
    assert.match(
        page.flat,
        /:aria-invalid="lineError\(index\) \? true : undefined"/,
    );
    assert.match(
        page.flat,
        /Creates a stock request only\. No stock moves until it is approved and sent\./,
    );
});

test("page 10a: Daniel's changes, disclosure bars, a full-size supplier note and compact Stock health cards", () => {
    // The method notes use V2DisclosureBar (it has the chevron), never a bare <details>.
    for (const key of ['health', 'reorder', 'replenish']) {
        assert.match(v2[key].template, /<V2DisclosureBar title="How /);
        assert.ok(!v2[key].template.includes('<details'));
    }

    // The no-supplier note is the row's own size, with the link on its own line.
    assert.match(
        v2.reorder.flat,
        /<div v-else class="flex min-w-0 flex-col gap-1 text-sm" ?>/,
    );
    assert.match(v2.reorder.flat, />Set one in Supplier price list<\/Link/);
    assert.ok(
        !v2.reorder.flat.includes('text-xs text-muted-foreground" > None set'),
    );
    // Ticks use the table's own select cell on cards.
    assert.match(v2.reorder.flat, /<td v-if="canTick" data-select>/);
    assert.match(
        v2.replenish.flat,
        /<td v-if="suggestions\.canRequest" data-select>/,
    );
    // Stock health cards: the medicine across the top, then two columns.
    assert.match(v2.health.flat, /class="v2-health-cards"/);
    assert.match(
        v2.health.source,
        /grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/,
    );
    assert.match(
        v2.health.source,
        /td\[data-primary\]\) \{\s*grid-column: 1 \/ -1;/,
    );
});
