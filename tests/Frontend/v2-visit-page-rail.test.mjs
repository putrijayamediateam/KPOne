import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
    new URL(
        '../../resources/js/pages/Registration/V2/Show.vue',
        import.meta.url,
    ),
    'utf8',
);
const loose = (text) => text.replace(/\s+/g, ' ');

test('visit page V2 moves the rail to xl and stacks it below the main column', () => {
    const s = loose(source);
    assert.match(s, /rail-from="xl"/);
    assert.match(s, /rail-below="stack-bottom"/);
});

test('visit page V2 keeps status chips and its actions in the page body', () => {
    const s = loose(source);
    assert.match(
        s,
        /<V2Chip :tone="statusChip\.tone" :label="statusChip\.label" \/>/,
    );
    assert.match(s, /Send to waiting/);
    assert.match(s, /Open queue/);
});

test('visit page V2 does not change its requests', () => {
    assert.match(source, /sendToWaiting/);
    assert.doesNotMatch(source, /rail-from="lg"/);
});
