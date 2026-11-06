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
