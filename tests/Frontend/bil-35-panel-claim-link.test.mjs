import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-35: a Panel invoice links straight to its Panel claim, or Create claim,
// for Panel Claims users only. The server decides; the page only shows it.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );

test('BIL-35: Patient Billing shows the server link as an outline button (not on Manage payments)', () => {
    assert.match(
        read('resources/js/components/billing/BillingWorkspace.vue'),
        /<ActionLink v-if="!manage && billing\.panelClaim" :href="billing\.panelClaim\.href" variant="outline" data-testid="panel-claim-link" >\{\{ billing\.panelClaim\.label \}\}<\/ActionLink >/,
    );
});

test('BIL-35: the completed Invoice shows the server link as an outline button', () => {
    assert.match(
        read('resources/js/pages/Billing/Invoice.vue'),
        /<ActionLink v-if="invoice\.links\.panelClaim" :href="invoice\.links\.panelClaim\.href" variant="outline" data-testid="panel-claim-link" >\{\{ invoice\.links\.panelClaim\.label \}\}<\/ActionLink >/,
    );
});

test('BIL-35: one shared link type', () => {
    const types = read('resources/js/types/billing.ts');
    assert.match(
        types,
        /export type PanelClaimLink = \{ kind: 'open' \| 'create'; label: string; href: string; claimNumber: string \| null; \};/,
    );
    assert.match(
        read('resources/js/types/completedInvoice.ts'),
        /panelClaim\?: PanelClaimLink \| null;/,
    );
});
