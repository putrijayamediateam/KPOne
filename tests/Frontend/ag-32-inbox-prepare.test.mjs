import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const v2 = read('resources/js/pages/Agents/V2/Inbox.vue');
const classic = read('resources/js/pages/Agents/Inbox.vue');

for (const [name, page] of [
    ['V2', v2],
    ['Classic', classic],
]) {
    test(`${name} inbox: approve can also create the stock request or purchase request, only when the server offers it`, () => {
        // The choice is rendered only for a proposal that carries a `prepare` offer, and nothing is sent without it.
        assert.match(page, /prepare-choice/);
        assert.match(page, /Approve \(decision only\)/);
        assert.match(page, /actionLabel/);
        assert.match(page, /extra\.mode = mode\.value/);
        assert.match(page, /extra\.edits = \{/);
        assert.match(page, /source_location_public_id: editSource\.value/);
        assert.match(
            page,
            /destination_location_public_id: editDestination\.value/,
        );
        // The existing decision payload stays as it was, with the new keys added after it.
        assert.match(page, /lock_version: current\.proposal\.lockVersion/);
        assert.match(page, /\.\.\.extra/);
    });

    test(`${name} inbox: the quantity can only go down and a refusal from the domain is shown`, () => {
        assert.match(
            page,
            /wanted > Number\(offer\.value\.edits\.maxQuantity\)/,
        );
        assert.match(page, /You can only lower it\./);
        assert.match(page, /errors\.mode/);
        assert.match(page, /errors\.quantity/);
        assert.match(page, /errors\.edits/);
    });

    test(`${name} inbox: a person who cannot prepare is told why, a warning shows, and the created record is named`, () => {
        assert.match(page, /offer\.cannotPrepareReason/);
        assert.match(page, /offer\.warning/);
        assert.match(page, /proposal\.prepared\.text/);
    });

    test(`${name} inbox: the decision-only notice stays as it was while nothing can prepare`, () => {
        assert.match(page, /v-if="!anyPrepare"/);
        assert.match(page, /Approving (only )?records your/);
        assert.match(page, /Nothing is created\s+unless you choose that\./);
    });
}

test('V2 inbox: the choice uses full-width option rows and the shared pickers, and the confirm button names the action', () => {
    assert.match(v2, /data-test="mode-options"/);
    assert.match(v2, /<V2Picker/);
    assert.match(v2, /label="Sending location"/);
    assert.match(v2, /label="Receiving location"/);
    assert.match(v2, /offer\?\.actionLabel \?\? 'Approve'/);
});

test('V2 inbox: the unavailable choice stays readable, says so, and has its reason right under it', () => {
    assert.match(v2, /aria-disabled="!option\.available \|\| undefined"/);
    assert.match(v2, /Not available/);
    assert.match(v2, /id="cannot-prepare-reason"/);
    assert.match(v2, /aria-describedby/);
    // No pale disabled button: the unavailable row is not opacity-faded.
    assert.doesNotMatch(
        v2,
        /disabled:opacity-50[^"]*"\s*:class="\s*!option\.available/,
    );
    // The choice cannot be selected while unavailable.
    assert.match(
        v2,
        /if \(value === 'prepare' && !offer\.value\?\.canPrepare\)/,
    );
});

test('V2 inbox: the choices are full-width rows with a tick as well as colour', () => {
    assert.match(v2, /flex min-h-11 w-full min-w-0 items-center gap-3/);
    assert.match(v2, /<Check/);
    assert.match(v2, /:aria-pressed="option\.value === mode"/);
});

test('V2 inbox: the quantity hint carries the unit', () => {
    assert.match(v2, /unitPlural/);
    assert.match(v2, /lineValue\(deciding\.value\.proposal, 'Quantity'\)/);
});

test('inbox: the Approved tab does not say nothing was created when requests were created', () => {
    for (const page of [v2, classic]) {
        assert.match(page, /row\.prepared (\?\? null\) )?!==? null/);
    }

    assert.match(v2, /anyWithoutRecord/);
    assert.match(v2, /approved-act-note/);
    // The original wording is kept when the switch is off.
    assert.match(v2, /Approving only records your/);
    assert.match(
        v2,
        /Act on an approved suggestion from the Inventory screens\./,
    );
});
