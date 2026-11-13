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

test('V2MaskedValue round 2: spoken mask, pending ignores a second press, hide line, hides before print', () => {
    assert.match(s, /maskedSpoken\?: string/);
    assert.match(s, /aria-hidden="true">\{\{ masked \}\}/);
    assert.match(s, /if \(busy\.value\) \{\s+return;/);
    assert.match(s, /Hides in/);
    assert.match(s, /addEventListener\('beforeprint', onBeforePrint\)/);
    assert.match(s, /removeEventListener\('beforeprint', onBeforePrint\)/);
    assert.match(s, /print:hidden/);
    assert.match(s, /print:inline/);
});
