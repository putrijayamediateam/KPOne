import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Inventory/TreatmentUsage.vue');
const page = read('resources/js/pages/Inventory/V2/TreatmentUsage.vue');
const template = page.slice(page.indexOf('<template>'));

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

test('page 4: the Classic script is copied verbatim', () => {
    for (const name of [
        'StockRow',
        'EntryRow',
        'Page',
        'q',
        'stockQ',
        'form',
        'errors',
        'scanned',
        'search',
        'showRecord',
        'showReverse',
        'submit',
    ]) {
        assert.equal(block(page, name), block(classic, name), name);
    }

    // Registered as the V2 page of Inventory/TreatmentUsage, Classic untouched.
    assert.match(read('config/v2-pages.php'), /'Inventory\/TreatmentUsage',/);
    assert.ok(!classic.includes('components/v2/'));
});

test("page 4: Daniel's checks (sentence, one action, no over-on-hand, reverse names the entry)", () => {
    // Stock first, entries behind a bar that is closed on a phone (while the person can record).
    assert.ok(
        template.indexOf('Record use or waste') < template.indexOf('Entries ('),
    );
    assert.match(template, /:default-open="wide \|\| !usage\.canRecord"/);
    assert.match(page, /window\.matchMedia\('\(min-width: 640px\)'\)/);
    // The record sentence names what leaves stock, from where and why; it is said before the press.
    assert.match(page, /Takes \$\{quantityWithUnit/);
    assert.match(page, /out of \$\{row\.location\} now, as \$\{why\}/);
    assert.match(page, /Not linked to a patient; no bill\./);
    // Kind and reason as chips, the reason list following the kind (verbatim watch kept).
    assert.match(template, /label="What happened"/);
    assert.match(
        block(page, 'reasonOptions'),
        /props\.usage\.reasons\[form\.kind\]/,
    );
    // Over on hand keeps Record off, with the line under the box and the reason by the button.
    assert.match(template, /:confirm-disabled="overOnHand !== null"/);
    assert.match(block(page, 'overOnHand'), /Only\$\{quantityWithUnit/);
    // Reverse is destructive and names the entry and what goes back.
    assert.match(
        template,
        /:tone="operation === 'reverse' \? 'danger' : 'default'"/,
    );
    assert.match(template, /of \{\{ activeEntry\.item_name \}\} back into/);
    assert.match(template, /An entry can be reversed once\./);
    // An unusable batch points to Write-off instead of Record, in words.
    assert.match(template, /href="\/inventory\/writeoffs"/);
    assert.match(template, /ineligibleBatchLabels\[/);
    // One sheet, one filled action, dirty guard on Record; staff fall back to "Staff member".
    assert.equal((template.match(/<V2Dialog/g) ?? []).length, 1);
    assert.match(template, /:dirty="operation === 'record' && form\.isDirty"/);
    assert.match(page, /row\.recorded_by_name \?\?\s+'Staff member'/);
    // Multi-line cells stack on cards.
    assert.match(page, /:deep\(\.v2-cards-xl td\[data-stack\]\)/);
});
