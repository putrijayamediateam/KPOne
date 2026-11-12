import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UI-RESTYLE: one small Panel-local map from a claim status to a badge tone. Words come from the server.

const read = (path) =>
    readFileSync(
        new URL(`../../resources/js/${path}`, import.meta.url),
        'utf8',
    );

const helper = read('lib/panel-claim-status.ts');
const invoices = read('pages/PanelInvoices/Index.vue');

test('The tone map gives each claim status its tone and keeps the rest neutral', () => {
    for (const [status, tone] of [
        ['accepted', 'success'],
        ['paid', 'success'],
        ['partially_accepted', 'warning'],
        ['partially_paid', 'warning'],
        ['queried', 'warning'],
        ['rejected', 'danger'],
    ]) {
        assert.match(helper, new RegExp(`${status}: '${tone}'`));
    }

    assert.match(helper, /\?\? 'neutral'/);
    assert.match(helper, /toLowerCase\(\)\.replaceAll\(' ', '_'\)/);
});

test('Panel invoices shows the server word and takes only the tone from the helper', () => {
    assert.match(
        invoices,
        /import \{ panelClaimTone \} from '@\/lib\/panel-claim-status';/,
    );
    assert.equal(
        invoices.match(/:tone="panelClaimTone\(row\.claimStatus\)"/g)?.length,
        2,
    );
    assert.equal(invoices.match(/:label="row\.claimStatus"/g)?.length, 2);
});
