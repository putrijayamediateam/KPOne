// Classic only, CO-6: the Classic half of v2-inventory-lists-10a.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
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
    test(`page 10a: the Classic ${pages[key]} script keeps its named blocks`, () => {
        for (const name of names) {
            block(classic[key], name);
        }
    });
}

test('page 10a: Classic list pages import no V2 code', () => {
    for (const source of Object.values(classic)) {
        assert.ok(!source.includes('components/v2'));
    }
});
