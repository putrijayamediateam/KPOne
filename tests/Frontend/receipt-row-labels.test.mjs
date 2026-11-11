import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const panel = readFileSync(
    new URL(
        '../../resources/js/components/inventory/InventoryOperationsPanel.vue',
        import.meta.url,
    ),
    'utf8',
);

// DD-12 follow-up from the STK-10B design review: the Post receipt row shows a visible label on every field,
// so nothing relies on placeholder text that is cut off at 1440 px.
test('every Post receipt field has a visible label and keeps its accessible name', () => {
    for (const [label, name] of [
        ['Batch number', 'received batch number'],
        ['Expiry date', 'received batch expiry'],
        [
            'Quantity this receipt \\(\\{\\{ line\\.stockUnit \\}\\}\\)',
            'receipt quantity',
        ],
    ]) {
        assert.match(
            panel,
            new RegExp(
                `<label class="grid min-w-0 gap-1 text-xs"\\s+>${label}\\s+<\\w+[\\s\\S]{0,160}?:aria-label="\`\\$\\{line\\.sku\\} ${name}\`"`,
            ),
            label,
        );
    }

    assert.doesNotMatch(panel, /placeholder="Batch number"/);
    assert.doesNotMatch(panel, /placeholder="Quantity this receipt"/);
    assert.match(panel, /sm:grid-cols-2 lg:grid-cols-4/);
});
