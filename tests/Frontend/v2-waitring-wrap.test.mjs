import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const s = readFileSync(
    new URL('../../resources/js/components/v2/WaitRing.vue', import.meta.url),
    'utf8',
);

test('WaitRing: the ring is a fixed-size item and the words wrap beside it', () => {
    assert.match(
        s,
        /relative inline-flex size-9 shrink-0 items-center justify-center/,
    );
    assert.match(s, /min-w-0 text-\[13px\]/);
    assert.match(s, /inline-flex max-w-full items-center gap-1\.5/);
    assert.doesNotMatch(s, /inline-flex shrink-0 items-center gap-1\.5/);
});
