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
    assert.match(template, /\/inventory\/writeoffs\?q=/);
    assert.match(template, /ineligibleBatchLabels\[/);
    // One sheet, one filled action, dirty guard on Record; staff fall back to "Staff member".
    assert.equal((template.match(/<V2Dialog/g) ?? []).length, 1);
    assert.match(template, /:dirty="operation === 'record' && form\.isDirty"/);
    assert.match(page, /row\.recorded_by_name \?\?\s+'Staff member'/);
    // Multi-line cells stack on cards.
    assert.match(page, /:deep\(\.v2-cards-xl td\[data-stack\]\)/);
});

test("page 4: Daniel's plan additions (reverse-only view, privacy, blocked reversal, Write-off link)", () => {
    // A reverse-only person sees no Record section, and Entries opens for them, so the page never starts empty.
    assert.match(template, /<section\s+v-if="usage\.canRecord"/);
    assert.match(template, /:default-open="wide \|\| !usage\.canRecord"/);
    // 1) Privacy: the sheet has no free-text box; the quantity is the only typed field, and nothing is a textarea.
    const sheet = template.slice(template.indexOf('<V2Dialog'));
    assert.equal((sheet.match(/<input\b/g) ?? []).length, 1);
    assert.match(sheet, /<input\s+v-model="form\.quantity"/);
    assert.ok(!/<textarea\b/.test(template));
    // 2) A later governed count or write-off blocks the reversal: the row says so instead of offering Reverse.
    assert.match(
        template,
        /A later stock count or\s+write-off came after this entry,\s+so it cannot be reversed\s+here\./,
    );
    assert.match(template, /row\.canReverse &&\s+!laterGoverned\(row\)/);
    assert.match(block(page, 'laterGoverned'), /later_governed\?:boolean/);
    // An entry already reversed keeps its line (it comes first).
    assert.ok(
        template.indexOf('The stock was put') <
            template.indexOf('A later stock count or'),
    );
    // 4) Use Write-off opens page 3's write-off mode with the batch searched.
    assert.match(
        template,
        /:href="`\/inventory\/writeoffs\?q=\$\{encodeURIComponent\(row\.batch_number\)\}`"/,
    );
    // 3) The over-on-hand figure comes from the row the sheet opened from.
    assert.match(
        block(page, 'overOnHand'),
        /consthave=transferQuantityThousandths\(row\.on_hand\)/,
    );
    // 5) Reverse: the box is neutral; only the confirm button is destructive.
    assert.match(
        sheet,
        /<p class="rounded-\[14px\] border bg-muted\/40 px-4 py-3 text-sm">\s+Puts/,
    );
});

test('page 4 review: the sentence shows with the Record button, and cards drop the Action label', () => {
    const record = template.slice(
        template.indexOf('<!-- Record: what, why and how much'),
        template.indexOf('<!-- Reverse: names the entry'),
    );
    // The sentence is pinned just above the buttons, so it stays in view while the sheet scrolls.
    assert.match(
        record,
        /class="sticky bottom-0 z-10[^"]*"\s+role="status"\s*>\s*\{\{ recordSentence \}\}/,
    );
    assert.match(
        block(page, 'stockRows'),
        /label:'Item',value:`\$\{row\.item_name\}·\$\{row\.sku_code\}`/,
    );
    assert.equal((template.match(/<td data-actions>/g) ?? []).length, 2);
    assert.ok(!template.includes('data-label="Action"'));
});
