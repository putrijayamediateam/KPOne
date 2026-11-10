import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-8 (Daniel's note on Q-7): on the consultation page the call error sits right under the treatment room
// controls and is linked to them, instead of floating beside the next toolbar button on a phone.

const page = readFileSync(
    new URL('../../resources/js/pages/Clinical/Show.vue', import.meta.url),
    'utf8',
);

test('the call error sits under the treatment room controls and the controls point to it', () => {
    const group = page.match(
        /<div\s+v-if="\s*clinical\.queue\.canRecall &&\s*\(treatmentRooms\?\.length \?\? 0\) > 0\s*"\s+class="flex flex-col gap-1"\s*>([\s\S]*?)<TreatmentProgressActions/,
    );
    assert.ok(group, 'treatment room group found');
    const body = group[1];
    // The row with the select and the button comes first, then the error inside the same group.
    assert.ok(
        body.indexOf('data-testid="call-treatment"') <
            body.indexOf('id="recall-error"'),
    );
    assert.match(body, /<InputError\s+v-if="recallError"\s+id="recall-error"/);
    assert.match(
        body,
        /:describedby="\s*recallError \? 'recall-error' : undefined\s*"/,
    );
    assert.match(
        body,
        /:aria-describedby="\s*recallError \? 'recall-error' : undefined\s*"/,
    );
    assert.match(body, /:invalid="recallError !== ''"/);
});

test('without treatment rooms the error still shows, in the toolbar as before', () => {
    assert.match(
        page,
        /<InputError\s+v-else-if="recallError"\s+class="max-w-56 text-right"\s+:message="recallError"\s*\/>/,
    );
    // Only one live error for the call buttons: no second copy of the message anywhere.
    assert.equal((page.match(/:message="recallError"/g) ?? []).length, 2);
});
