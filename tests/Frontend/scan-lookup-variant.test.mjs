import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const scan = readFileSync(
    new URL(
        '../../resources/js/components/inventory/ScanInput.vue',
        import.meta.url,
    ),
    'utf8',
);

// Daniel: a page with a solid main action beside the scanner can show "Look up" as outline. The default stays solid.
test('ScanInput: Look up takes an optional outline variant, solid by default', () => {
    assert.match(scan, /lookupVariant\?: 'default' \| 'outline';/);
    assert.match(scan, /:variant="lookupVariant \?\? 'default'"/);
});
