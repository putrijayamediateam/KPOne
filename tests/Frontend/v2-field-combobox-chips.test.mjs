import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const v2 = (n) => read(`resources/js/components/v2/${n}.vue`);

test('V2Field: a visible label tied to the control, a hint, and an announced error', () => {
    const s = v2('V2Field');
    assert.match(s, /<label :for="id"/);
    assert.match(s, /described-by/);
    assert.match(s, /role="alert"/);
    assert.match(s, /v-if="required"[\s\S]*aria-hidden="true"/);
});

test('The new blocks import nothing from the Classic ui components', () => {
    for (const n of ['V2Field']) {
        assert.doesNotMatch(v2(n), /from '@\/components\/ui\//);
    }
});
