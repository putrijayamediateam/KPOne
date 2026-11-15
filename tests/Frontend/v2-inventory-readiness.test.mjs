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
const readinessClassic = read('resources/js/pages/Inventory/Readiness.vue');
const importClassic = read('resources/js/pages/Inventory/OpeningImport.vue');
const readiness = parts('resources/js/pages/Inventory/V2/Readiness.vue');
const opening = parts('resources/js/pages/Inventory/V2/OpeningImport.vue');

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

test('page 9a: the Pilot readiness Classic script is copied verbatim', () => {
    for (const name of [
        'Row',
        'Opening',
        'props',
        'search',
        'busy',
        'barcodeDrafts',
        'barcodeDraft',
        'savedSku',
        'saveBarcode',
        'retryKeys',
        'page',
        'run',
        'openingPage',
        'verify',
    ]) {
        assert.equal(
            block(readiness.script, name),
            block(readinessClassic, name),
            name,
        );
    }

    assert.deepEqual(watches(readiness.script), watches(readinessClassic));
});

test('page 9b: the Opening import Classic script is copied verbatim', () => {
    for (const name of [
        'CheckedRow',
        'props',
        'file',
        'checking',
        'recording',
        'checked',
        'fileError',
        'importKey',
        'csrf',
        'choose',
        'check',
        'ready',
        'confirming',
        'recordCount',
        'askToRecord',
        'record',
    ]) {
        assert.equal(
            block(opening.script, name),
            block(importClassic, name),
            name,
        );
    }

    for (const path of [
        "'/inventory/opening-import/check'",
        "'/inventory/opening-import'",
    ]) {
        assert.ok(
            importClassic.includes(path) && opening.source.includes(path),
            path,
        );
    }
});

test('page 9: registered, V2 blocks, no native select, radio or checkbox; Classic untouched', () => {
    assert.match(registry, /'Inventory\/Readiness',/);
    assert.match(registry, /'Inventory\/OpeningImport',/);

    for (const page of [readiness, opening]) {
        assert.ok(!page.template.includes('<select'));
        assert.ok(!page.template.includes('type="radio"'));
        assert.ok(!page.template.includes('type="checkbox"'));
        assert.match(page.template, /<V2Table/);
        assert.match(page.template, /<V2Dialog/);
        assert.match(page.template, /<template #footer-note>/);
        assert.ok(!page.template.includes('OperationalConfirmDialog'));
    }

    for (const classic of [readinessClassic, importClassic]) {
        assert.ok(!classic.includes('components/v2'));
    }
});

test('page 9a: Verify and Sign off ask first, then call Classic verify unchanged', () => {
    // Buttons open the sheet; only the sheet calls verify.
    assert.match(readiness.flat, /@click="askUnit\(row\)"/);
    assert.match(readiness.flat, /@click="askOpening\(opening\)"/);
    assert.ok(!readiness.template.includes("verify('"));
    assert.match(
        readiness.script,
        /verify\('unit', c\.row\.public_id, c\.row\.stock_unit\)/,
    );
    assert.match(
        readiness.script,
        /verify\('opening', c\.opening\.public_id\)/,
    );
    assert.match(readiness.script, /No stock quantity changes\./);
    // Chips in words; the checker line in place of a missing button.
    assert.match(readiness.script, /'Ambiguous: refused'/);
    assert.match(
        readiness.script,
        /`\$\{row\.signed_count\} of \$\{row\.opening_count\} signed off`/,
    );
    assert.match(
        readiness.flat,
        /A different authorised checker must sign this off\./,
    );
    // The import link only for those who may import; the barcode error is not repeated at the top.
    assert.match(
        readiness.flat,
        /<template v-if="readiness\.canImportOpening" #actions>/,
    );
    assert.match(readiness.script, /key !== 'barcode'/);
    assert.equal((readiness.template.match(/<V2Pager/g) ?? []).length, 1);
});

test('page 9b: numbered steps, a chip per checked row, and the record sheet', () => {
    assert.match(opening.flat, /1\. The file/);
    assert.match(opening.flat, /2\. Check the file/);
    assert.match(opening.flat, /3\. \{\{ checked\.rows\.length \}\}/);
    assert.match(
        opening.flat,
        /\{\{ checking \? 'Checking…' : 'Check file' \}\}/,
    );
    assert.match(opening.flat, /row\.problems\.length \? 'Problem' : 'OK'/);
    assert.match(opening.flat, /@click="askToRecord"/);
    assert.match(opening.flat, /@confirm="record"/);
    assert.match(
        opening.flat,
        /They cannot be edited afterwards\. Each still needs sign-off by someone other than you\./,
    );
    assert.match(opening.flat, /data-testid="opening-import-notice"/);
    assert.match(opening.flat, /data-testid="opening-import-cost-column"/);
});

test("page 9: Daniel's changes, plural unit words and an outline Check file", () => {
    // Unit words come from the SKU and are plural ("40 tablets"), in the sheet, the table and the import rows.
    assert.match(
        readiness.script,
        /quantityWithUnit\(c\.opening\.quantity, sku\?\.stock_unit\)/,
    );
    assert.match(
        readiness.flat,
        /quantityWithUnit\( opening\.quantity, skuFor\(opening\)\?\.stock_unit, \)/,
    );
    assert.match(
        opening.flat,
        /quantityWithUnit\( row\.quantity, row\.stock_unit \?\? 'unit', \)/,
    );
    assert.ok(!readiness.source.includes('trimQuantity'));
    // Check file is outline (never filled) and off with a reason until a file is chosen; Record is the only filled one.
    const checkButton = opening.flat.slice(
        opening.flat.lastIndexOf(
            '<button',
            opening.flat.indexOf('data-testid="opening-import-check"'),
        ),
        opening.flat.indexOf('data-testid="opening-import-check"'),
    );
    assert.match(checkButton, /border border-input bg-background/);
    assert.ok(!checkButton.includes('bg-primary'));
    assert.match(opening.flat, /Choose a file to continue\./);
    assert.match(
        opening.flat,
        /file \? undefined : 'opening-import-check-reason'/,
    );
    assert.equal((opening.template.match(/bg-primary /g) ?? []).length, 1);
});
