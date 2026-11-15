import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-16: a claim below its invoice total prints the remaining balance under the
// Panel share, without saying who pays it.

const pack = readFileSync(
    new URL('../../resources/js/pages/PanelClaims/Pack.vue', import.meta.url),
    'utf8',
);

test('The balance row follows the bold Panel share line and is only shown when above zero', () => {
    assert.match(
        pack,
        /<div class="pack-totals-final">\s*<dt>Amount claimed \(Panel share\)<\/dt>\s*<dd>\{\{ myr\(claim\.claimAmountSen\) \}\}<\/dd>\s*<\/div>\s*<!--[^>]*-->\s*<div\s+v-if="unclaimedSen\(claim\) > 0"\s+data-testid="pack-unclaimed-balance"\s*>\s*<dt>Balance not claimed from the Panel<\/dt>\s*<dd>\{\{ myr\(unclaimedSen\(claim\)\) \}\}<\/dd>/,
    );
    assert.match(
        pack,
        /const unclaimedSen = \(claim: \{\s*invoiceTotalSen: number;\s*claimAmountSen: number;?\s*\}\) =>\s*Math\.max\(0, claim\.invoiceTotalSen - claim\.claimAmountSen\);/,
    );
    assert.doesNotMatch(pack, /Patient pays|Patient share/);
});

test('The balance is the unclaimed rest, and zero for a full claim or a claim above the total', () => {
    const body = pack.match(
        /const unclaimedSen = \(claim: \{[^}]*\}\) =>\s*(Math\.max\([^;]*\));/,
    );
    assert.ok(body, 'unclaimedSen is defined');
    const unclaimedSen = new Function('claim', `return ${body[1]};`);

    assert.equal(
        unclaimedSen({ invoiceTotalSen: 4000, claimAmountSen: 2500 }),
        1500,
    );
    assert.equal(
        unclaimedSen({ invoiceTotalSen: 4000, claimAmountSen: 4000 }),
        0,
    );
    assert.equal(
        unclaimedSen({ invoiceTotalSen: 4000, claimAmountSen: 4500 }),
        0,
    );
});
