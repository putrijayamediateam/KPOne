import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workspace = readFileSync(
    new URL(
        '../../resources/js/components/billing/BillingWorkspace.vue',
        import.meta.url,
    ),
    'utf8',
);

test('DD-29: below 1024 px every Billing button is 44 px, as on the Dispensary pages', () => {
    assert.match(
        workspace,
        /@media \(max-width: 1023\.98px\) \{\s*\.billing-entry :deep\(button\),\s*\.billing-workspace :deep\(button\),\s*\.billing-workspace :deep\(a\[data-slot='button'\]\) \{\s*height: auto;\s*min-height: 44px;/,
    );
    // The compact height stays for wide screens.
    assert.match(workspace, /min-height: 28px;\s*height: 30px;/);
});
