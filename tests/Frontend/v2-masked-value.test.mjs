import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const s = readFileSync('resources/js/components/v2/V2MaskedValue.vue', 'utf8');

test('V2MaskedValue: holds no value, never in title or attribute, named buttons', () => {
    assert.match(s, /reveal: \(\) => Promise<string>/);
    assert.doesNotMatch(s, /:title=|:data-value|:value=/);
    assert.match(s, /`\$\{value !== null \? 'Hide' : 'Show'\} \$\{label\}`/);
    assert.doesNotMatch(s, /components\/ui/);
});

test('V2MaskedValue: failure shows an error and nothing; hides after 60 s, on navigation, on leave; stale requests dropped', () => {
    assert.match(s, /hideAfterMs: 60000/);
    assert.match(s, /Nothing was shown/);
    assert.match(
        s,
        /value\.value = null;\s+busy\.value = false;\s+failed\.value = true/,
    );
    assert.match(s, /inertia:start/);
    assert.match(s, /onBeforeUnmount/);
    assert.match(s, /mine !== ticket/);
    assert.match(s, /status\.value = 'Shown'/);
    assert.match(s, /status\.value = 'Hidden'/);
});
