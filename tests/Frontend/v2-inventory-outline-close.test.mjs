import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('Inventory V2: a sheet whose only button closes it uses the outline Close (Daniel, V2Dialog singleActionStyle)', () => {
    for (const [file, label] of [
        // Done only closes: each difference is resolved by its own button inside the sheet.
        [
            'resources/js/components/inventory-v2/TransferDifferencesSheet.vue',
            'Done',
        ],
        // View lines (read only).
        ['resources/js/components/inventory-v2/RequestsSection.vue', 'Close'],
        // Page 3 evidence history (read only).
        ['resources/js/pages/Inventory/V2/GovernedStock.vue', 'Close'],
    ]) {
        const source = read(file);
        assert.match(
            source,
            new RegExp(
                `confirm-label="${label}"\\s+single-action\\s+single-action-style="outline"`,
            ),
            file,
        );
        // Exactly one single-action sheet per file, and it is the outline one.
        assert.equal(
            (source.match(/\bsingle-action\b(?!-)/g) ?? []).length,
            1,
            file,
        );
    }
});
