import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DSP-29 (coordinator OK, 27 Nov 2026): the doctor sees the CA's possible-allergy-match warning, read only.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const mark = read('resources/js/components/dispensary/AllergyMatchMark.vue');
const panel = read(
    'resources/js/pages/Clinical/Partials/DispensaryCheckPanel.vue',
);
const types = read('resources/js/types/clinical.ts');

test('the mark is read only, worded like the CA warning and never claims a check', () => {
    assert.match(mark, /Possible allergy match/);
    assert.match(mark, /This medicine may match the patient's recorded/);
    assert.match(mark, /role="note"/);
    assert.match(mark, /aria-hidden="true"/);
    assert.equal(/type="checkbox"|defineModel|checked with/i.test(mark), false);
    assert.equal(/role="alert"/.test(mark), false);
    // Shown only when there is a name to show; duplicates and blanks dropped.
    assert.match(mark, /v-if="names\.length"/);
    assert.match(mark, /new Set\(/);
});

test('the check panel shows the mark before the quantities', () => {
    const at = panel.indexOf('<AllergyMatchMark');
    assert.ok(at > 0);
    assert.ok(
        at < panel.indexOf('<dt class="text-muted-foreground">Ordered</dt>'),
    );
    assert.match(panel, /:allergens="line\.allergyMatches \?\? \[\]"/);
    assert.match(types, /allergyMatches\?: string\[\];/);
});
