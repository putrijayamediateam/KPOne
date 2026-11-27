import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DSP-29 part A: whoever cannot change the line sees the CA's allergy match read only on the case card.
const show = readFileSync(
    new URL('../../resources/js/pages/Dispensary/Show.vue', import.meta.url),
    'utf8',
);

test('the case card shows the read-only mark when the warning with its tick box is not shown', () => {
    // The mark directly follows the card's CA warning (only a comment between), so it is that warning's else branch.
    assert.match(
        show,
        /<AllergyMatchWarning\s+v-if="\s*dispensary\.can\.update && matchesFor\(item\)\.length\s*"[^>]*\/>\s*(?:<!--[\s\S]*?-->\s*)?<AllergyMatchMark\s+v-else-if="matchesFor\(item\)\.length"/,
    );
    assert.match(show, /:medicine="item\.name"/);
    assert.equal((show.match(/<AllergyMatchMark/g) ?? []).length, 1);
});
