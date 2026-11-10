import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-8 (Daniel's note on Q-7): on the consultation page the treatment room error sits right under the treatment
// room controls and is linked to them, instead of floating beside the next toolbar button on a phone. An error from
// "Call again" is not shown there, and choosing a room clears the treatment room error.

const page = readFileSync(
    new URL('../../resources/js/pages/Clinical/Show.vue', import.meta.url),
    'utf8',
);

test('the treatment room error sits inside the treatment room group, after its controls, and they point to it', () => {
    const at = page.indexOf('id="recall-error"');
    assert.ok(at > 0, 'the error has an id');
    const before = page.slice(0, at);
    // The error belongs to the flex-col group that holds the select and the button, in that order.
    const groupStart = before.lastIndexOf('class="flex flex-col gap-1"');
    assert.ok(groupStart > 0, 'inside the flex-col group');
    const inGroup = before.slice(groupStart);
    assert.ok(inGroup.includes('data-testid="call-treatment"'));
    assert.ok(
        inGroup.indexOf('<OperationalSelect') <
            inGroup.indexOf('data-testid="call-treatment"'),
    );
    assert.match(
        page,
        /:describedby="\s*treatmentError \? 'recall-error' : undefined\s*"/,
    );
    assert.match(
        page,
        /:aria-describedby="\s*treatmentError \? 'recall-error' : undefined\s*"/,
    );
    assert.match(page, /:invalid="treatmentError"/);
});

test('a Call again error is not shown under the treatment room controls, and shows once in the toolbar', () => {
    assert.match(
        page,
        /const recallErrorFor = ref<'recall' \| 'treatment'>\('recall'\)/,
    );
    assert.match(page, /recallErrorFor\.value = 'recall';/);
    assert.match(page, /recallErrorFor\.value = 'treatment';/);
    assert.match(page, /recallErrorFor\.value === 'treatment' &&/);
    // The toolbar copy shows only when the treatment room copy does not, so the message is never shown twice.
    assert.match(
        page,
        /<InputError\s+v-if="recallError && !treatmentError"\s+class="max-w-56 text-right"\s+:message="recallError"\s*\/>/,
    );
    assert.equal((page.match(/:message="recallError"/g) ?? []).length, 2);
});

test('choosing a treatment room clears a treatment room error', () => {
    assert.match(
        page,
        /watch\(treatmentRoomId, \(\) => \{\s*if \(recallErrorFor\.value === 'treatment'\) \{\s*recallError\.value = '';\s*\}\s*\}\);/,
    );
});
