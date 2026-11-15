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
const holdsClassic = read('resources/js/pages/Inventory/BatchHolds.vue');
const recallsClassic = read('resources/js/pages/Inventory/Recalls.vue');
const holds = parts('resources/js/pages/Inventory/V2/BatchHolds.vue');
const recalls = parts('resources/js/pages/Inventory/V2/Recalls.vue');

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

test('page 7a: the Batch holds Classic script is copied verbatim', () => {
    for (const name of [
        'Row',
        'props',
        'query',
        'open',
        'selected',
        'action',
        'form',
        'placeReasons',
        'releaseReasons',
        'label',
        'show',
        'search',
        'submit',
    ]) {
        assert.equal(
            block(holds.script, name),
            block(holdsClassic, name),
            name,
        );
    }

    assert.deepEqual(watches(holds.script), watches(holdsClassic));
});

test('page 7b: the Recalls Classic script is copied verbatim', () => {
    for (const name of [
        'Where',
        'Recall',
        'BatchOption',
        'props',
        'reasons',
        'liftReasons',
        'visit',
        'generalError',
        'startOpen',
        'start',
        'options',
        'batchSearch',
        'BATCH_CAP',
        'matchingOptions',
        'visibleOptions',
        'selectedOptions',
        'unselect',
        'optionsError',
        'startErrors',
        'busy',
        'openStart',
        'startFields',
        'submitStart',
        'startGeneral',
        'lifting',
        'lift',
        'liftErrors',
        'openLift',
        'submitLift',
        'liftGeneral',
        'batchWord',
        'startSummary',
        'liftBatches',
        'tabs',
    ]) {
        assert.equal(
            block(recalls.script, name),
            block(recallsClassic, name),
            name,
        );
    }

    assert.deepEqual(watches(recalls.script), watches(recallsClassic));

    for (const path of [
        "'/inventory/recalls'",
        '`/inventory/recalls/batches?sku=',
    ]) {
        assert.ok(
            recallsClassic.includes(path) && recalls.source.includes(path),
            path,
        );
    }
});

test('page 7: registered, V2 blocks, no native select, radio or checkbox; Classic untouched', () => {
    assert.match(registry, /'Inventory\/BatchHolds',/);
    assert.match(registry, /'Inventory\/Recalls',/);

    for (const page of [holds, recalls]) {
        assert.ok(!page.template.includes('<select'));
        assert.ok(!page.template.includes('type="radio"'));
        assert.ok(!page.template.includes('type="checkbox"'));
        assert.match(page.template, /<V2OptionGroup/);
        assert.match(page.template, /<template #footer-note>/);
    }

    for (const classic of [holdsClassic, recallsClassic]) {
        assert.ok(!classic.includes('components/v2'));
    }
});

test("page 7a: Daniel's checklist for Batch holds", () => {
    // Chips with words; one outline action per row; the checker line instead of a missing button.
    assert.match(
        holds.flat,
        /`Held: \$\{formatInventoryReason\(row\.reason\)\}`/,
    );
    assert.match(holds.flat, /'No hold at this branch'/);
    assert.match(holds.script, /text\.charAt\(0\)\.toUpperCase\(\)/);
    assert.match(holds.flat, /v-else-if="row\.canRelease"/);
    assert.match(
        holds.flat,
        /A different authorised checker is required for release\./,
    );
    // Reason starts empty; action off with words; red Place, standard Release, named with the batch.
    assert.match(holds.flat, /:model-value="form\.reason \|\| null"/);
    assert.match(holds.flat, /'Pick a reason to continue\.'/);
    assert.match(holds.flat, /action === 'place' \? 'danger' : 'default'/);
    assert.match(
        holds.script,
        /'Place hold on' : 'Release hold on'\} \$\{unbroken\(selected\.value\.batch_number\)\}/,
    );
    assert.match(holds.script, /No stock quantity changes\./);
    assert.match(
        holds.flat,
        /25 per page\. Search includes batches beyond this page\./,
    );
    assert.equal((holds.template.match(/<V2Pager/g) ?? []).length, 1);
});

test("page 7b: Daniel's checklist for Recalls", () => {
    // One filled action, only for those who can start; tabs with counts.
    assert.match(
        recalls.flat,
        /<template v-if="recalls\.can\.start" #actions>/,
    );
    assert.match(
        recalls.flat,
        /<V2Tabs :model-value="recalls\.tab" :tabs="tabs"/,
    );
    // Where it is: counted, patient returns in words, the first few then "Show all N places".
    assert.match(recalls.script, /placesWord = \(n: number\) =>/);
    assert.match(recalls.flat, /\(patient returns\)/);
    assert.match(
        recalls.flat,
        /`Show all \$\{recall\.whereIs\.length\} places`/,
    );
    assert.match(
        recalls.flat,
        /quantityWithUnit\( ?where\.quantity, where\.stockUnit,? ?\)/,
    );
    // Links named with the medicine; own-recall line instead of Lift.
    assert.match(
        recalls.flat,
        /:aria-label="`Write off stock of \$\{recall\.medicine\}`"/,
    );
    assert.match(
        recalls.flat,
        /:aria-label="`Return \$\{recall\.medicine\} to the supplier`"/,
    );
    assert.match(
        recalls.flat,
        /You started this recall, so another person must lift it\./,
    );
    // Start sheet: server SKU search, 44 px batch rows, chips, "Showing", "Already recalled" in words, live sentence.
    assert.match(
        recalls.flat,
        /<InventoryPickerField :model-value="String\(start\.sku\)" kind="skus"/,
    );
    assert.match(recalls.flat, /min-h-11 min-w-11/);
    assert.match(recalls.flat, /aria-label="Ticked batches"/);
    assert.match(recalls.flat, /Showing \{\{ visibleOptions\.length \}\} of/);
    assert.match(recalls.flat, /label="Already recalled"/);
    assert.match(
        recalls.script,
        /`\$\{startSummary\.value\} No stock moves\.`/,
    );
    // Red, named, off until complete with words; reason starts empty; dirty guard and busy.
    assert.match(recalls.flat, /tone="danger"/);
    assert.match(recalls.flat, /`Start recall of \$\{skuWords\}`/);
    assert.match(recalls.script, /'Tick at least one batch to continue\.'/);
    assert.match(recalls.flat, /:model-value="start\.reason \|\| null"/);
    assert.match(recalls.flat, /:dirty="startDirty"/);
    // Lift: summary first, standard filled, footer beside the button.
    assert.match(recalls.flat, /confirm-label="Lift recall"/);
    assert.match(
        recalls.flat,
        /can be used again at every branch, unless a branch hold or expiry stops them\. No stock moves\./,
    );

    // Codes unbroken.
    for (const code of [
        'recall.skuCode',
        'batch.batchNumber',
        'where.batchNumber',
        'option.batchNumber',
    ]) {
        assert.match(
            recalls.flat,
            new RegExp(
                `unbroken\\(\\s*${code.replace('.', '\\.')}\\s*,?\\s*\\)`,
            ),
            code,
        );
    }
});
